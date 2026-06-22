<?php

namespace WindowsForum\SessionValidator\Cli\Command\ImageProxy;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use XF\Cli\Command\AbstractCommand;

class FlushStats extends AbstractCommand
{
    protected function configure()
    {
        $this
            ->setName('wf:image-proxy-flush-stats')
            ->setDescription('Flush async image proxy stats')
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum image IDs to flush in this run',
                500
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Read pending aggregates without writing to MySQL'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $limit = max(1, min(5000, (int)$input->getOption('limit')));
        $dryRun = (bool)$input->getOption('dry-run');

        $redis = $this->connectRedis();
        if (!$redis)
        {
            $output->writeln('<error>Unable to connect to Redis.</error>');
            return 1;
        }

        $lockToken = bin2hex(random_bytes(12));
        if (!$redis->set('wf:image_proxy:flush_lock', $lockToken, ['nx', 'ex' => 55]))
        {
            $output->writeln('<comment>Another image proxy stats flush is already running.</comment>');
            return 0;
        }

        $processed = 0;
        $views = 0;
        $referrers = 0;

        try
        {
            $imageIds = $this->getPendingImageIds($redis, $limit);
            foreach ($imageIds AS $imageId)
            {
                $batch = $this->popImageBatch($redis, $imageId);
                if (!$batch['views'] && !$batch['referrers'])
                {
                    continue;
                }

                if ($dryRun)
                {
                    $this->requeueBatch($redis, $imageId, $batch);
                    $processed++;
                    $views += $batch['views'];
                    $referrers += count($batch['referrers']);
                    continue;
                }

                try
                {
                    $this->flushImageBatch($imageId, $batch);
                    $processed++;
                    $views += $batch['views'];
                    $referrers += count($batch['referrers']);
                }
                catch (\Throwable $e)
                {
                    $this->requeueBatch($redis, $imageId, $batch);
                    throw $e;
                }
            }
        }
        finally
        {
            $this->releaseLock($redis, $lockToken);
        }

        $output->writeln(sprintf(
            'Flushed %d image IDs, %d views, %d referrer rows.',
            $processed,
            $views,
            $referrers
        ));

        return 0;
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
            $redis->pconnect($host, $port, $timeout, 'wf_image_proxy_flush_' . $database);
            $redis->select($database);
            return $redis;
        }
        catch (\Throwable $e)
        {
            return null;
        }
    }

    protected function getPendingImageIds(\Redis $redis, int $limit): array
    {
        $ids = [];

        foreach ((array)$redis->hKeys('wf:image_proxy:views') AS $imageId)
        {
            $imageId = (int)$imageId;
            if ($imageId > 0)
            {
                $ids[$imageId] = true;
            }
            if (count($ids) >= $limit)
            {
                return array_keys($ids);
            }
        }

        foreach ((array)$redis->sMembers('wf:image_proxy:ref_images') AS $imageId)
        {
            $imageId = (int)$imageId;
            if ($imageId > 0)
            {
                $ids[$imageId] = true;
            }
            if (count($ids) >= $limit)
            {
                break;
            }
        }

        return array_keys($ids);
    }

    protected function popImageBatch(\Redis $redis, int $imageId): array
    {
        $script = <<<'LUA'
local imageId = ARGV[1]
local viewCount = redis.call('HGET', KEYS[1], imageId)
local lastDate = redis.call('HGET', KEYS[2], imageId)
if viewCount then redis.call('HDEL', KEYS[1], imageId) end
if lastDate then redis.call('HDEL', KEYS[2], imageId) end

local refHitsKey = KEYS[3] .. imageId
local refUrlKey = KEYS[4] .. imageId
local refFirstKey = KEYS[5] .. imageId
local refLastKey = KEYS[6] .. imageId
local refHits = redis.call('HGETALL', refHitsKey)
local refUrls = redis.call('HGETALL', refUrlKey)
local refFirst = redis.call('HGETALL', refFirstKey)
local refLast = redis.call('HGETALL', refLastKey)
redis.call('DEL', refHitsKey, refUrlKey, refFirstKey, refLastKey)
redis.call('SREM', KEYS[7], imageId)

return {viewCount or '', lastDate or '', refHits, refUrls, refFirst, refLast}
LUA;

        $result = $redis->eval($script, [
            'wf:image_proxy:views',
            'wf:image_proxy:last',
            'wf:image_proxy:ref_hits:',
            'wf:image_proxy:ref_url:',
            'wf:image_proxy:ref_first:',
            'wf:image_proxy:ref_last:',
            'wf:image_proxy:ref_images',
            (string)$imageId,
        ], 7);

        $views = max(0, (int)($result[0] ?? 0));
        $lastDate = max(0, (int)($result[1] ?? 0));

        return [
            'views' => $views,
            'last_date' => $lastDate,
            'referrers' => $this->combineReferrers(
                $result[2] ?? [],
                $result[3] ?? [],
                $result[4] ?? [],
                $result[5] ?? []
            ),
        ];
    }

    protected function combineReferrers(array $hitsRaw, array $urlsRaw, array $firstRaw, array $lastRaw): array
    {
        $hits = $this->redisFlatHashToAssoc($hitsRaw);
        $urls = $this->redisFlatHashToAssoc($urlsRaw);
        $firstDates = $this->redisFlatHashToAssoc($firstRaw);
        $lastDates = $this->redisFlatHashToAssoc($lastRaw);
        $referrers = [];

        foreach ($hits AS $hash => $count)
        {
            $url = $urls[$hash] ?? '';
            if (!is_string($url) || !preg_match('#^https?://#i', $url))
            {
                continue;
            }

            $referrers[$hash] = [
                'url' => $url,
                'hits' => max(0, (int)$count),
                'first_date' => max(0, (int)($firstDates[$hash] ?? time())),
                'last_date' => max(0, (int)($lastDates[$hash] ?? time())),
            ];
        }

        return array_filter($referrers, function (array $referrer)
        {
            return $referrer['hits'] > 0;
        });
    }

    protected function redisFlatHashToAssoc(array $flat): array
    {
        $assoc = [];
        $count = count($flat);
        for ($i = 0; $i + 1 < $count; $i += 2)
        {
            $assoc[(string)$flat[$i]] = $flat[$i + 1];
        }

        return $assoc;
    }

    protected function flushImageBatch(int $imageId, array $batch): void
    {
        $db = \XF::db();

        if ($batch['views'] > 0)
        {
            $db->query(
                'UPDATE xf_image_proxy
                    SET views = views + ?, last_request_date = GREATEST(last_request_date, ?)
                    WHERE image_id = ?',
                [$batch['views'], $batch['last_date'] ?: time(), $imageId]
            );
        }

        foreach ($batch['referrers'] AS $hash => $referrer)
        {
            $db->query(
                'INSERT INTO xf_image_proxy_referrer
                    (image_id, referrer_hash, referrer_url, hits, first_date, last_date)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        hits = hits + VALUES(hits),
                        last_date = GREATEST(last_date, VALUES(last_date))',
                [
                    $imageId,
                    $hash,
                    $referrer['url'],
                    $referrer['hits'],
                    $referrer['first_date'],
                    $referrer['last_date'],
                ]
            );
        }
    }

    protected function requeueBatch(\Redis $redis, int $imageId, array $batch): void
    {
        if ($batch['views'] > 0)
        {
            $redis->hIncrBy('wf:image_proxy:views', (string)$imageId, $batch['views']);
            if ($batch['last_date'] > 0)
            {
                $redis->hSet('wf:image_proxy:last', (string)$imageId, $batch['last_date']);
            }
        }

        foreach ($batch['referrers'] AS $hash => $referrer)
        {
            $redis->hIncrBy('wf:image_proxy:ref_hits:' . $imageId, $hash, $referrer['hits']);
            $redis->hSetNx('wf:image_proxy:ref_url:' . $imageId, $hash, $referrer['url']);
            $redis->hSetNx('wf:image_proxy:ref_first:' . $imageId, $hash, $referrer['first_date']);
            $redis->hSet('wf:image_proxy:ref_last:' . $imageId, $hash, $referrer['last_date']);
            $redis->sAdd('wf:image_proxy:ref_images', (string)$imageId);
        }
    }

    protected function releaseLock(\Redis $redis, string $lockToken): void
    {
        $script = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('DEL', KEYS[1])
end
return 0
LUA;

        $redis->eval($script, ['wf:image_proxy:flush_lock', $lockToken], 1);
    }
}
