<?php

namespace WindowsForum\SessionValidator\Service;

class LiveNewsCacheInvalidator
{
    protected static $queued = [];

    public static function purgeForThread($thread, array $nodeIds = [])
    {
        if (!$thread || empty($thread->thread_id)) {
            return;
        }

        $nodeIds = self::normalizeNodeIds(array_merge($nodeIds, self::getThreadNodeIds($thread)));

        // Flush the CacheOptimizer's internal Redis cache so the next render
        // uses fresh last_post_date for Last-Modified. Done for every thread
        // save, not just news, since the cache is per-thread and per-forum.
        self::flushInternalCache((int) $thread->thread_id, $nodeIds);

        $newsNodeIds = self::getNewsNodeIds();
        if (!self::intersects($nodeIds, $newsNodeIds)) {
            return;
        }

        $urls = self::buildUrls($thread, $nodeIds);
        if (!$urls) {
            return;
        }

        $jobKey = md5(implode("\n", $urls));
        if (isset(self::$queued[$jobKey])) {
            return;
        }
        self::$queued[$jobKey] = true;

        try {
            \XF::app()->jobManager()->enqueueUnique(
                'wfLiveNewsCachePurge:' . $jobKey,
                'WindowsForum\SessionValidator\Job\LiveNewsCachePurge',
                [
                    'urls' => array_values($urls),
                    'node_ids' => array_values(array_intersect($nodeIds, $newsNodeIds)),
                ],
                false
            );
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'SessionValidator live-news cache purge enqueue failed: ');
        }
    }

    protected static function flushInternalCache($threadId, array $nodeIds)
    {
        $cache = \XF::app()->cache();
        if (!$cache) {
            return;
        }

        try {
            if ($threadId) {
                $cache->delete("wf_co:thread:{$threadId}");
            }
            foreach ($nodeIds as $nodeId) {
                $cache->delete("wf_co:forum:{$nodeId}");
            }
        } catch (\Throwable $e) {
            // Best-effort; the live cache headers will recover within 600s on their own.
        }
    }

    protected static function getThreadNodeIds($thread)
    {
        $nodeIds = [];

        if (!empty($thread->node_id)) {
            $nodeIds[] = (int) $thread->node_id;
        }

        try {
            if (method_exists($thread, 'isUpdate') && $thread->isUpdate()
                && method_exists($thread, 'isChanged') && $thread->isChanged('node_id')
                && method_exists($thread, 'getExistingValue')
            ) {
                $nodeIds[] = (int) $thread->getExistingValue('node_id');
            }
        } catch (\Throwable $e) {
            // Best-effort invalidation only; keep the save path clean.
        }

        return $nodeIds;
    }

    protected static function getNewsNodeIds()
    {
        $options = \XF::options();
        $nodes = array_merge(
            self::parseNodeOption($options->wfCacheOptimizer_extendedCacheNodes ?? '4'),
            self::parseNodeOption($options->wfNewsFilterNodeIds ?? '')
        );

        return self::normalizeNodeIds($nodes ?: [4]);
    }

    protected static function parseNodeOption($value)
    {
        if (is_array($value)) {
            return array_map('intval', $value);
        }

        return array_map('intval', array_filter(
            array_map('trim', explode(',', (string) $value))
        ));
    }

    protected static function normalizeNodeIds(array $nodeIds)
    {
        $nodeIds = array_map('intval', $nodeIds);
        $nodeIds = array_filter($nodeIds);

        return array_values(array_unique($nodeIds));
    }

    protected static function intersects(array $left, array $right)
    {
        return (bool) array_intersect($left, $right);
    }

    protected static function buildUrls($thread, array $nodeIds)
    {
        $router = \XF::app()->router('public');
        $urls = [];

        self::addUrlWithSlashVariants($urls, $router->buildLink('canonical:index'));
        self::addUrlWithSlashVariants($urls, $router->buildLink('canonical:whats-new'));
        self::addUrlWithSlashVariants($urls, $router->buildLink('canonical:whats-new/posts'));
        self::addUrlWithSlashVariants($urls, $router->buildLink('canonical:whats-new/posts', null, [
            'news' => 1,
            'skip' => 1,
        ]));
        self::addUrlWithSlashVariants($urls, $router->buildLink('canonical:threads/latest', $thread));

        foreach ($nodeIds as $nodeId) {
            $forum = self::getForumForNode($thread, $nodeId);
            if ($forum) {
                self::addUrlWithSlashVariants($urls, $router->buildLink('canonical:forums', $forum));
            }
        }

        return array_keys($urls);
    }

    protected static function getForumForNode($thread, $nodeId)
    {
        try {
            if (!empty($thread->Forum) && (int) $thread->Forum->node_id === (int) $nodeId) {
                return $thread->Forum;
            }
        } catch (\Throwable $e) {
            // Fall through to entity lookup.
        }

        try {
            return \XF::em()->find('XF:Forum', (int) $nodeId);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected static function addUrlWithSlashVariants(array &$urls, $url)
    {
        $url = self::normalizeUrl($url);
        if (!$url) {
            return;
        }

        $urls[$url] = true;

        $parts = @parse_url($url);
        if (!$parts || empty($parts['path']) || $parts['path'] === '/') {
            return;
        }

        $path = $parts['path'];
        $hasSlash = substr($path, -1) === '/';
        $variantPath = $hasSlash ? rtrim($path, '/') : $path . '/';

        $variant = (isset($parts['scheme']) ? $parts['scheme'] . '://' : '')
            . ($parts['host'] ?? '')
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . $variantPath
            . (isset($parts['query']) ? '?' . $parts['query'] : '');

        $urls[$variant] = true;
    }

    protected static function normalizeUrl($url)
    {
        $url = html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8');
        if ($url === '') {
            return '';
        }

        if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
            return $url;
        }

        $boardUrl = rtrim((string) \XF::options()->boardUrl, '/');
        if ($boardUrl === '') {
            $boardUrl = 'https://windowsforum.com';
        }

        return $boardUrl . '/' . ltrim($url, '/');
    }
}
