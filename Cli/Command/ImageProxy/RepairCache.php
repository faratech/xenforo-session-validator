<?php

namespace WindowsForum\SessionValidator\Cli\Command\ImageProxy;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use XF\Cli\Command\AbstractCommand;

class RepairCache extends AbstractCommand
{
    protected function configure()
    {
        $this
            ->setName('wf:image-proxy-repair-cache')
            ->setDescription('Repair missing or size-mismatched image proxy cache files from a peer node')
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum image proxy rows to repair in this run',
                50
            )
            ->addOption(
                'scan-limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum recent image proxy rows to inspect',
                2000
            )
            ->addOption(
                'peer',
                null,
                InputOption::VALUE_REQUIRED,
                'SSH peer to pull cache files from',
                'root@10.10.0.3'
            )
            ->addOption(
                'peer-root',
                null,
                InputOption::VALUE_REQUIRED,
                'Peer XenForo root path',
                '/web/public_html'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Report repairable rows without copying files or updating MySQL'
            )
            ->addOption(
                'no-prune-missing',
                null,
                InputOption::VALUE_NONE,
                'Do not mark rows pruned when neither local nor peer cache files validate'
            )
            ->addOption(
                'no-peer-manifest',
                null,
                InputOption::VALUE_NONE,
                'Do not build a peer file manifest before attempting peer repairs'
            )
            ->addOption(
                'no-cluster-lock',
                null,
                InputOption::VALUE_NONE,
                'Do not use the Redis cluster-wide repair lock'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $limit = max(1, min(1000, (int)$input->getOption('limit')));
        $scanLimit = max($limit, min(20000, (int)$input->getOption('scan-limit')));
        $peer = (string)$input->getOption('peer');
        $peerRoot = rtrim((string)$input->getOption('peer-root'), '/');
        $dryRun = (bool)$input->getOption('dry-run');
        $pruneMissing = !(bool)$input->getOption('no-prune-missing');
        $usePeerManifest = !(bool)$input->getOption('no-peer-manifest');
        $useClusterLock = !(bool)$input->getOption('no-cluster-lock');

        if ($peer === '' || $peerRoot === '')
        {
            $output->writeln('<error>Both --peer and --peer-root are required.</error>');
            return 1;
        }

        $redis = $useClusterLock ? $this->connectRedis() : null;
        $lockToken = $redis ? bin2hex(random_bytes(12)) : '';
        if ($redis && !$redis->set('wf:image_proxy:repair_lock', $lockToken, ['nx', 'ex' => 240]))
        {
            $output->writeln('<comment>Another image proxy cache repair is already running.</comment>');
            return 0;
        }
        if ($useClusterLock && !$redis)
        {
            $output->writeln('<comment>Redis unavailable; continuing with local process lock only.</comment>');
        }

        $ok = 0;
        $checked = 0;
        $metadataFixed = 0;
        $copiedFromPeer = 0;
        $prunedMissing = 0;
        $invalid = 0;
        $failed = 0;

        try
        {
            $rows = $this->getCandidateRows($scanLimit, $redis);
            $peerManifest = $usePeerManifest ? $this->buildPeerManifest($peer, $peerRoot, $output) : null;
            foreach ($rows AS $row)
            {
                if (($metadataFixed + $copiedFromPeer + $prunedMissing) >= $limit)
                {
                    break;
                }

                $path = $this->getImagePath($row);
                $localValidation = $this->validateLocalFile($path, (string)$row['mime_type']);
                if ($localValidation['valid'])
                {
                    $this->clearMissingFileCandidate($redis, $row);

                    if ($this->rowMatchesValidation($row, $localValidation))
                    {
                        $ok++;
                        continue;
                    }

                    $checked++;
                    if ($dryRun)
                    {
                        $metadataFixed++;
                        $output->writeln(sprintf(
                            'Metadata stale image_id=%d db_size=%d actual_size=%d db_mime=%s actual_mime=%s',
                            $row['image_id'],
                            $row['file_size'],
                            $localValidation['size'],
                            $row['mime_type'],
                            $localValidation['mime_type']
                        ));
                        continue;
                    }

                    $this->updateImageRow($row, $localValidation);
                    $this->clearNegativeCache($redis, (string)$row['url_hash']);
                    $this->clearMissingFileCandidate($redis, $row);
                    $metadataFixed++;
                    $output->writeln(sprintf(
                        'Updated metadata image_id=%d size=%d mime=%s',
                        $row['image_id'],
                        $localValidation['size'],
                        $localValidation['mime_type']
                    ));
                    continue;
                }

                $checked++;
                if ($peerManifest !== null && !isset($peerManifest[$path]))
                {
                    $tempFile = null;
                }
                else
                {
                    $tempFile = $this->copyFromPeer($peer, $peerRoot, $path);
                }
                if (!$tempFile)
                {
                    if ($pruneMissing)
                    {
                        if ($dryRun)
                        {
                            $prunedMissing++;
                            $output->writeln(sprintf(
                                'Would mark pruned image_id=%d reason=peer_missing',
                                $row['image_id']
                            ));
                        }
                        else
                        {
                            $this->markImagePruned($row);
                            $this->clearMissingFileCandidate($redis, $row);
                            $prunedMissing++;
                            $output->writeln(sprintf(
                                'Marked pruned image_id=%d reason=peer_missing',
                                $row['image_id']
                            ));
                        }
                    }
                    else
                    {
                        $failed++;
                    }
                    continue;
                }

                $validation = $this->validateCopiedFile($tempFile, (string)$row['mime_type']);
                if (!$validation['valid'])
                {
                    @unlink($tempFile);
                    if ($pruneMissing)
                    {
                        if ($dryRun)
                        {
                            $prunedMissing++;
                            $output->writeln(sprintf(
                                'Would mark pruned image_id=%d reason=peer_invalid',
                                $row['image_id']
                            ));
                        }
                        else
                        {
                            $this->markImagePruned($row);
                            $this->clearMissingFileCandidate($redis, $row);
                            $prunedMissing++;
                            $output->writeln(sprintf(
                                'Marked pruned image_id=%d reason=peer_invalid',
                                $row['image_id']
                            ));
                        }
                    }
                    else
                    {
                        $invalid++;
                    }
                    continue;
                }

                if ($dryRun)
                {
                    @unlink($tempFile);
                    $copiedFromPeer++;
                    $output->writeln(sprintf(
                        'Peer repairable image_id=%d size=%d mime=%s',
                        $row['image_id'],
                        $validation['size'],
                        $validation['mime_type']
                    ));
                    continue;
                }

                if ($this->installRepairedFile($tempFile, $path))
                {
                    $this->updateImageRow($row, $validation);
                    $this->clearNegativeCache($redis, (string)$row['url_hash']);
                    $this->clearMissingFileCandidate($redis, $row);
                    $copiedFromPeer++;
                    $output->writeln(sprintf(
                        'Repaired image_id=%d size=%d mime=%s',
                        $row['image_id'],
                        $validation['size'],
                        $validation['mime_type']
                    ));
                }
                else
                {
                    @unlink($tempFile);
                    $failed++;
                }
            }
        }
        finally
        {
            if ($redis && $lockToken !== '')
            {
                $this->releaseLock($redis, 'wf:image_proxy:repair_lock', $lockToken);
            }
        }

        $this->recordRepairStats($redis, [
            'ok' => $ok,
            'checked' => $checked,
            'metadata_fixed' => $metadataFixed,
            'copied_from_peer' => $copiedFromPeer,
            'pruned_missing' => $prunedMissing,
            'invalid' => $invalid,
            'failed' => $failed,
        ]);

        $output->writeln(sprintf(
            'Scanned %d rows: ok %d, checked %d, metadata_fixed %d, copied_from_peer %d, pruned_missing %d, invalid %d, failed %d.',
            count($rows ?? []),
            $ok,
            $checked,
            $metadataFixed,
            $copiedFromPeer,
            $prunedMissing,
            $invalid,
            $failed
        ));

        return $failed && !($metadataFixed || $copiedFromPeer || $prunedMissing) ? 1 : 0;
    }

