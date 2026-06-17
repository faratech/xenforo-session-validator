<?php

namespace WindowsForum\SessionValidator\Service;

use XF\App;
use XF\Http\Response;

class CacheOptimizer
{
    protected $app;
    protected $response;
    protected $options;
    protected $extendedCacheNodes;
    
    public function __construct()
    {
        $this->app = \XF::app();
        $this->response = $this->app->response();
        $this->options = $this->app->options();
    }
    
    /**
     * Set cache headers based on current page context
     */
    public function setCacheHeaders()
    {
        // Don't set headers if they've already been sent
        if (headers_sent()) {
            return;
        }

        $visitor = \XF::visitor();
        $request = $this->app->request();
        $routePath = $request->getRoutePath();

        if ($routePath === 'wf-unfurl/image') {
            $this->setCacheHeadersForUnfurlImage();
            return;
        }

        // Clear any existing cache headers
        $this->clearCacheHeaders();

        // Never cache XenForo error pages. A Reply\Error renders the "An error
        // occurred while the page was being generated" page with HTTP 200 (XF
        // default), so a transient render failure on an otherwise-cacheable route
        // (e.g. a brand-new news thread whose image/summary isn't ready) would
        // otherwise inherit the full public/s-maxage treatment below and get
        // pinned at the Cloudflare edge + LiteSpeed for hours. Detect it from the
        // rendered body — the <html ... data-template="error"> marker sits in the
        // first bytes — and force no-cache before any route-based caching runs.
        if ($this->isErrorPage()) {
            $this->setNoCacheForError();
            return;
        }

        // Always set no-cache for authentication-related pages — must run before
        // error code check because a 403 on /register/ or /login/ (IP ban) is
        // per-visitor and must never be publicly cached.
        if ($this->isAuthenticationRoute($routePath)) {
            $this->setNoCacheForAuthPages();
            return;
        }

        $httpCode = $this->response->httpCode();

        // A redirect whose Location is the request's own URL is a self-redirect loop.
        // Publicly caching it strips the Set-Cookie that would have broken the loop and
        // pins an infinite "301 to itself" at the shared cache + edge (the homepage
        // guest-redirect incident). Force it private + no-store so it is never shared-
        // cached; a genuine cross-scheme http->https UPGRADE is NOT a self-redirect and
        // stays cacheable (see isSelfRedirect).
        if (in_array($httpCode, [301, 302, 303, 307, 308], true) && $this->isSelfRedirect()) {
            $this->setNoCacheForRedirect($httpCode);
            return;
        }

        // 301/308 permanent redirects are highly cacheable (no DB query needed)
        if ($httpCode === 301 || $httpCode === 308) {
            $this->setRedirectCacheHeaders($httpCode);
            return;
        }

        // Check if user is authenticated — must run BEFORE error code check
        // because a banned user's 403 is per-visitor and must never be public-cached.
        if ($visitor->user_id) {
            // A member WRITE into a thread invalidates that thread's cached copies —
            // the public entry AND every session's private one, on both nodes
            // (httpjet applies X-LiteSpeed-Purge from any response and fans it out
            // to its peer). Without this the member's own just-posted reply could
            // hide behind their private entry's TTL.
            if ($request->getRequestMethod() === 'post'
                && preg_match('#^threads/[^/]+\.(\d+)(?:/|$)#', $routePath, $purgeMatch)
            ) {
                $this->response->header('X-LiteSpeed-Purge', 'tag=T' . $purgeMatch[1]);
            }
            if ($httpCode === 200) {
                $this->setPrivateCacheForUser($routePath);
            } else {
                $this->setNoCacheForUser();
            }
            return;
        }

        // Only cache temporary redirects where the target is public and useful
        // to collapse crawler traffic. Other 302/303 responses may be contextual.
        if ($httpCode === 302 || $httpCode === 303) {
            if ($this->isGuestCacheableRedirect($routePath)) {
                $this->setTemporaryRedirectCacheHeaders($httpCode, $routePath);
            } else {
                $this->setNoCacheForRedirect($httpCode);
            }
            return;
        }

        // Error pages — cache at edge to absorb bot/crawler probes
        // Safe now because authenticated users (including banned) are already handled above.
        if (in_array($httpCode, [400, 404, 410])) {
            $this->setErrorCacheHeaders($httpCode);
            return;
        }

        // Style variations (light/dark) are handled by LiteSpeed via X-LiteSpeed-Vary
        // which creates separate cache entries per xf_style_variation cookie.
        // Style ID overrides (different theme) are handled by the CF Worker
        // which varies the cache key by xf_style_id cookie.

        // For guests, set cache headers based on content.
        $this->applyPathBasedHeaders($routePath);
    }

    /**
     * Path-based PUBLIC cache headers for a guest page. Shared by setCacheHeaders()
     * (app_pub_complete) and setGuestPreStoreHeaders() (controller_post_dispatch — which
     * runs BEFORE XF\Pub\App stores the response in the page cache, so the stored copy
     * carries `public` instead of XF's default `private`).
     */
    protected function applyPathBasedHeaders($routePath)
    {
        // Extract first path segment for O(1) prefix dispatch instead of sequential regex.
        $slashPos = strpos($routePath, '/');
        $prefix = $slashPos !== false ? substr($routePath, 0, $slashPos) : $routePath;

        switch ($prefix) {
            case '':
                $this->setHomepageCacheHeaders();
                break;

            case 'threads':
                if (preg_match('#^threads/[^/]+\.(\d+)(?:/|$)#', $routePath, $matches)) {
                    $this->setThreadCacheHeaders($matches[1]);
                } else {
                    $this->setDefaultCacheHeaders();
                }
                break;

            case 'forums':
                if (preg_match('#^forums/[^/]+\.(\d+)(?:/|$)#', $routePath, $matches)) {
                    $this->setForumCacheHeaders($matches[1]);
                } else {
                    $this->setDefaultCacheHeaders();
                }
                break;

            case 'media':
                // Order: sub-paths (albums/, categories/) before bare media item
                if (preg_match('#^media/albums/[^/]+\.(\d+)(?:/|$)#', $routePath, $matches)) {
                    $this->setAlbumCacheHeaders($matches[1]);
                } elseif (preg_match('#^media/categories/[^/]+\.(\d+)(?:/|$)#', $routePath, $matches)) {
                    $this->setMediaCategoryCacheHeaders();
                } elseif (preg_match('#^media/[^/]+\.(\d+)(?:/|$)#', $routePath, $matches)) {
                    $this->setMediaCacheHeaders($matches[1]);
                } elseif (preg_match('#^media/?$#', $routePath)) {
                    $this->setListingCacheHeaders('media-index');
                } else {
                    $this->setDefaultCacheHeaders();
                }
                break;

            case 'resources':
                if (preg_match('#^resources/categories/[^/]+\.(\d+)(?:/|$)#', $routePath, $matches)) {
                    $this->setResourceCategoryCacheHeaders($matches[1]);
                } elseif (preg_match('#^resources/[^/]+\.(\d+)(?:/|$)#', $routePath, $matches)) {
                    $this->setResourceCacheHeaders($matches[1]);
                } elseif (preg_match('#^resources/?$#', $routePath)) {
                    $this->setListingCacheHeaders('resource-index');
                } else {
                    $this->setDefaultCacheHeaders();
                }
                break;

            case 'members':
                if (preg_match('#^members/[^/]+\.(\d+)(?:/|$)#', $routePath, $matches)) {
                    $this->setMemberCacheHeaders($matches[1]);
                } elseif (preg_match('#^members/?$#', $routePath)) {
                    $this->setListingCacheHeaders('member-index');
                } else {
                    $this->setDefaultCacheHeaders();
                }
                break;

            case 'tags':
                if (preg_match('#^tags/([^/]+)#', $routePath, $matches)) {
                    $this->setTagCacheHeaders($matches[1]);
                } elseif (preg_match('#^tags/?$#', $routePath)) {
                    $this->setListingCacheHeaders('tag-index');
                } else {
                    $this->setDefaultCacheHeaders();
                }
                break;

            case 'whats-new':
                $this->setWhatsNewCacheHeaders();
                break;

            case 'help':
                $this->setStaticPageCacheHeaders('help');
                break;

            case 'pages':
                $this->setStaticPageCacheHeaders('page');
                break;

            default:
                $this->setDefaultCacheHeaders();
                break;
        }
    }

