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

        // Aged threads (>7d tiers) carry multi-day edge TTLs and are Cache
        // Reserve-eligible, so a write can otherwise serve stale from CF until
        // the origin TTL lapses — purge the thread's own URL at the edge.
        // Fresh threads keep the pre-existing accept-staleness-up-to-tier model
        // (short TTLs) and are excluded to keep purge volume negligible.
        self::purgeAgedThreadUrl($thread);

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

    /**
     * Purge the content's OWN url(s) — the page a guest/crawler hit and got a
     * moderation 404 on — when content is released from the approval queue.
     * Unlike purgeForThread() this is NOT gated on news nodes: a moderated
     * post/thread in any node leaves a cached 404 that must be cleared.
     */
    public static function purgeContentForThread($thread, $postId = null)
    {
        if (!$thread || empty($thread->thread_id)) {
            return;
        }

        $nodeIds = self::normalizeNodeIds(self::getThreadNodeIds($thread));

        self::flushInternalCache((int) $thread->thread_id, $nodeIds);

        $urls = self::buildContentUrls($thread, $nodeIds, $postId);
        if (!$urls) {
            return;
        }

        $jobKey = md5('content:' . implode("\n", $urls));
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
                    'node_ids' => array_values($nodeIds),
                    'thread_id' => (int) $thread->thread_id,
                ],
                false
            );
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'SessionValidator moderation cache purge enqueue failed: ');
        }
    }

    /**
     * CF-purge the thread's own canonical URL when a write lands on an aged
     * thread. The job's prefix purge (host/threads/<slug>.<id>) also evicts
     * page-N, /latest, and Cache Reserve entries for the thread. Age is
     * judged on the PRE-SAVE last_post_date — that is the age the cached
     * copies were rendered under.
     */
    protected static function purgeAgedThreadUrl($thread)
    {
        try {
            $lastPostDate = (int) ($thread->last_post_date ?? 0);
            if (method_exists($thread, 'isUpdate') && $thread->isUpdate()
                && method_exists($thread, 'isChanged') && $thread->isChanged('last_post_date')
                && method_exists($thread, 'getExistingValue')
            ) {
                $lastPostDate = (int) $thread->getExistingValue('last_post_date');
            }
            if (!$lastPostDate) {
                return;
            }

            $threshold = (int) (\XF::options()->wfCacheOptimizer_ageThreshold7Days ?? 604800);
            if (\XF::$time - $lastPostDate <= $threshold) {
                return;
            }

            $urls = [];
            try {
                self::addUrlWithSlashVariants($urls, \XF::app()->router('public')->buildLink('canonical:threads', $thread));
            } catch (\Throwable $e) {
                self::addUrlWithSlashVariants($urls, 'threads/' . (int) $thread->thread_id . '/');
            }
            $urls = array_keys($urls);
            if (!$urls) {
                return;
            }

            $jobKey = md5('aged:' . implode("\n", $urls));
            if (isset(self::$queued[$jobKey])) {
                return;
            }
            self::$queued[$jobKey] = true;

            \XF::app()->jobManager()->enqueueUnique(
                'wfLiveNewsCachePurge:' . $jobKey,
                'WindowsForum\SessionValidator\Job\LiveNewsCachePurge',
                [
                    'urls' => $urls,
                    'node_ids' => [],
                    'thread_id' => (int) $thread->thread_id,
                ],
                false
            );
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'SessionValidator aged-thread cache purge enqueue failed: ');
        }
    }

    protected static function buildContentUrls($thread, array $nodeIds, $postId = null)
    {
        $router = \XF::app()->router('public');
        $urls = [];

        try {
            self::addUrlWithSlashVariants($urls, $router->buildLink('canonical:threads', $thread));
            self::addUrlWithSlashVariants($urls, $router->buildLink('canonical:threads/latest', $thread));
            self::addUrlWithSlashVariants($urls, $router->buildLink('canonical:threads/unread', $thread));
        } catch (\Throwable $e) {
            // Fall back to a bare relative URL if the router/entity is unhappy.
            self::addUrlWithSlashVariants($urls, 'threads/' . (int) $thread->thread_id . '/');
        }

        if ($postId) {
            self::addUrlWithSlashVariants($urls, 'posts/' . (int) $postId . '/');
        }

        self::addUrlWithSlashVariants($urls, $router->buildLink('canonical:index'));
        self::addUrlWithSlashVariants($urls, $router->buildLink('canonical:whats-new'));
        self::addUrlWithSlashVariants($urls, $router->buildLink('canonical:whats-new/posts'));

        foreach ($nodeIds as $nodeId) {
            $forum = self::getForumForNode($thread, $nodeId);
            if ($forum) {
                self::addUrlWithSlashVariants($urls, $router->buildLink('canonical:forums', $forum));
            }
        }

        return array_keys($urls);
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

        // Drop the thread's cached guest pages from the Redis page cache (DB1).
        // CacheOptimizer gives old threads an extended page-cache store TTL; this
        // purge is what keeps that safe — a reply/edit/delete clears the stale
        // cached page so the next origin MISS re-renders fresh instead of serving
        // a days-old copy. Fresh threads (600s base TTL) carry no index and no-op.
        if ($threadId) {
            try {
                CacheOptimizer::purgePageCacheForThread($threadId);
            } catch (\Throwable $e) {
                // Best-effort; a missed purge self-heals when the store TTL lapses.
            }
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