    protected function getCandidateRows(int $scanLimit, $redis = null): array
    {
        $priorityRows = [];
        $priorityIds = $this->getMissingFileCandidateIds($redis, $scanLimit);
        if ($priorityIds)
        {
            $priorityRows = \XF::db()->fetchAll(
                'SELECT image_id, url_hash, file_size, file_name, file_hash, mime_type, fetch_date, url
                    FROM xf_image_proxy
                    WHERE image_id IN (' . \XF::db()->quote($priorityIds) . ')
                        AND image_id > 0
                        AND file_size > 0
                        AND fetch_date > 0
                        AND failed_date = 0
                        AND fail_count = 0
                        AND pruned = 0
                        AND is_processing = 0
                    ORDER BY last_request_date DESC'
            );
        }

        $recentRows = \XF::db()->fetchAll(
            'SELECT image_id, url_hash, file_size, file_name, file_hash, mime_type, fetch_date, url
                FROM xf_image_proxy
                WHERE image_id > 0
                    AND file_size > 0
                    AND fetch_date > 0
                    AND failed_date = 0
                    AND fail_count = 0
                    AND pruned = 0
                    AND is_processing = 0
                ORDER BY last_request_date DESC
                LIMIT ?',
            $scanLimit
        );

        if (!$priorityRows)
        {
            return $recentRows;
        }

        $rows = [];
        foreach (array_merge($priorityRows, $recentRows) AS $row)
        {
            $rows[(int)$row['image_id']] = $row;
        }

        return array_values($rows);
    }