    /**
     * Set PUBLIC guest cache headers BEFORE XenForo's page cache stores the response.
     * `XF\Pub\App::complete()` calls `saveToCache()` BEFORE firing `app_pub_complete`
     * (where setCacheHeaders runs), so without this the stored copy carries XF's default
     * `Cache-control: private, no-cache, max-age=0` (App.php) and is replayed private by
     * the page cache / pagecache.php. Returns false for auth routes so the caller disables
     * page caching for them (they must never be stored or served public).
     */
    public function setGuestPreStoreHeaders()
    {
        if (headers_sent()) {
            return false;
        }
        $routePath = $this->app->request()->getRoutePath();
        if ($routePath === 'wf-unfurl/image') {
            $this->setCacheHeadersForUnfurlImage();
            return false;
        }

        $this->clearCacheHeaders();
        if ($this->isAuthenticationRoute($routePath)) {
            $this->setNoCacheForAuthPages();
            return false;
        }
        $this->applyPathBasedHeaders($routePath);
        return true;
    }
    
    /**
     * Set no-cache headers for logged-in users
     */
    protected function setNoCacheForUser()
    {
        // Force no-cache for authenticated users
        $this->response->header('Cache-Control', 'private, no-cache, no-store, must-revalidate, max-age=0');
        $this->response->header('Pragma', 'no-cache');
        $this->response->header('Expires', '0');

        // Vary by authentication cookies to ensure proper cache separation
        $this->response->header('Vary', 'Cookie');

        // Add Cloudflare-specific header to prevent edge caching
        $this->response->header('Cloudflare-CDN-Cache-Control', 'no-cache, no-store, private');

        // Add LiteSpeed-specific headers to bypass cache for logged-in users
        $this->response->header('X-LiteSpeed-Cache-Control', 'no-cache');
        $this->response->header('X-LiteSpeed-Tag', 'private');

        // Identify that these headers came from us
        $this->response->header('X-Cache-Optimizer', 'no-cache-user');
    }

    /**
     * Per-session PRIVATE cache for logged-in page VIEWS (httpjet --page-cache-private).
     * The origin stores one copy per xf_session and only ever serves it back to that
     * exact session; browsers still revalidate every load (max-age=0, must-revalidate)
     * and Cloudflare never edge-caches it. Only allowlisted view routes opt in —
     * everything else keeps the hard no-cache. Deliberately NO `no-store` on the
     * standard Cache-Control: that token vetoes the origin's private store
     * (no-store always wins in httpjet's classifier).
     */
    protected function setPrivateCacheForUser($routePath)
    {
        $tag = null;
        $slashPos = strpos($routePath, '/');
        $prefix = $slashPos !== false ? substr($routePath, 0, $slashPos) : $routePath;
        switch ($prefix) {
            case '':
                $tag = 'H';
                break;
            case 'threads':
                if (preg_match('#^threads/[^/]+\.(\d+)(?:/|$)#', $routePath, $m)) {
                    $tag = 'T' . $m[1];
                }
                break;
            case 'forums':
                if (preg_match('#^forums/[^/]+\.(\d+)(?:/|$)#', $routePath, $m)) {
                    $tag = 'F' . $m[1];
                }
                break;
            case 'whats-new':
                $tag = 'WN';
                break;
        }
        if ($tag === null) {
            // Not an allowlisted page view (account/, conversations/, posts/, …):
            // the pre-tier hard bypass.
            $this->setNoCacheForUser();
            return;
        }

        $this->response->header('Cache-Control', 'private, max-age=0, must-revalidate');
        $this->response->header('Pragma', 'no-cache');
        $this->response->header('Expires', '0');
        $this->response->header('Vary', 'Cookie');
        $this->response->header('Cloudflare-CDN-Cache-Control', 'no-cache, no-store, private');
        $this->response->header('X-LiteSpeed-Cache-Control', 'private,max-age=60');
        // 'private' groups every private entry for an ops sweep (purge tag=private);
        // the content tag lets a write purge private copies alongside the public one.
        $this->response->header('X-LiteSpeed-Tag', "private, $tag");
        $this->response->header('X-Cache-Optimizer', 'private-cache-user');
    }

