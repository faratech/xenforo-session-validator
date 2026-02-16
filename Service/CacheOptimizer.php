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

        // Clear any existing cache headers
        $this->clearCacheHeaders();

        // Always set no-cache for authentication-related pages — must run before
        // error code check because a 403 on /register/ or /login/ (IP ban) is
        // per-visitor and must never be publicly cached.
        if ($this->isAuthenticationRoute($routePath)) {
            $this->setNoCacheForAuthPages();
            return;
        }

        // 301/308 permanent redirects are highly cacheable (no DB query needed)
        $httpCode = $this->response->httpCode();
        if ($httpCode === 301 || $httpCode === 308) {
            $this->setRedirectCacheHeaders($httpCode);
            return;
        }

        // Check if user is authenticated — must run BEFORE error code check
        // because a banned user's 403 is per-visitor and must never be public-cached.
        if ($visitor->user_id) {
            $this->setNoCacheForUser();
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
        $isExtendedCache = $nodeId && in_array($nodeId, $extendedCacheNodes);

        // Determine cache times based on age
        if ($age === null) {
            // Cannot determine age, use moderate caching
            $cacheTime = $isExtendedCache
                ? ($this->options->wfCacheOptimizer_extendedThread7Days ?? 604800)
                : ($this->options->wfCacheOptimizer_thread7Days ?? 86400);
            $edgeCache = $cacheTime * 3;
        } elseif ($age > $ancientThreshold) {
            // Ancient content (> 10 years)
            $cacheTime = $this->options->wfCacheOptimizer_ancientCache ?? 31536000; // 1 year
            $edgeCache = $cacheTime;
        } elseif ($age < $threshold1Day) {
            // Fresh content (< 24 hours)
            $cacheTime = $isExtendedCache
                ? ($this->options->wfCacheOptimizer_extendedThreadFresh ?? 3600)
                : ($this->options->wfCacheOptimizer_threadFresh ?? 600);
            $edgeCache = $cacheTime * 3;
        } elseif ($age < $threshold7Days) {
            // Recent content (1-7 days)
            $cacheTime = $isExtendedCache
                ? ($this->options->wfCacheOptimizer_extendedThread1Day ?? 86400)
                : ($this->options->wfCacheOptimizer_thread1Day ?? 7200);
            $edgeCache = $cacheTime * 3;
        } elseif ($age < $threshold30Days) {
            // Older content (7-30 days)
            $cacheTime = $isExtendedCache
                ? ($this->options->wfCacheOptimizer_extendedThread7Days ?? 604800)
                : ($this->options->wfCacheOptimizer_thread7Days ?? 86400);
            $edgeCache = $cacheTime * 3;
        } else {
            // Archived content (30+ days)
            $cacheTime = $isExtendedCache
                ? ($this->options->wfCacheOptimizer_extendedThread30Days ?? 2592000)
                : ($this->options->wfCacheOptimizer_thread30Days ?? 604800);
            $edgeCache = $cacheTime * 3;
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
        $isExtendedCache = in_array($forumId, $extendedCacheNodes);

        // Forum listings get different cache based on forum ID
        if ($isExtendedCache) {
            $cacheTime = $this->options->wfCacheOptimizer_windowsNews ?? 3600;
            $edgeCache = $this->options->wfCacheOptimizer_windowsNewsEdgeCache ?? 7200;
        } else {
            $cacheTime = $this->options->wfCacheOptimizer_forums ?? 900;
            $edgeCache = $this->options->wfCacheOptimizer_forumsEdgeCache ?? 1800;
        }

        $this->setCacheControlHeaders($cacheTime, $edgeCache);

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
        $this->setCacheControlHeaders($cacheTime, $edgeCache);
        
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
        $staleError = max($staleTime, 86400); // Serve stale on 5xx for at least 24h — prevents origin blips from reaching users
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
            'account/two-step',
            'account/security',
            'account/connected-accounts'
        ];

        foreach ($authRoutes as $route) {
            if (strpos($routePath, $route) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fetch thread entity (single query for age + node ID)
     * @param int $threadId
     * @return \XF\Entity\Thread|null
     */
    protected function getThread($threadId)
    {
        try {
            return \XF::db()->fetchRow(
                'SELECT thread_id, node_id, last_post_date FROM xf_thread WHERE thread_id = ?',
                $threadId
            );
        } catch (\Exception $e) {
            \XF::logError('CacheOptimizer: Failed to get thread: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch forum data (single PK lookup for last_post_date)
     */
    protected function getForum($forumId)
    {
        try {
            return \XF::db()->fetchRow(
                'SELECT node_id, last_post_date FROM xf_forum WHERE node_id = ?',
                $forumId
            );
        } catch (\Exception $e) {
            \XF::logError('CacheOptimizer: Failed to get forum: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch XFMG media item with computed last_modified timestamp
     */
    protected function getMediaItem($mediaId)
    {
        try {
            return \XF::db()->fetchRow(
                'SELECT media_id, GREATEST(media_date, COALESCE(last_edit_date, 0), COALESCE(last_comment_date, 0)) AS last_modified FROM xf_mg_media_item WHERE media_id = ?',
                $mediaId
            );
        } catch (\Exception $e) {
            \XF::logError('CacheOptimizer: Failed to get media item: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch XFMG album with computed last_modified timestamp
     */
    protected function getAlbum($albumId)
    {
        try {
            return \XF::db()->fetchRow(
                'SELECT album_id, GREATEST(create_date, COALESCE(last_update_date, 0), COALESCE(last_comment_date, 0)) AS last_modified FROM xf_mg_album WHERE album_id = ?',
                $albumId
            );
        } catch (\Exception $e) {
            \XF::logError('CacheOptimizer: Failed to get album: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch XFRM resource with last_update timestamp
     */
    protected function getResource($resourceId)
    {
        try {
            return \XF::db()->fetchRow(
                'SELECT resource_id, last_update FROM xf_rm_resource WHERE resource_id = ?',
                $resourceId
            );
        } catch (\Exception $e) {
            \XF::logError('CacheOptimizer: Failed to get resource: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch XFRM category with last_update timestamp
     */
    protected function getResourceCategory($categoryId)
    {
        try {
            return \XF::db()->fetchRow(
                'SELECT resource_category_id, last_update FROM xf_rm_category WHERE resource_category_id = ?',
                $categoryId
            );
        } catch (\Exception $e) {
            \XF::logError('CacheOptimizer: Failed to get resource category: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch member data with computed last_modified from the most recent profile-visible change.
     * Joins xf_user + xf_user_profile for avatar, banner, activity, and username changes.
     */
    protected function getMember($userId)
    {
        try {
            return \XF::db()->fetchRow(
                'SELECT u.user_id, GREATEST(u.last_activity, COALESCE(u.avatar_date, 0), COALESCE(u.username_date, 0), COALESCE(p.banner_date, 0)) AS last_modified FROM xf_user u LEFT JOIN xf_user_profile p ON u.user_id = p.user_id WHERE u.user_id = ?',
                $userId
            );
        } catch (\Exception $e) {
            \XF::logError('CacheOptimizer: Failed to get member: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch tag data by URL slug (single indexed lookup for last_use_date)
     */
    protected function getTag($tagSlug)
    {
        try {
            return \XF::db()->fetchRow(
                'SELECT tag_id, last_use_date FROM xf_tag WHERE tag_url = ?',
                $tagSlug
            );
        } catch (\Exception $e) {
            \XF::logError('CacheOptimizer: Failed to get tag: ' . $e->getMessage());
            return null;
        }
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
            $cacheTime = $this->options->wfCacheOptimizer_thread7Days ?? 86400;
            $edgeCache = $cacheTime * 3;
        } elseif ($age > $ancientThreshold) {
            $cacheTime = $this->options->wfCacheOptimizer_ancientCache ?? 31536000;
            $edgeCache = $cacheTime;
        } elseif ($age < $threshold1Day) {
            $cacheTime = $this->options->wfCacheOptimizer_threadFresh ?? 600;
            $edgeCache = $cacheTime * 3;
        } elseif ($age < $threshold7Days) {
            $cacheTime = $this->options->wfCacheOptimizer_thread1Day ?? 7200;
            $edgeCache = $cacheTime * 3;
        } elseif ($age < $threshold30Days) {
            $cacheTime = $this->options->wfCacheOptimizer_thread7Days ?? 86400;
            $edgeCache = $cacheTime * 3;
        } else {
            $cacheTime = $this->options->wfCacheOptimizer_thread30Days ?? 604800;
            $edgeCache = $cacheTime * 3;
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
        $this->setCacheControlHeaders($cacheTime, $edgeCache);

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
        // 5 min browser / 1 hour edge — short enough to recover if content is created
        $maxAge = 300;
        $sMaxAge = 3600;
        $this->setCacheControlHeaders($maxAge, $sMaxAge);

        $this->response->header('X-Cache-Optimizer', 'error-' . $httpCode);
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
}