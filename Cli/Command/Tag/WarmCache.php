<?php

namespace WindowsForum\SessionValidator\Cli\Command\Tag;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use XF\Cli\Command\AbstractCommand;
use XF\Repository\TagRepository;

class WarmCache extends AbstractCommand
{
    protected function configure()
    {
        $this
            ->setName('wf:tag-cache-warm')
            ->setDescription('Warm guest tag result caches and optional tag page-cache entries')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum tags to inspect', 50)
            ->addOption('refresh-window', null, InputOption::VALUE_REQUIRED, 'Refresh caches expiring within this many seconds', 900)
            ->addOption('pages', null, InputOption::VALUE_REQUIRED, 'Maximum pages to warm per tag when --warm-pages is used', 10)
            ->addOption('min-use-count', null, InputOption::VALUE_REQUIRED, 'Minimum xf_tag.use_count to consider', 1)
            ->addOption('tag-url', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Specific tag_url to warm')
            ->addOption('warm-pages', null, InputOption::VALUE_NONE, 'Fetch canonical guest tag page URLs after refreshing the result cache')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Base URL for page warming; defaults to boardUrl')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'HTTP timeout in seconds for page warming', 4)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report work without saving tag result caches or fetching pages')
            ->addOption('no-cluster-lock', null, InputOption::VALUE_NONE, 'Do not use Redis cluster-wide per-tag refresh locks');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $limit = max(1, min(500, (int)$input->getOption('limit')));
        $refreshWindow = max(0, min(7200, (int)$input->getOption('refresh-window')));
        $pages = max(1, min(25, (int)$input->getOption('pages')));
        $minUseCount = max(0, (int)$input->getOption('min-use-count'));
        $warmPages = (bool)$input->getOption('warm-pages');
        $dryRun = (bool)$input->getOption('dry-run');
        $useClusterLock = !(bool)$input->getOption('no-cluster-lock');
        $timeout = max(1, min(15, (int)$input->getOption('timeout')));

        $originalVisitor = \XF::visitor();
        \XF::setVisitor(\XF::repository('XF:User')->getVisitor(0));

        $redis = $useClusterLock ? $this->connectRedis() : null;
        if ($useClusterLock && !$redis)
        {
            $output->writeln('<comment>Redis unavailable; continuing without cluster locks.</comment>');
        }

        $stats = [
            'tags' => 0,
            'refreshed' => 0,
            'fresh' => 0,
            'empty' => 0,
            'locked' => 0,
            'page_warmed' => 0,
            'page_failed' => 0,
            'ai_summary_generated' => 0,
            'ai_summary_fresh' => 0,
            'ai_summary_failed' => 0,
        ];

        try
        {
            $tags = $this->getTags($input, $limit, $minUseCount, $refreshWindow);
            foreach ($tags AS $tag)
            {
                $stats['tags']++;
                $lockToken = '';
                if ($redis)
                {
                    $lockToken = $this->acquireTagLock($redis, (int)$tag['tag_id']);
                    if ($lockToken === '')
                    {
                        $stats['locked']++;
                        continue;
                    }
                }

                try
                {
                    $result = $this->refreshTagCache($tag, $refreshWindow, $dryRun);
                    $stats[$result['status']]++;

                    if ($warmPages && $result['results'] > 0 && !$dryRun)
                    {
                        $aiStatus = $this->warmAiTagSummary($tag);
                        if ($aiStatus === 'generated')
                        {
                            $stats['ai_summary_generated']++;
                        }
                        else if ($aiStatus === 'fresh')
                        {
                            $stats['ai_summary_fresh']++;
                        }
                        else if ($aiStatus === 'failed')
                        {
                            $stats['ai_summary_failed']++;
                        }

                        $pageStats = $this->warmTagPages($tag, $result['results'], $pages, $input, $timeout);
                        $stats['page_warmed'] += $pageStats['warmed'];
                        $stats['page_failed'] += $pageStats['failed'];
                    }

                    $output->writeln(sprintf(
                        'tag_id=%d tag_url=%s status=%s results=%d expiry=%s',
                        $tag['tag_id'],
                        $tag['tag_url'],
                        $result['status'],
                        $result['results'],
                        $result['expiry_date'] ? date('c', $result['expiry_date']) : 'none'
                    ));
                }
                finally
                {
                    if ($redis && $lockToken !== '')
                    {
                        $this->releaseLock($redis, $this->tagLockKey((int)$tag['tag_id']), $lockToken);
                    }
                }
            }
        }
        finally
        {
            \XF::setVisitor($originalVisitor);
        }

        $this->recordStats($redis, $stats);

        $output->writeln(sprintf(
            'Done. tags=%d refreshed=%d fresh=%d empty=%d locked=%d page_warmed=%d page_failed=%d ai_generated=%d ai_fresh=%d ai_failed=%d%s',
            $stats['tags'],
            $stats['refreshed'],
            $stats['fresh'],
            $stats['empty'],
            $stats['locked'],
            $stats['page_warmed'],
            $stats['page_failed'],
            $stats['ai_summary_generated'],
            $stats['ai_summary_fresh'],
            $stats['ai_summary_failed'],
            $dryRun ? ' [DRY RUN]' : ''
        ));

        return 0;
    }