    protected function getMissingFileCandidateIds($redis, int $limit): array
    {
        if (!$redis)
        {
            return [];
        }

        try
        {
            $members = (array)$redis->sMembers('wf:image_proxy:missing_files');
        }
        catch (\Throwable $e)
        {
            return [];
        }

        $ids = [];
        foreach ($members AS $member)
        {
            $parts = explode(':', (string)$member, 2);
            $imageId = (int)$parts[0];
            if ($imageId > 0)
            {
                $ids[$imageId] = $imageId;
            }
            if (count($ids) >= $limit)
            {
                break;
            }
        }

        return array_values($ids);
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
            $redis->pconnect($host, $port, $timeout, 'wf_image_proxy_repair_' . $database);
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

    protected function releaseLock(\Redis $redis, string $key, string $lockToken): void
    {
        $script = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('DEL', KEYS[1])
end
return 0
LUA;

        $redis->eval($script, [$key, $lockToken], 1);
    }

    protected function getImagePath(array $row): string
    {
        $imageId = (int)$row['image_id'];
        return sprintf(
            'internal_data/image_cache/%d/%d-%s.data',
            floor($imageId / 1000),
            $imageId,
            $row['url_hash']
        );
    }

    protected function validateLocalFile(string $relativePath, string $expectedMimeType): array
    {
        $path = \XF::getRootDirectory() . '/' . $relativePath;
        if (!is_file($path) || !is_readable($path))
        {
            return ['valid' => false];
        }

        return $this->validateCopiedFile($path, $expectedMimeType);
    }

    protected function rowMatchesValidation(array $row, array $validation): bool
    {
        return (int)$row['file_size'] === (int)$validation['size']
            && hash_equals((string)$row['file_hash'], (string)$validation['hash'])
            && (string)$row['mime_type'] === (string)$validation['mime_type'];
    }

    protected function buildPeerManifest(string $peer, string $peerRoot, OutputInterface $output): ?array
    {
        $remoteCommand = sprintf(
            'cd %s && find internal_data/image_cache -type f -name %s -printf %s',
            escapeshellarg($peerRoot),
            escapeshellarg('*.data'),
            escapeshellarg("%p\t%s\n")
        );
        $command = sprintf(
            'ssh -o BatchMode=yes -o ConnectTimeout=5 %s %s 2>/dev/null',
            escapeshellarg($peer),
            escapeshellarg($remoteCommand)
        );

        $lines = [];
        exec($command, $lines, $exitCode);
        if ($exitCode !== 0)
        {
            $output->writeln('<comment>Unable to build peer image-cache manifest; falling back to direct peer copy attempts.</comment>');
            return null;
        }

        $manifest = [];
        foreach ($lines AS $line)
        {
            [$path, $size] = array_pad(explode("\t", $line, 2), 2, null);
            if ($path !== null && $path !== '' && (int)$size > 0)
            {
                $manifest[$path] = (int)$size;
            }
        }

        $output->writeln(sprintf('Loaded peer image-cache manifest with %d files.', count($manifest)));
        return $manifest;
    }

    protected function copyFromPeer(string $peer, string $peerRoot, string $relativePath): ?string
    {
        $tempFile = tempnam(\XF\Util\File::getTempDir(), 'wf-iprepair-');
        if (!$tempFile)
        {
            return null;
        }

        $source = $peer . ':' . $peerRoot . '/' . $relativePath;
        $command = [
            'rsync',
            '-a',
            '--timeout=8',
            '--protect-args',
            $source,
            $tempFile,
        ];

        $escaped = array_map('escapeshellarg', $command);
        exec(implode(' ', $escaped) . ' 2>/dev/null', $output, $exitCode);
        if ($exitCode !== 0 || !is_file($tempFile) || filesize($tempFile) <= 0)
        {
            @unlink($tempFile);
            return null;
        }

        return $tempFile;
    }

    protected function validateCopiedFile(string $file, string $expectedMimeType): array
    {
        $size = filesize($file);
        if ($size <= 0)
        {
            return ['valid' => false];
        }

        $mimeType = $expectedMimeType;
        if ($expectedMimeType === 'image/svg+xml')
        {
            $contents = file_get_contents($file);
            if (!is_string($contents) || stripos($contents, '<svg') === false)
            {
                return ['valid' => false];
            }
        }
        else
        {
            $imageInfo = @getimagesize($file);
            if (!$imageInfo || empty($imageInfo['mime']) || strpos($imageInfo['mime'], 'image/') !== 0)
            {
                return ['valid' => false];
            }

            $mimeType = $imageInfo['mime'];
        }

        return [
            'valid' => true,
            'size' => $size,
            'hash' => md5_file($file),
            'mime_type' => $mimeType,
        ];
    }

    protected function installRepairedFile(string $tempFile, string $relativePath): bool
    {
        $target = \XF::getRootDirectory() . '/' . $relativePath;
        $dir = dirname($target);
        if (!is_dir($dir) && !\XF\Util\File::createDirectory($dir, false))
        {
            return false;
        }

        if (!@rename($tempFile, $target))
        {
            if (!@copy($tempFile, $target))
            {
                return false;
            }
            @unlink($tempFile);
        }

        @chmod($target, 0644);
        return true;
    }

    protected function updateImageRow(array $row, array $validation): void
    {
        \XF::db()->query(
            'UPDATE xf_image_proxy
                SET file_size = ?,
                    file_hash = ?,
                    mime_type = ?,
                    pruned = 0,
                    failed_date = 0,
                    fail_count = 0,
                    is_processing = 0
                WHERE image_id = ?',
            [
                $validation['size'],
                $validation['hash'],
                $validation['mime_type'],
                $row['image_id'],
            ]
        );
    }

    protected function markImagePruned(array $row): void
    {
        \XF::db()->query(
            'UPDATE xf_image_proxy
                SET pruned = 1,
                    file_hash = \'\',
                    is_processing = 0
                WHERE image_id = ?',
            [$row['image_id']]
        );
    }

    protected function clearNegativeCache($redis, string $urlHash): void
    {
        if (!$redis)
        {
            return;
        }

        try
        {
            $redis->del('wf:image_proxy:negative:' . $urlHash);
        }
        catch (\Throwable $e)
        {
        }
    }

    protected function clearMissingFileCandidate($redis, array $row): void
    {
        if (!$redis)
        {
            return;
        }

        try
        {
            $redis->sRem('wf:image_proxy:missing_files', (int)$row['image_id'] . ':' . (string)$row['url_hash']);
        }
        catch (\Throwable $e)
        {
        }
    }

    protected function recordRepairStats($redis, array $stats): void
    {
        if (!$redis)
        {
            return;
        }

        try
        {
            foreach ($stats AS $key => $value)
            {
                $redis->hIncrBy('wf:image_proxy:repair_stats', $key, (int)$value);
            }
            $redis->expire('wf:image_proxy:repair_stats', 604800);
        }
        catch (\Throwable $e)
        {
        }
    }
}
