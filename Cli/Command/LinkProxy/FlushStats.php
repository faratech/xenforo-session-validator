<?php

namespace WindowsForum\SessionValidator\Cli\Command\LinkProxy;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use XF\Cli\Command\AbstractCommand;

class FlushStats extends AbstractCommand
{
    protected function configure()
    {
        $this
            ->setName('wf:link-proxy-flush-stats')
            ->setDescription('Flush async link proxy stats')
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum link URL hashes to flush in this run',
                1000
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
        $limit = max(1, min(10000, (int)$input->getOption('limit')));
        $dryRun = (bool)$input->getOption('dry-run');

        $redis = $this->connectRedis();
        if (!$redis)
        {
            $output->writeln('<error>Unable to connect to Redis.</error>');
            return 1;
        }

        $lockToken = bin2hex(random_bytes(12));
        if (!$redis->set('wf:link_proxy:flush_lock', $lockToken, ['nx', 'ex' => 55]))
        {
            $output->writeln('<comment>Another link proxy stats flush is already running.</comment>');
            return 0;
        }

        $processed = 0;
        $hits = 0;
        $referrers = 0;

        try
        {
            foreach ($this->getPendingUrlHashes($redis, $limit) AS $urlHash)
            {
                $batch = $this->popLinkBatch($redis, $urlHash);
                if (!$batch['hits'] && !$batch['referrers'])
                {
                    continue;
                }

                if ($dryRun)
                {
                    $this->requeueBatch($redis, $urlHash, $batch);
                    $processed++;
                    $hits += $batch['hits'];
                    $referrers += count($batch['referrers']);
                    continue;
                }

                try
                {
                    $this->flushLinkBatch($urlHash, $batch);
                    $processed++;
                    $hits += $batch['hits'];
                    $referrers += count($batch['referrers']);
                }
                catch (\Throwable $e)
                {
                    $this->requeueBatch($redis, $urlHash, $batch);
                    throw $e;
                }
            }
        }
        finally
        {
            $this->releaseLock($redis, $lockToken);
        }

        $output->writeln(sprintf(
            'Flushed %d link URLs, %d hits, %d referrer rows.',
            $processed,
            $hits,
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
            $redis->pconnect($host, $port, $timeout, 'wf_link_proxy_flush_' . $database);
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

    protected function getPendingUrlHashes(\Redis $redis, int $limit): array
    {
        $hashes = [];

        foreach ((array)$redis->hKeys('wf:link_proxy:hits') AS $urlHash)
        {
            if (preg_match('/^[a-f0-9]{32}$/', (string)$urlHash))
            {
                $hashes[(string)$urlHash] = true;
            }
            if (count($hashes) >= $limit)
            {
                return array_keys($hashes);
            }
        }

        foreach ((array)$redis->sMembers('wf:link_proxy:ref_links') AS $urlHash)
        {
            if (preg_match('/^[a-f0-9]{32}$/', (string)$urlHash))
            {
                $hashes[(string)$urlHash] = true;
            }
            if (count($hashes) >= $limit)
            {
                break;
            }
        }

        return array_keys($hashes);
    }

    protected function popLinkBatch(\Redis $redis, string $urlHash): array
    {
        $script = <<<'LUA'
local urlHash = ARGV[1]
local hits = redis.call('HGET', KEYS[1], urlHash)
local url = redis.call('HGET', KEYS[2], urlHash)
local firstDate = redis.call('HGET', KEYS[3], urlHash)
local lastDate = redis.call('HGET', KEYS[4], urlHash)
if hits then redis.call('HDEL', KEYS[1], urlHash) end
if url then redis.call('HDEL', KEYS[2], urlHash) end
if firstDate then redis.call('HDEL', KEYS[3], urlHash) end
if lastDate then redis.call('HDEL', KEYS[4], urlHash) end

local refHitsKey = KEYS[5] .. urlHash
local refUrlKey = KEYS[6] .. urlHash
local refFirstKey = KEYS[7] .. urlHash
local refLastKey = KEYS[8] .. urlHash
local refHits = redis.call('HGETALL', refHitsKey)
local refUrls = redis.call('HGETALL', refUrlKey)
local refFirst = redis.call('HGETALL', refFirstKey)
local refLast = redis.call('HGETALL', refLastKey)
redis.call('DEL', refHitsKey, refUrlKey, refFirstKey, refLastKey)
redis.call('SREM', KEYS[9], urlHash)

return {hits or '', url or '', firstDate or '', lastDate or '', refHits, refUrls, refFirst, refLast}
LUA;

        $result = $redis->eval($script, [
            'wf:link_proxy:hits',
            'wf:link_proxy:url',
            'wf:link_proxy:first',
            'wf:link_proxy:last',
            'wf:link_proxy:ref_hits:',
            'wf:link_proxy:ref_url:',
            'wf:link_proxy:ref_first:',
            'wf:link_proxy:ref_last:',
            'wf:link_proxy:ref_links',
            $urlHash,
        ], 9);

        return [
            'hits' => max(0, (int)($result[0] ?? 0)),
            'url' => (string)($result[1] ?? ''),
            'first_date' => max(0, (int)($result[2] ?? time())),
            'last_date' => max(0, (int)($result[3] ?? time())),
            'referrers' => $this->combineReferrers(
                $result[4] ?? [],
                $result[5] ?? [],
                $result[6] ?? [],
                $result[7] ?? []
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

    protected function flushLinkBatch(string $urlHash, array $batch): void
    {
        if ($batch['hits'] > 0 && preg_match('#^https?://#i', $batch['url']))
        {
            \XF::db()->query(
                'INSERT INTO xf_link_proxy
                    (url, url_hash, first_request_date, last_request_date, hits)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        last_request_date = GREATEST(last_request_date, VALUES(last_request_date)),
                        hits = hits + VALUES(hits)',
                [
                    $batch['url'],
                    $urlHash,
                    $batch['first_date'] ?: time(),
                    $batch['last_date'] ?: time(),
                    $batch['hits'],
                ]
            );
        }

        if (!$batch['referrers'])
        {
            return;
        }

        $linkId = (int)\XF::db()->fetchOne(
            'SELECT link_id FROM xf_link_proxy WHERE url_hash = ?',
            $urlHash
        );
        if (!$linkId)
        {
            return;
        }

        foreach ($batch['referrers'] AS $hash => $referrer)
        {
            \XF::db()->query(
                'INSERT INTO xf_link_proxy_referrer
                    (link_id, referrer_hash, referrer_url, hits, first_date, last_date)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        hits = hits + VALUES(hits),
                        last_date = GREATEST(last_date, VALUES(last_date))',
                [
                    $linkId,
                    $hash,
                    $referrer['url'],
                    $referrer['hits'],
                    $referrer['first_date'],
                    $referrer['last_date'],
                ]
            );
        }
    }

    protected function requeueBatch(\Redis $redis, string $urlHash, array $batch): void
    {
        if ($batch['hits'] > 0)
        {
            $redis->hIncrBy('wf:link_proxy:hits', $urlHash, $batch['hits']);
            if ($batch['url'] !== '')
            {
                $redis->hSetNx('wf:link_proxy:url', $urlHash, $batch['url']);
            }
            if ($batch['first_date'] > 0)
            {
                $redis->hSetNx('wf:link_proxy:first', $urlHash, $batch['first_date']);
            }
            if ($batch['last_date'] > 0)
            {
                $redis->hSet('wf:link_proxy:last', $urlHash, $batch['last_date']);
            }
        }

        foreach ($batch['referrers'] AS $hash => $referrer)
        {
            $redis->hIncrBy('wf:link_proxy:ref_hits:' . $urlHash, $hash, $referrer['hits']);
            $redis->hSetNx('wf:link_proxy:ref_url:' . $urlHash, $hash, $referrer['url']);
            $redis->hSetNx('wf:link_proxy:ref_first:' . $urlHash, $hash, $referrer['first_date']);
            $redis->hSet('wf:link_proxy:ref_last:' . $urlHash, $hash, $referrer['last_date']);
            $redis->sAdd('wf:link_proxy:ref_links', $urlHash);
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

        $redis->eval($script, ['wf:link_proxy:flush_lock', $lockToken], 1);
    }
}