    protected function getTags(InputInterface $input, int $limit, int $minUseCount, int $refreshWindow): array
    {
        $tagUrls = array_filter(array_map('trim', (array)$input->getOption('tag-url')));
        if ($tagUrls)
        {
            return \XF::db()->fetchAll(
                'SELECT tag_id, tag, tag_url, use_count
                    FROM xf_tag
                    WHERE tag_url IN (' . \XF::db()->quote($tagUrls) . ')
                    ORDER BY use_count DESC
                    LIMIT ?',
                $limit
            );
        }

        $now = \XF::$time;
        $refreshBefore = $now + $refreshWindow;

        return \XF::db()->fetchAll(
            'SELECT t.tag_id, t.tag, t.tag_url, t.use_count
                FROM xf_tag AS t
                LEFT JOIN xf_tag_result_cache AS trc
                    ON (trc.tag_id = t.tag_id AND trc.user_id = 0)
                WHERE t.use_count >= ?
                    AND (
                        trc.result_cache_id IS NULL
                        OR trc.expiry_date <= ?
                    )
                ORDER BY
                    CASE WHEN trc.result_cache_id IS NULL THEN 0 ELSE 1 END,
                    t.use_count DESC
                LIMIT ?',
            [$minUseCount, $refreshBefore, $limit]
        );
    }

    protected function refreshTagCache(array $tagRow, int $refreshWindow, bool $dryRun): array
    {
        /** @var TagRepository $tagRepo */
        $tagRepo = \XF::repository(TagRepository::class);
        $cache = $tagRepo->getTagResultCache((int)$tagRow['tag_id'], 0);

        if (!$cache->requiresRefetch() && (int)$cache->expiry_date > \XF::$time + $refreshWindow)
        {
            return [
                'status' => 'fresh',
                'results' => is_array($cache->results) ? count($cache->results) : 0,
                'expiry_date' => (int)$cache->expiry_date,
            ];
        }

        $limit = (int)\XF::options()->maximumSearchResults;
        $tagResults = $tagRepo->getTagSearchResults((int)$tagRow['tag_id'], max(1, $limit));
        $resultSet = $tagRepo->getTagResultSet($tagResults)->limitToViewableResults();
        $results = $resultSet->getResults();

        if (!$results)
        {
            return [
                'status' => 'empty',
                'results' => 0,
                'expiry_date' => (int)$cache->expiry_date,
            ];
        }

        if (!$dryRun)
        {
            $cache->results = $results;
            $cache->save();
        }

        return [
            'status' => 'refreshed',
            'results' => count($results),
            'expiry_date' => $dryRun ? \XF::$time + 3600 : (int)$cache->expiry_date,
        ];
    }