    /**
     * Set cache headers for thread pages based on thread's actual age
     */
    protected function setThreadCacheHeaders($threadId)
    {
        // Single DB query to get both age and node ID (raw query, no entity overhead)
        $thread = $this->getThread($threadId);
        $age = ($thread && $thread['last_post_date']) ? (time() - $thread['last_post_date']) : null;
        $nodeId = $thread ? $thread['node_id'] : null;

        // Get age thresholds from options
        $threshold1Day = $this->options->wfCacheOptimizer_ageThreshold1Day ?? 86400;
        $threshold7Days = $this->options->wfCacheOptimizer_ageThreshold7Days ?? 604800;
        $threshold30Days = $this->options->wfCacheOptimizer_ageThreshold30Days ?? 2592000;
        $ancientThreshold = $this->options->wfCacheOptimizer_ancientThreshold ?? 315360000; // 10 years

        // Check if this thread's forum gets extended cache times
        $extendedCacheNodes = $this->getExtendedCacheNodes();
        $isExtendedCache = $nodeId && in_array((int) $nodeId, $extendedCacheNodes, true);

        // Determine cache times based on age
        if ($age === null) {
            // Cannot determine age, use moderate caching
            [$cacheTime, $edgeCache] = $isExtendedCache
                ? $this->getCachePair('wfCacheOptimizer_extendedThread7Days', 604800, 'wfCacheOptimizer_extendedThread7DaysEdgeCache', 1814400)
                : $this->getCachePair('wfCacheOptimizer_thread7Days', 86400, 'wfCacheOptimizer_thread7DaysEdgeCache', 259200);
        } elseif ($age > $ancientThreshold) {
            // Ancient content (> 10 years)
            [$cacheTime, $edgeCache] = $this->getCachePair('wfCacheOptimizer_ancientCache', 31536000, 'wfCacheOptimizer_ancientCacheEdgeCache', 31536000);
        } elseif ($age < $threshold1Day) {
            // Fresh content (< 24 hours)
            [$cacheTime, $edgeCache] = $isExtendedCache
                ? $this->getCachePair('wfCacheOptimizer_extendedThreadFresh', 3600, 'wfCacheOptimizer_extendedThreadFreshEdgeCache', 10800)
                : $this->getCachePair('wfCacheOptimizer_threadFresh', 600, 'wfCacheOptimizer_threadFreshEdgeCache', 1800);
        } elseif ($age < $threshold7Days) {
            // Recent content (1-7 days)
            [$cacheTime, $edgeCache] = $isExtendedCache
                ? $this->getCachePair('wfCacheOptimizer_extendedThread1Day', 86400, 'wfCacheOptimizer_extendedThread1DayEdgeCache', 259200)
                : $this->getCachePair('wfCacheOptimizer_thread1Day', 7200, 'wfCacheOptimizer_thread1DayEdgeCache', 21600);
        } elseif ($age < $threshold30Days) {
            // Older content (7-30 days)
            [$cacheTime, $edgeCache] = $isExtendedCache
                ? $this->getCachePair('wfCacheOptimizer_extendedThread7Days', 604800, 'wfCacheOptimizer_extendedThread7DaysEdgeCache', 1814400)
                : $this->getCachePair('wfCacheOptimizer_thread7Days', 86400, 'wfCacheOptimizer_thread7DaysEdgeCache', 259200);
        } else {
            // Archived content (30+ days)
            [$cacheTime, $edgeCache] = $isExtendedCache
                ? $this->getCachePair('wfCacheOptimizer_extendedThread30Days', 2592000, 'wfCacheOptimizer_extendedThread30DaysEdgeCache', 7776000)
                : $this->getCachePair('wfCacheOptimizer_thread30Days', 604800, 'wfCacheOptimizer_thread30DaysEdgeCache', 2592000);
        }

        // Set cache headers with thread-specific tag for targeted purging
        $this->setCacheControlHeaders($cacheTime, $edgeCache, "T$threadId");

        // Set Last-Modified from thread's last post date
        if ($thread && $thread['last_post_date']) {
            $this->setLastModifiedHeader($thread['last_post_date']);
        }

        // Identify cache type for debugging
        $ageLabel = $age === null ? 'unknown' : round($age / 86400, 1) . 'd';
        $this->response->header('X-Cache-Optimizer', 'thread-' . $ageLabel);
    }
    
    /**
     * Set cache headers for forum listing pages (no SQL queries)
     */
    protected function setForumCacheHeaders($forumId)
    {
        // Check if this is Windows News forum (ID 4) without SQL
        $extendedCacheNodes = $this->getExtendedCacheNodes();
        $isExtendedCache = in_array((int) $forumId, $extendedCacheNodes, true);

        // Forum listings get different cache based on forum ID
        if ($isExtendedCache) {
            $cacheTime = $this->options->wfCacheOptimizer_windowsNews ?? 3600;
            $edgeCache = $this->options->wfCacheOptimizer_windowsNewsEdgeCache ?? 7200;
        } else {
            $cacheTime = $this->options->wfCacheOptimizer_forums ?? 900;
            $edgeCache = $this->options->wfCacheOptimizer_forumsEdgeCache ?? 1800;
        }

        $this->setCacheControlHeaders($cacheTime, $edgeCache, "F$forumId");

        // Set Last-Modified from forum's last post date
        $forum = $this->getForum($forumId);
        if ($forum && $forum['last_post_date']) {
            $this->setLastModifiedHeader($forum['last_post_date']);
        }

        // Identify cache type for debugging
        $this->response->header('X-Cache-Optimizer', $isExtendedCache ? 'forum-extended' : 'forum');
    }
    
    /**
     * Set cache headers for homepage
     */
    protected function setHomepageCacheHeaders()
    {
        $cacheTime = $this->options->wfCacheOptimizer_homepage ?? 600;
        $edgeCache = $this->options->wfCacheOptimizer_homepageEdgeCache ?? 600;
        $this->setCacheControlHeaders($cacheTime, $edgeCache, 'H');
        
        // Identify cache type for debugging
        $this->response->header('X-Cache-Optimizer', 'homepage');
    }
    
    /**
     * Set default cache headers for unmatched pages
     */
    protected function setDefaultCacheHeaders()
    {
        $cacheTime = $this->options->wfCacheOptimizer_default ?? 900;
        $edgeCache = $this->options->wfCacheOptimizer_defaultEdgeCache ?? 1800;
        $this->setCacheControlHeaders($cacheTime, $edgeCache);
        
        // Identify cache type for debugging
        $this->response->header('X-Cache-Optimizer', 'default');
    }
    
