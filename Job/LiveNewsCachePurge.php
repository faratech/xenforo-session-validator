<?php

namespace WindowsForum\SessionValidator\Job;

use XF\Job\AbstractJob;

class LiveNewsCachePurge extends AbstractJob
{
    public function run($maxRunTime)
    {
        $urls = $this->normalizeUrls($this->data['urls'] ?? []);
        $nodeIds = $this->normalizeNodeIds($this->data['node_ids'] ?? []);
        $threadId = (int) ($this->data['thread_id'] ?? 0);

        if ($nodeIds) {
            // Clear XenForo's Redis DB1 listing bodies before the outer cache
            // layers are purged. Otherwise the warmer receives an old PREBHIT
            // and immediately repopulates HTTPjet/Cloudflare with stale HTML.
            \WindowsForum\SessionValidator\Service\CacheOptimizer::purgePageCacheForListings($nodeIds);
        }

        if ($nodeIds || $threadId) {
            $this->purgeLiteSpeedTags($nodeIds, $threadId);
        }

        if ($urls) {
            $this->purgeCloudflare($urls);
            $this->warmUrls($urls);
        }

        return $this->complete();
    }

    protected function purgeCloudflare(array $urls)
    {
        try {
            $cloudflareRepo = $this->app->repository('DigitalPoint\Cloudflare:Cloudflare');
            $cloudflareRepo->purgeCache(['files' => $urls]);

            // Cloudflare's single-file API currently reports success for this
            // zone without evicting cached guest-HTML variants. A narrow path
            // prefix reliably removes those variants. Keep the file purge for
            // the homepage, but never turn `/` into a host-wide prefix purge.
            $prefixes = $this->buildCloudflarePrefixes($urls);
            if ($prefixes) {
                $cloudflareRepo->purgeCache(['prefixes' => $prefixes]);
            }
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'SessionValidator live-news Cloudflare purge failed: ');
        }
    }

    protected function buildCloudflarePrefixes(array $urls)
    {
        $prefixes = [];

        foreach ($urls as $url) {
            $parts = @parse_url($url);
            $host = strtolower((string) ($parts['host'] ?? ''));
            $path = (string) ($parts['path'] ?? '');
            if ($host === '' || $path === '' || $path === '/') {
                continue;
            }

            $prefixes[$host . rtrim($path, '/')] = true;
        }

        return array_keys($prefixes);
    }

    protected function purgeLiteSpeedTags(array $nodeIds, $threadId = 0)
    {
        $tags = ['H', 'WN'];
        foreach ($nodeIds as $nodeId) {
            $tags[] = 'F' . (int) $nodeId;
        }
        if ($threadId) {
            // Matches the T<id> tag CacheOptimizer emits for thread pages.
            $tags[] = 'T' . (int) $threadId;
        }

        $host = parse_url((string) \XF::options()->boardUrl, PHP_URL_HOST) ?: 'windowsforum.com';
        $hostArg = escapeshellarg('Host: ' . $host);

        foreach (array_unique($tags) as $tag) {
            $tag = preg_replace('/[^A-Za-z0-9_-]/', '', $tag);
            if ($tag === '') {
                continue;
            }

            // Complete each local tag purge before starting the warmer. A
            // detached purge can race the warm requests, either replaying the
            // old outer entry or deleting the newly warmed replacement.
            @exec("curl -s --connect-timeout 1 --max-time 2 'http://127.0.0.1/lscache_purge.php?tag={$tag}' -H {$hostArg} > /dev/null 2>&1");
        }
    }

    protected function warmUrls(array $urls)
    {
        $userAgent = escapeshellarg('WindowsForumCacheWarmer/SessionValidator');

        foreach ($urls as $url) {
            $urlArg = escapeshellarg($url);
            $curl = "curl -s --compressed --connect-timeout 2 --max-time 8 -A {$userAgent} {$urlArg}";
            // The first request recreates the now-missing XenForo DB1 page.
            // With the optimizer master switch off that full render is private
            // to HTTPjet/Cloudflare; the second request is the fresh PREBHIT
            // that safely warms those outer public caches.
            @exec("({$curl}; {$curl}) > /dev/null 2>&1 &");
        }
    }

    protected function normalizeUrls(array $urls)
    {
        $normalized = [];

        foreach ($urls as $url) {
            $url = (string) $url;
            if ($url !== '' && preg_match('#^https?://#i', $url)) {
                $normalized[$url] = true;
            }
        }

        return array_keys($normalized);
    }

    protected function normalizeNodeIds(array $nodeIds)
    {
        $nodeIds = array_map('intval', $nodeIds);
        $nodeIds = array_filter($nodeIds);

        return array_values(array_unique($nodeIds));
    }

    public function getStatusMessage()
    {
        return 'Purging live-news cache';
    }

    public function canCancel()
    {
        return false;
    }

    public function canTriggerByChoice()
    {
        return false;
    }
}