    protected function warmAiTagSummary(array $tagRow): string
    {
        try
        {
            if (empty(\XF::options()->xfaiEnabled) || empty(\XF::options()->xfaiTagSummariesEnabled))
            {
                return 'skipped';
            }

            $tag = \XF::em()->find('XF:Tag', (int)$tagRow['tag_id']);
            if (!$tag || !class_exists('XFAI\Service\TagSummary'))
            {
                return 'skipped';
            }

            /** @var \XFAI\Service\TagSummary $service */
            $service = \XF::service('XFAI:TagSummary');
            $result = $service->generateForTag($tag);
            return $result['status'] ?? 'failed';
        }
        catch (\Exception $e)
        {
            \XF::logException($e, false, 'WF tag cache warmer AI summary failed: ');
            return 'failed';
        }
    }

    protected function warmTagPages(array $tagRow, int $results, int $pages, InputInterface $input, int $timeout): array
    {
        $perPage = max(1, (int)\XF::options()->searchResultsPerPage);
        $pageCount = min($pages, max(1, (int)ceil($results / $perPage)));
        $baseUrl = rtrim((string)$input->getOption('base-url'), '/');
        if ($baseUrl === '')
        {
            $baseUrl = rtrim((string)\XF::options()->boardUrl, '/');
        }

        $stats = ['warmed' => 0, 'failed' => 0];
        for ($page = 1; $page <= $pageCount; $page++)
        {
            $path = '/tags/' . rawurlencode((string)$tagRow['tag_url']) . '/';
            if ($page > 1)
            {
                $path .= 'page-' . $page;
            }

            if ($this->fetchPage($baseUrl . $path, $timeout))
            {
                $stats['warmed']++;
            }
            else
            {
                $stats['failed']++;
            }
        }

        return $stats;
    }

    protected function fetchPage(string $url, int $timeout): bool
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => "User-Agent: WindowsForumTagCacheWarmer/1.0\r\nAccept: text/html\r\n",
            ],
        ]);

        $stream = @fopen($url, 'rb', false, $context);
        if (!$stream)
        {
            return false;
        }

        stream_get_contents($stream, 1024);
        $meta = stream_get_meta_data($stream);
        fclose($stream);

        $headers = isset($meta['wrapper_data']) && is_array($meta['wrapper_data']) ? $meta['wrapper_data'] : [];
        foreach ($headers AS $header)
        {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match))
            {
                $code = (int)$match[1];
                return $code >= 200 && $code < 400;
            }
        }

        return true;
    }

    protected function connectRedis()
    {
        if (!extension_loaded('redis'))
        {
            return null;
        }

        $cacheConfig = \XF::config('cache')['config'] ?? [];
        $host = $cacheConfig['host'] ?? '127.0.0.1';
        $port = (int)($cacheConfig['port'] ?? 6379);
        $timeout = !empty($cacheConfig['timeout']) ? (float)$cacheConfig['timeout'] : 0.5;
        $database = (int)($cacheConfig['database'] ?? 0);

        try
        {
            $redis = new \Redis();
            $redis->pconnect($host, $port, $timeout, 'wf_tag_cache_warm_' . $database);
            if (!empty($cacheConfig['password']))
            {
                $redis->auth($cacheConfig['password']);
            }
            $redis->select($database);
            return $redis;
        }
        catch (\Throwable $e)
        {
            return null;
        }
    }

    protected function acquireTagLock(\Redis $redis, int $tagId): string
    {
        $token = bin2hex(random_bytes(12));
        return $redis->set($this->tagLockKey($tagId), $token, ['nx', 'ex' => 120]) ? $token : '';
    }

    protected function tagLockKey(int $tagId): string
    {
        return 'wf:tag_cache:refresh_lock:' . $tagId . ':guest';
    }

    protected function releaseLock(\Redis $redis, string $key, string $token): void
    {
        $script = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('DEL', KEYS[1])
end
return 0
LUA;

        $redis->eval($script, [$key, $token], 1);
    }

    protected function recordStats($redis, array $stats): void
    {
        if (!$redis)
        {
            return;
        }

        try
        {
            foreach ($stats AS $key => $value)
            {
                $redis->hIncrBy('wf:tag_cache:warm_stats', $key, (int)$value);
            }
            $redis->expire('wf:tag_cache:warm_stats', 604800);
        }
        catch (\Throwable $e)
        {
        }
    }
}