    /**
     * Set standardized cache control headers with proper vary directives
     */
    protected function setCacheControlHeaders($maxAge, $sMaxAge, $extraTag = null)
    {
        // Set standard cache control with stale-while-revalidate for better performance
        $staleTime = min($maxAge * 2, 86400); // Allow stale content for up to 2x cache time or 24h max
        // Scale stale-if-error with TTL: short-lived pages get short stale windows,
        // long-lived content (old threads) gets longer. Min 1 hour, max 24 hours.
        $staleError = max(min($sMaxAge * 4, 86400), 3600);
        $this->response->header('Cache-Control', "public, max-age=$maxAge, s-maxage=$sMaxAge, stale-while-revalidate=$staleTime, stale-if-error=$staleError");

        // Only vary on Accept-Encoding for guest pages.
        // Logged-in users are already bypassed by Cloudflare's cookie rule
        // and setNoCacheForUser(). Vary: Cookie destroys cache hit rates
        // because even guests have unique analytics/consent cookies.
        $this->response->header('Vary', 'Accept-Encoding');

        // Set Cloudflare-specific cache control
        $this->response->header('Cloudflare-CDN-Cache-Control', "max-age=$sMaxAge, stale-while-revalidate=$staleTime, stale-if-error=$staleError");

        // Add LiteSpeed-specific cache control headers
        $this->response->header('X-LiteSpeed-Cache-Control', "public, max-age=$sMaxAge");

        // Tag for targeted purging: "public" always present, plus optional content-specific tag
        // e.g. thread 401039 gets "public, T401039" so we can purge just that thread
        $tag = $extraTag ? "public, $extraTag" : 'public';
        $this->response->header('X-LiteSpeed-Tag', $tag);

        // Tell LiteSpeed to create separate cache entries per style/variation/language cookie.
        // Without this, all guests share one cache entry regardless of dark/light preference.
        $this->response->header('X-LiteSpeed-Vary', 'cookie=xf_style_variation, cookie=xf_style_id, cookie=xf_language_id');
    }

    protected function getCacheDuration($optionId, $default)
    {
        return max(0, (int) ($this->options->{$optionId} ?? $default));
    }

    protected function getCachePair($cacheOptionId, $cacheDefault, $edgeOptionId, $edgeDefault)
    {
        $cacheTime = $this->getCacheDuration($cacheOptionId, $cacheDefault);
        $edgeCache = $this->getCacheDuration($edgeOptionId, $edgeDefault);

        return [$cacheTime, $edgeCache];
    }

    /**
     * Set Last-Modified header from a Unix timestamp.
     * Replaces LiteSpeed's bogus "now" value with the actual content timestamp.
     */
    protected function setLastModifiedHeader($timestamp)
    {
        if ($timestamp && $timestamp > 0) {
            $this->response->header('Last-Modified', gmdate('D, d M Y H:i:s', $timestamp) . ' GMT');
        }
    }

    /**
     * Get the list of node IDs that should receive extended cache times
     * @return array
     */
    protected function getExtendedCacheNodes()
    {
        if ($this->extendedCacheNodes === null) {
            $nodeString = $this->options->wfCacheOptimizer_extendedCacheNodes ?? '4';
            $nodes = explode(',', $nodeString);
            $nodes = array_map('trim', $nodes);
            $nodes = array_map('intval', $nodes);
            $this->extendedCacheNodes = array_filter($nodes);
        }
        return $this->extendedCacheNodes;
    }
    
    /**
     * Clear existing cache headers
     */
    protected function clearCacheHeaders()
    {
        $this->response->removeHeader('Cache-Control');
        $this->response->removeHeader('Pragma');
        $this->response->removeHeader('Expires');
        $this->response->removeHeader('Last-Modified');
        $this->response->removeHeader('X-LiteSpeed-Cache-Control');
        $this->response->removeHeader('X-LiteSpeed-Tag');
        $this->response->removeHeader('Cloudflare-CDN-Cache-Control');

        // Also use PHP's header_remove for extra assurance
        if (!headers_sent()) {
            @header_remove('Cache-Control');
            @header_remove('Pragma');
            @header_remove('Expires');
            @header_remove('Last-Modified');
            @header_remove('X-LiteSpeed-Cache-Control');
            @header_remove('X-LiteSpeed-Tag');
            @header_remove('Cloudflare-CDN-Cache-Control');
        }
    }
    
