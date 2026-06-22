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
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'SessionValidator live-news Cloudflare purge failed: ');
        }
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

            \WindowsForum\SessionValidator\Service\GenericShellFragment::purgeByTag($tag);
            @exec("curl -s -m 2 'http://127.0.0.1/lscache_purge.php?tag={$tag}' -H {$hostArg} > /dev/null 2>&1 &");
        }
    }

    protected function warmUrls(array $urls)
    {
        $userAgent = escapeshellarg('WindowsForumCacheWarmer/SessionValidator');

        foreach ($urls as $url) {
            $urlArg = escapeshellarg($url);
            @exec("curl -s --compressed --connect-timeout 2 --max-time 8 -A {$userAgent} {$urlArg} > /dev/null 2>&1 &");
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