    /**
     * Check if current route is authentication-related
     */
    protected function isAuthenticationRoute($routePath)
    {
        $authRoutes = [
            'login',
            'logout',
            'register',
            'lost-password',
            'two-step',
            'oauth2',
            'account',
            'conversations',
            'direct-messages',
            'find-threads',
            'watched',
            'email-stop',
            'misc/accept-terms',
            'misc/contact',
            'misc/style',
            'search/auto-complete'
        ];

        foreach ($authRoutes as $route) {
            if (strpos($routePath, $route) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fetch a row via cache-first, DB-fallback pattern.
     * Caches successful results in Redis for 600s to avoid per-request DB hits
     * for Last-Modified and age-tier calculations. LiveNewsCacheInvalidator
     * deletes the wf_co:thread:* / wf_co:forum:* keys on save to keep the
     * Last-Modified header in sync.
     */
    protected function cachedFetchRow($cacheKey, $sql, $params)
    {
        $cache = $this->app->cache();
        if ($cache) {
            $result = $cache->fetch($cacheKey);
            if ($result !== false) {
                return $result;
            }
        }

        try {
            $row = \XF::db()->fetchRow($sql, $params);
        } catch (\Exception $e) {
            \XF::logError('CacheOptimizer: Query failed (' . $cacheKey . '): ' . $e->getMessage());
            return null;
        }

        if ($row && $cache) {
            $cache->save($cacheKey, $row, 600);
        }

        return $row ?: null;
    }

    protected function getThread($threadId)
    {
        return $this->cachedFetchRow(
            "wf_co:thread:{$threadId}",
            'SELECT thread_id, node_id, last_post_date FROM xf_thread WHERE thread_id = ?',
            $threadId
        );
    }

    protected function getForum($forumId)
    {
        return $this->cachedFetchRow(
            "wf_co:forum:{$forumId}",
            'SELECT node_id, last_post_date FROM xf_forum WHERE node_id = ?',
            $forumId
        );
    }

    protected function getMediaItem($mediaId)
    {
        return $this->cachedFetchRow(
            "wf_co:media:{$mediaId}",
            'SELECT media_id, GREATEST(media_date, COALESCE(last_edit_date, 0), COALESCE(last_comment_date, 0)) AS last_modified FROM xf_mg_media_item WHERE media_id = ?',
            $mediaId
        );
    }

    protected function getAlbum($albumId)
    {
        return $this->cachedFetchRow(
            "wf_co:album:{$albumId}",
            'SELECT album_id, GREATEST(create_date, COALESCE(last_update_date, 0), COALESCE(last_comment_date, 0)) AS last_modified FROM xf_mg_album WHERE album_id = ?',
            $albumId
        );
    }

    protected function getResource($resourceId)
    {
        return $this->cachedFetchRow(
            "wf_co:resource:{$resourceId}",
            'SELECT resource_id, last_update FROM xf_rm_resource WHERE resource_id = ?',
            $resourceId
        );
    }

    protected function getResourceCategory($categoryId)
    {
        return $this->cachedFetchRow(
            "wf_co:rcat:{$categoryId}",
            'SELECT resource_category_id, last_update FROM xf_rm_category WHERE resource_category_id = ?',
            $categoryId
        );
    }

    protected function getMember($userId)
    {
        return $this->cachedFetchRow(
            "wf_co:member:{$userId}",
            'SELECT u.user_id, GREATEST(u.last_activity, COALESCE(u.avatar_date, 0), COALESCE(u.username_date, 0), COALESCE(p.banner_date, 0)) AS last_modified FROM xf_user u LEFT JOIN xf_user_profile p ON u.user_id = p.user_id WHERE u.user_id = ?',
            $userId
        );
    }

    protected function getTag($tagSlug)
    {
        $cacheSlug = preg_replace('/[^a-z0-9-]/i', '', (string) $tagSlug);
        return $this->cachedFetchRow(
            "wf_co:tag:{$cacheSlug}",
            'SELECT tag_id, last_use_date FROM xf_tag WHERE tag_url = ?',
            $tagSlug
        );
    }

    /**
     * Generic age-tiered cache headers for content with a last-modified timestamp.
     * Reuses the same threshold options as threads, with non-extended durations.
     * No "extended cache nodes" concept for XFMG/XFRM content.
     *
     * @param int|null $lastModified Unix timestamp of last content update
     * @param string $contentTag LiteSpeed purge tag (e.g. "M123", "A45", "R7")
     * @param string $contentLabel X-Cache-Optimizer label prefix (e.g. "media", "album", "resource")
     */
    protected function setAgeTieredCacheHeaders($lastModified, $contentTag, $contentLabel)
    {
        $age = ($lastModified && $lastModified > 0) ? (time() - $lastModified) : null;

        $threshold1Day = $this->options->wfCacheOptimizer_ageThreshold1Day ?? 86400;
        $threshold7Days = $this->options->wfCacheOptimizer_ageThreshold7Days ?? 604800;
        $threshold30Days = $this->options->wfCacheOptimizer_ageThreshold30Days ?? 2592000;
        $ancientThreshold = $this->options->wfCacheOptimizer_ancientThreshold ?? 315360000;

        if ($age === null) {
            [$cacheTime, $edgeCache] = $this->getCachePair('wfCacheOptimizer_thread7Days', 86400, 'wfCacheOptimizer_thread7DaysEdgeCache', 259200);
        } elseif ($age > $ancientThreshold) {
            [$cacheTime, $edgeCache] = $this->getCachePair('wfCacheOptimizer_ancientCache', 31536000, 'wfCacheOptimizer_ancientCacheEdgeCache', 31536000);
        } elseif ($age < $threshold1Day) {
            [$cacheTime, $edgeCache] = $this->getCachePair('wfCacheOptimizer_threadFresh', 600, 'wfCacheOptimizer_threadFreshEdgeCache', 1800);
        } elseif ($age < $threshold7Days) {
            [$cacheTime, $edgeCache] = $this->getCachePair('wfCacheOptimizer_thread1Day', 7200, 'wfCacheOptimizer_thread1DayEdgeCache', 21600);
        } elseif ($age < $threshold30Days) {
            [$cacheTime, $edgeCache] = $this->getCachePair('wfCacheOptimizer_thread7Days', 86400, 'wfCacheOptimizer_thread7DaysEdgeCache', 259200);
        } else {
            [$cacheTime, $edgeCache] = $this->getCachePair('wfCacheOptimizer_thread30Days', 604800, 'wfCacheOptimizer_thread30DaysEdgeCache', 2592000);
        }

        $this->setCacheControlHeaders($cacheTime, $edgeCache, $contentTag);

        if ($lastModified && $lastModified > 0) {
            $this->setLastModifiedHeader($lastModified);
        }

        $ageLabel = $age === null ? 'unknown' : round($age / 86400, 1) . 'd';
        $this->response->header('X-Cache-Optimizer', $contentLabel . '-' . $ageLabel);
    }

    /**
     * Set cache headers for XFMG media item pages (age-tiered)
     */
    protected function setMediaCacheHeaders($mediaId)
    {
        $media = $this->getMediaItem($mediaId);
        $lastModified = $media ? $media['last_modified'] : null;
        $this->setAgeTieredCacheHeaders($lastModified, "M$mediaId", 'media');
    }

    /**
     * Set cache headers for XFMG album pages (age-tiered)
     */
    protected function setAlbumCacheHeaders($albumId)
    {
        $album = $this->getAlbum($albumId);
        $lastModified = $album ? $album['last_modified'] : null;
        $this->setAgeTieredCacheHeaders($lastModified, "A$albumId", 'album');
    }

    /**
     * Set cache headers for XFRM resource pages (age-tiered)
     */
    protected function setResourceCacheHeaders($resourceId)
    {
        $resource = $this->getResource($resourceId);
        $lastModified = $resource ? $resource['last_update'] : null;
        $this->setAgeTieredCacheHeaders($lastModified, "R$resourceId", 'resource');
    }

    /**
     * Set cache headers for XFMG category listings (flat TTL, like forums)
     * No Last-Modified — xf_mg_category has no date columns.
     */
    protected function setMediaCategoryCacheHeaders()
    {
        $cacheTime = $this->options->wfCacheOptimizer_forums ?? 900;
        $edgeCache = $this->options->wfCacheOptimizer_forumsEdgeCache ?? 1800;
        $this->setCacheControlHeaders($cacheTime, $edgeCache);

        $this->response->header('X-Cache-Optimizer', 'media-category');
    }

    /**
     * Set cache headers for XFRM category listings (flat TTL, like forums)
     * Sets Last-Modified from xf_rm_category.last_update if available.
     */
    protected function setResourceCategoryCacheHeaders($categoryId)
    {
        $cacheTime = $this->options->wfCacheOptimizer_forums ?? 900;
        $edgeCache = $this->options->wfCacheOptimizer_forumsEdgeCache ?? 1800;
        $this->setCacheControlHeaders($cacheTime, $edgeCache);

        $category = $this->getResourceCategory($categoryId);
        if ($category && $category['last_update']) {
            $this->setLastModifiedHeader($category['last_update']);
        }

        $this->response->header('X-Cache-Optimizer', 'resource-category');
    }

    /**
     * Set cache headers for member profile pages.
     * Uses forum-level TTLs + Last-Modified from the most recent profile-visible change.
     */
    protected function setMemberCacheHeaders($userId)
    {
        $cacheTime = $this->options->wfCacheOptimizer_forums ?? 900;
        $edgeCache = $this->options->wfCacheOptimizer_forumsEdgeCache ?? 1800;
        $this->setCacheControlHeaders($cacheTime, $edgeCache);

        $member = $this->getMember($userId);
        if ($member && $member['last_modified']) {
            $this->setLastModifiedHeader($member['last_modified']);
        }

        $this->response->header('X-Cache-Optimizer', 'member');
    }

    /**
     * Set cache headers for a specific tag page.
     * Uses forum-level TTLs + Last-Modified from xf_tag.last_use_date.
     */
    protected function setTagCacheHeaders($tagSlug)
    {
        $cacheTime = $this->options->wfCacheOptimizer_forums ?? 900;
        $edgeCache = $this->options->wfCacheOptimizer_forumsEdgeCache ?? 1800;
        $this->setCacheControlHeaders($cacheTime, $edgeCache);

        $tag = $this->getTag($tagSlug);
        if ($tag && $tag['last_use_date']) {
            $this->setLastModifiedHeader($tag['last_use_date']);
        }

        $this->response->header('X-Cache-Optimizer', 'tag');
    }

    /**
     * Set cache headers for listing/index pages (forum-level TTLs).
     * Used for media index, resource index, member profiles, tag index.
     */
    protected function setListingCacheHeaders($label)
    {
        $cacheTime = $this->options->wfCacheOptimizer_forums ?? 900;
        $edgeCache = $this->options->wfCacheOptimizer_forumsEdgeCache ?? 1800;
        $this->setCacheControlHeaders($cacheTime, $edgeCache);

        $this->response->header('X-Cache-Optimizer', $label);
    }

    /**
     * Set cache headers for what's-new pages (homepage-level TTLs).
     * Changes rapidly — same cadence as homepage.
     */
    protected function setWhatsNewCacheHeaders()
    {
        $cacheTime = $this->options->wfCacheOptimizer_homepage ?? 600;
        $edgeCache = $this->options->wfCacheOptimizer_homepageEdgeCache ?? 600;
        $this->setCacheControlHeaders($cacheTime, $edgeCache, 'WN');

        $this->response->header('X-Cache-Optimizer', 'whats-new');
    }

    /**
     * Set cache headers for static/rarely-changing pages (help, admin-created pages).
     * 1 day browser / 7 days edge.
     */
    protected function setStaticPageCacheHeaders($label)
    {
        $cacheTime = 86400;
        $edgeCache = 604800;
        $this->setCacheControlHeaders($cacheTime, $edgeCache);

        $this->response->header('X-Cache-Optimizer', $label);
    }

    /**
     * Set cache headers for error responses (400/404/410)
     * Cached at edge to absorb repeated bot probes on non-existent URLs.
     * Short browser TTL so real users see fresh content if the URL becomes valid.
     * NOTE: 403 is excluded — it can be per-visitor (user bans) and must not be public-cached.
     */
    protected function setErrorCacheHeaders($httpCode)
    {
        // 30s browser / 30s edge — long enough to absorb bot 404-probe floods,
        // short enough that moderation-queued content becoming visible recovers
        // quickly even if a targeted purge is missed.
        $maxAge = 30;
        $sMaxAge = 30;
        $this->setCacheControlHeaders($maxAge, $sMaxAge);

        $this->response->header('X-Cache-Optimizer', 'error-' . $httpCode);
    }

    /**
     * Only cache guest temporary redirects whose Location is deterministic and public.
     */
    protected function isGuestCacheableRedirect($routePath)
    {
        return preg_match('#^threads/[^/]+\.\d+/latest/?$#', $routePath)
            || preg_match('#^whats-new/posts/?$#', $routePath);
    }

    /**
     * Set short cache headers for public temporary redirects such as /latest.
     */
    protected function setTemporaryRedirectCacheHeaders($httpCode, $routePath)
    {
        if (preg_match('#^whats-new/posts/?$#', $routePath)) {
            // This pointer can change quickly on an active site.
            $maxAge = 30;
            $sMaxAge = 120;
        } else {
            // Thread /latest redirects are stable enough to collapse crawler traffic,
            // but still short enough to follow new replies soon.
            $maxAge = 300;
            $sMaxAge = 1800;
        }

        $this->setCacheControlHeaders($maxAge, $sMaxAge);
        $this->response->header('X-Cache-Optimizer', 'redirect-temp-' . $httpCode);
    }

    /**
     * True when this response's Location points back at the request's own URL — a
     * self-redirect that, if publicly cached, becomes an infinite loop (the cache
     * strips the Set-Cookie that would otherwise break it on the next hop).
     *
     * Compares scheme + host + path + query. The ONE cross-scheme case that does NOT
     * loop — a genuine http->https upgrade (insecure request, https Location, same
     * host+path) — is excluded so it stays cacheable; a canonical redirect to a
     * DIFFERENT path also differs and stays cacheable.
     */
    protected function isSelfRedirect()
    {
        $location = $this->response->header('Location');
        if (!$location || !is_string($location)) {
            return false;
        }

        $request   = $this->app->request();
        $reqHost   = (string) $request->getHost();
        $reqUri    = (string) $request->getRequestUri();   // path + query
        $reqSecure = $request->isSecure();

        $parts = parse_url(strtok($location, '#'));         // drop any #fragment
        if ($parts === false) {
            return false;
        }
        $locHost   = $parts['host'] ?? $reqHost;            // relative Location => same host
        $locScheme = $parts['scheme'] ?? ($reqSecure ? 'https' : 'http');
        $locUri    = ($parts['path'] ?? '/')
                   . (isset($parts['query']) ? '?' . $parts['query'] : '');

        // CRITICAL: getHost() returns HTTP_HOST verbatim, which for an h2/h3 request
        // carries the port (windowsforum.com:443 — the proxy synthesizes HTTP_HOST as
        // host[:port] from :authority) while a boardUrl-built Location is port-less.
        // Strip the port from BOTH hosts (handles host:port and [ipv6]:port) so the
        // compare is not silently defeated by it — without this, every h2 self-redirect
        // is mis-classified as "different host" and wrongly cached.
        $stripPort = static function ($h) {
            $h = (string) $h;
            if (isset($h[0]) && $h[0] === '[') {            // [ipv6] or [ipv6]:port
                $end = strpos($h, ']');
                return $end !== false ? substr($h, 0, $end + 1) : $h;
            }
            $colon = strpos($h, ':');                       // host:port (single colon)
            return ($colon !== false && strpos($h, ':', $colon + 1) === false)
                ? substr($h, 0, $colon) : $h;               // bare ipv6 (many colons) left as-is
        };
        $reqHost = $stripPort($reqHost);
        $locHost = $stripPort($locHost);

        // Compare path+query EXACTLY (only empty -> "/"); a trailing-slash canonical
        // redirect (/foo/ -> /foo) is a DIFFERENT url, not a self-redirect, so it must
        // stay cacheable.
        $reqUri = ($reqUri === '') ? '/' : $reqUri;
        $locUri = ($locUri === '') ? '/' : $locUri;

        if (strcasecmp($locHost, $reqHost) !== 0) {
            return false;                                   // different host: not a loop
        }
        if ($locUri !== $reqUri) {
            return false;                                   // different path/query: not a loop
        }
        // Same host + path + query. It is a self-redirect loop UNLESS it is the one
        // benign cross-scheme case: an insecure request upgraded to https (different
        // cache key, so it does not loop). Everything else (https->https, http->http)
        // loops and must not be shared-cached.
        $isHttpToHttpsUpgrade = (!$reqSecure && $locScheme === 'https');
        return !$isHttpToHttpsUpgrade;
    }

    /**
     * Set cache headers for permanent redirects (301/308)
     * These are immutable and can be cached aggressively without a DB query.
     */
    protected function setRedirectCacheHeaders($httpCode)
    {
        // Cache permanent redirects for 1 day browser / 7 days edge
        $maxAge = 86400;
        $sMaxAge = 604800;
        $this->setCacheControlHeaders($maxAge, $sMaxAge);

        $this->response->header('X-Cache-Optimizer', 'redirect-' . $httpCode);
    }

    /**
     * Keep contextual temporary redirects out of shared caches.
     */
    protected function setNoCacheForRedirect($httpCode)
    {
        $this->response->header('Cache-Control', 'private, no-cache, no-store, must-revalidate, max-age=0');
        $this->response->header('Pragma', 'no-cache');
        $this->response->header('Expires', '0');
        $this->response->header('Vary', 'Cookie');
        $this->response->header('Cloudflare-CDN-Cache-Control', 'no-cache, no-store, private');
        $this->response->header('X-LiteSpeed-Cache-Control', 'no-cache');
        $this->response->header('X-LiteSpeed-Tag', 'redirect-private');
        $this->response->header('X-Cache-Optimizer', 'no-cache-redirect-' . $httpCode);
    }

    /**
     * Set no-cache headers for authentication pages
     */
    protected function setNoCacheForAuthPages()
    {
        // Force no-cache for auth pages
        $this->response->header('Cache-Control', 'private, no-cache, no-store, must-revalidate, max-age=0');
        $this->response->header('Pragma', 'no-cache');
        $this->response->header('Expires', '0');

        // Vary by all cookies
        $this->response->header('Vary', 'Cookie');

        // Add Cloudflare-specific header to prevent edge caching
        $this->response->header('Cloudflare-CDN-Cache-Control', 'no-cache, no-store, private');

        // Add LiteSpeed-specific headers to bypass cache for auth pages
        $this->response->header('X-LiteSpeed-Cache-Control', 'no-cache');
        $this->response->header('X-LiteSpeed-Tag', 'auth');

        // Identify that these headers came from us
        $this->response->header('X-Cache-Optimizer', 'no-cache-auth');
    }

    protected function setCacheHeadersForUnfurlImage()
    {
        $this->response->removeHeader('Pragma');
        $this->response->removeHeader('Expires');
        $this->response->removeHeader('X-LiteSpeed-Cache-Control');
        $this->response->removeHeader('X-LiteSpeed-Tag');

        if (!headers_sent()) {
            @header_remove('Pragma');
            @header_remove('Expires');
            @header_remove('X-LiteSpeed-Cache-Control');
            @header_remove('X-LiteSpeed-Tag');
        }

        $this->response->header('Cache-Control', 'public, max-age=604800, immutable');
        $this->response->header('Cloudflare-CDN-Cache-Control', 'public, max-age=604800');
        $this->response->header('X-LiteSpeed-Cache-Control', 'no-cache');
        $this->response->header('X-LiteSpeed-Tag', 'unfurl-image');
        $this->response->header('X-Cache-Optimizer', 'unfurl-image-derivative');
        $this->response->removeCookie($this->response->getCookiePrefix() . 'csrf');
    }

    /**
     * Detect a rendered XenForo error page (Reply\Error / Reply\Exception).
     *
     * These render through the normal page container with HTTP 200, so the
     * status code alone can't distinguish them from a real thread/forum page.
     * The page container sets data-template="error" on the root <html> element,
     * which lands in the first ~120 bytes of the body. We only inspect a short
     * prefix to keep this O(1) on every guest response.
     */
    protected function isErrorPage()
    {
        $body = $this->response->body();
        if (!is_string($body) || $body === '') {
            return false;
        }

        return strpos(substr($body, 0, 1024), 'data-template="error"') !== false;
    }

    /**
     * Force no-cache for error pages so a transient failure is never pinned
     * at the CDN/origin caches. Mirrors setNoCacheForAuthPages() but public
     * (the body is identical for all guests, so no Vary: Cookie is needed).
     */
    protected function setNoCacheForError()
    {
        $this->response->header('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0');
        $this->response->header('Pragma', 'no-cache');
        $this->response->header('Expires', '0');

        $this->response->header('Cloudflare-CDN-Cache-Control', 'no-cache, no-store');
        $this->response->header('X-LiteSpeed-Cache-Control', 'no-cache');
        $this->response->header('X-LiteSpeed-Tag', 'error');

        $this->response->header('X-Cache-Optimizer', 'no-cache-error');
    }

    /*-------------------------------------------------------------------------
      Age-tiered Redis (DB1) page-cache STORE lifetime + per-thread purge index

      XF's guest page cache uses one flat store TTL ($config['pageCache']
      ['lifetime'] = 600). For cold long-tail threads that is far shorter than
      the days-to-years the edge already holds them (see setThreadCacheHeaders),
      so a crawler hitting a cold thread after a CF-edge eviction forces a full
      ~150ms render at the origin instead of a ~3ms pagecache.php PREBHIT.

      applyPageCacheTtl() extends the *store* TTL modestly for OLD threads
      (short tiers, capped at the 1h ceiling to bound Redis DB1 RAM), keeping
      FRESH threads at the 600s base so replies still surface quickly. It also
      registers the resulting cache key in a per-thread Redis set so a
      reply/edit/delete can purge every cached variant of the thread
      (purgePageCacheForThread(), called from LiveNewsCacheInvalidator on every
      thread write) — that purge is what makes the extended store TTL safe with
      no staleness regression on origin MISS. The store tiers are intentionally
      decoupled from (and much shorter than) the CF/browser header tiers.
    --------------------------------------------------------------------------*/

    /** Hard ceiling on the page-cache store TTL; a missed purge self-heals within this. */
    const PAGE_CACHE_MAX_STORE_LIFETIME = 3600; // 1 hour (short-tier ceiling, bounds DB1 RAM)

    /** Redis DB index that backs the guest page cache (config: cache.context.page). */
    const PAGE_CACHE_REDIS_DB = 1;

    /**
     * Wired via $config['pageCache']['onSetup']. Runs at PageCache build time
     * (pre-dispatch) with the instance, so setLifetime() lands before
     * saveToCache(). Must never return false (that disables caching) and must
     * never throw into the request path.
     */
    public static function applyPageCacheTtl(\XF\PageCache $pageCache): void
    {
        try {
            $base = (int) $pageCache->getLifetime();
            $routePath = $pageCache->getRequest()->getRoutePath();
            $threadId = self::parseThreadId($routePath);
            $ttl = $threadId
                ? self::threadPageStoreLifetime($threadId, $base)
                : self::publicRouteStoreLifetime($routePath, $base);

            if ($ttl <= $base) {
                return; // keep the short default TTL
            }

            $pageCache->setLifetime($ttl);
            if ($threadId) {
                self::registerThreadPageKey($threadId, $pageCache->getCacheId(), $ttl);
            }
        } catch (\Throwable $e) {
            // Caching is best-effort; never break the request on optimizer logic.
        }
    }

    protected static function parseThreadId($routePath)
    {
        if (preg_match('#^threads/[^/]+\.(\d+)(?:/|$)#', (string) $routePath, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    /**
     * Store guest public listings longer than XF's flat 600s default so hot
     * crawler/guest routes can be served from pre-bootstrap pagecache.php more
     * often. These routes already receive public cache headers from this add-on.
     */
    protected static function publicRouteStoreLifetime($routePath, $base)
    {
        $routePath = trim((string) $routePath, '/');
        if ($routePath === '') {
            return $base;
        }

        $prefix = strtok($routePath, '/') ?: $routePath;
        switch ($prefix) {
            case 'tags':
            case 'forums':
            case 'members':
            case 'media':
            case 'resources':
            case 'featured':
            case 'recent-activity':
                $ttl = 1800;
                break;

            case 'help':
            case 'pages':
                $ttl = self::PAGE_CACHE_MAX_STORE_LIFETIME;
                break;

            case 'whats-new':
            case 'find-new':
            case 'find-threads':
                $ttl = 900;
                break;

            default:
                return $base;
        }

        return max($base, min($ttl, self::PAGE_CACHE_MAX_STORE_LIFETIME));
    }

    /**
     * Age-tiered store TTL for a thread page. Kept deliberately short (<= the 1h
     * ceiling) to bound Redis DB1 memory; decoupled on purpose from the longer
     * CF/browser header tiers in setThreadCacheHeaders. Floored at the base and
     * capped. Fresh threads return the base so active discussions keep refreshing.
     */
    protected static function threadPageStoreLifetime($threadId, $base)
    {
        $row = self::fetchThreadRowStatic($threadId);
        $age = ($row && $row['last_post_date']) ? (\XF::$time - (int) $row['last_post_date']) : null;
        if ($age === null) {
            return $base; // cannot determine age — stay safe at the short base
        }

        $options = \XF::options();
        $t1Day = (int) ($options->wfCacheOptimizer_ageThreshold1Day ?? 86400);
        $t7Days = (int) ($options->wfCacheOptimizer_ageThreshold7Days ?? 604800);
        $t30Days = (int) ($options->wfCacheOptimizer_ageThreshold30Days ?? 2592000);

        if ($age < $t1Day) {
            $ttl = $base;   // fresh (<24h): unchanged (600s base)
        } elseif ($age < $t7Days) {
            $ttl = 1800;    // 1-7d: 30m
        } elseif ($age < $t30Days) {
            $ttl = 3600;    // 7-30d: 1h
        } else {
            $ttl = self::PAGE_CACHE_MAX_STORE_LIFETIME; // 30d+ / ancient: 1h (ceiling)
        }

        return max($base, min($ttl, self::PAGE_CACHE_MAX_STORE_LIFETIME));
    }

    /**
     * Single-row, cache-first thread fetch sharing the exact key + query of the
     * instance getThread() so both paths reuse one cached row (and one
     * LiveNewsCacheInvalidator flush keeps both in sync).
     */
    protected static function fetchThreadRowStatic($threadId)
    {
        $cache = \XF::app()->cache();
        $key = "wf_co:thread:{$threadId}";

        if ($cache) {
            $row = $cache->fetch($key);
            if ($row !== false) {
                return $row ?: null;
            }
        }

        try {
            $row = \XF::db()->fetchRow(
                'SELECT thread_id, node_id, last_post_date FROM xf_thread WHERE thread_id = ?',
                $threadId
            );
        } catch (\Throwable $e) {
            return null;
        }

        if ($row && $cache) {
            $cache->save($key, $row, 600);
        }

        return $row ?: null;
    }

    /**
     * Record a cached thread-page key in the thread's purge index (DB1 set) so
     * purgePageCacheForThread() can drop every cached variant (page N, style,
     * variation, language, consent) on the next write to the thread.
     */
    protected static function registerThreadPageKey($threadId, $cacheId, $ttl)
    {
        if (!$cacheId) {
            return;
        }

        $redis = \WindowsForum\SharedRedis::raw();
        if (!$redis) {
            return;
        }

        try {
            $redis->select(self::PAGE_CACHE_REDIS_DB);
            $idx = 'wf_pcidx:t:' . (int) $threadId;
            $redis->sAdd($idx, $cacheId);
            // Outlive the page entries it tracks so a later reply still finds them.
            $redis->expire($idx, (int) $ttl + 86400);
        } catch (\Throwable $e) {
        } finally {
            try { $redis->select(0); } catch (\Throwable $e) {}
        }
    }

    /**
     * Purge every cached guest page variant of a thread from the Redis page
     * cache (DB1). Called from LiveNewsCacheInvalidator on every thread/post
     * save/delete so an extended store TTL never serves a stale page after a
     * reply. The raw key is 'xf:' + cacheId, matching pagecache.php's reader.
     */
    public static function purgePageCacheForThread($threadId)
    {
        $threadId = (int) $threadId;
        if (!$threadId) {
            return;
        }

        $redis = \WindowsForum\SharedRedis::raw();
        if (!$redis) {
            return;
        }

        try {
            $redis->select(self::PAGE_CACHE_REDIS_DB);
            $idx = 'wf_pcidx:t:' . $threadId;
            $cacheIds = $redis->sMembers($idx);
            if ($cacheIds) {
                $rawKeys = [];
                foreach ($cacheIds as $cacheId) {
                    $rawKeys[] = 'xf:' . $cacheId;
                }
                $redis->del($rawKeys);
            }
            $redis->del($idx);
        } catch (\Throwable $e) {
        } finally {
            try { $redis->select(0); } catch (\Throwable $e) {}
        }
    }
}
