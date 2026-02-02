<?php

namespace WindowsForum\SessionValidator\Service;

use XF\App;
use XF\Http\Response;

class CacheOptimizer
{
    protected $app;
    protected $response;
    protected $options;
    
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
        
        // Always set no-cache for authentication-related pages
        if ($this->isAuthenticationRoute($routePath)) {
            $this->setNoCacheForAuthPages();
            return;
        }
        
        // Check if user is authenticated
        if ($visitor->user_id) {
            $this->setNoCacheForUser();
            return;
        }
        
        // For guests, set cache headers based on content
        // Determine the content type and set appropriate headers
        if (empty($routePath) || $routePath === '/') {
            // Homepage
            $this->setHomepageCacheHeaders();
        } elseif (preg_match('#^threads/[^/]+\.(\d+)(?:/|$)#', $routePath, $matches)) {
            // Thread pages - matches: threads/thread-title.123/ or threads/thread-title.123
            $threadId = $matches[1];
            $this->setThreadCacheHeaders($threadId);
        } elseif (preg_match('#^forums/[^/]+\.(\d+)(?:/|$)#', $routePath, $matches)) {
            // Forum listings - matches: forums/forum-name.45/ or forums/forum-name.45
            $forumId = $matches[1];
            $this->setForumCacheHeaders($forumId);
        } else {
            // Default for other pages
            $this->setDefaultCacheHeaders();
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
        // Try to get thread's actual age from entity data
        $age = $this->getThreadAge($threadId);

        // Get age thresholds from options
        $threshold1Day = $this->options->wfCacheOptimizer_ageThreshold1Day ?? 86400;
        $threshold7Days = $this->options->wfCacheOptimizer_ageThreshold7Days ?? 604800;
        $threshold30Days = $this->options->wfCacheOptimizer_ageThreshold30Days ?? 2592000;
        $ancientThreshold = $this->options->wfCacheOptimizer_ancientThreshold ?? 315360000; // 10 years

        // Check if this thread's forum gets extended cache times
        $nodeId = $this->getThreadNodeId($threadId);
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

        // Set cache headers
        $this->setCacheControlHeaders($cacheTime, $edgeCache);

        // Generate and set ETag for better cache validation
        $this->setETagHeader('thread', $threadId, $age);

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

        // Generate and set ETag for better cache validation
        $this->setETagHeader('forum', $forumId);

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
    protected function setCacheControlHeaders($maxAge, $sMaxAge)
    {
        // Set standard cache control with stale-while-revalidate for better performance
        $staleTime = min($maxAge * 2, 86400); // Allow stale content for up to 2x cache time or 24h max
        $this->response->header('Cache-Control', "public, max-age=$maxAge, s-maxage=$sMaxAge, stale-while-revalidate=$staleTime");

        // Get cookie configuration
        $cookiePrefix = $this->app->config('cookie')['prefix'] ?? 'xf_';

        // Build vary header with specific cookies for better cache efficiency
        // We vary on session and user cookies to ensure proper cache separation
        $varyHeaders = ['Accept-Encoding'];
        $varyCookies = [
            $cookiePrefix . 'session',
            $cookiePrefix . 'user',
            $cookiePrefix . 'admin',
            $cookiePrefix . 'install'
        ];

        // Add cookie vary directive
        $varyHeaders[] = 'Cookie';

        $this->response->header('Vary', implode(', ', $varyHeaders));

        // Set Cloudflare-specific cache control
        $this->response->header('Cloudflare-CDN-Cache-Control', "max-age=$sMaxAge, stale-while-revalidate=$staleTime");

        // Add LiteSpeed-specific cache control headers
        $this->response->header('X-LiteSpeed-Cache-Control', "public, max-age=$sMaxAge");
        $this->response->header('X-LiteSpeed-Tag', 'public');
    }
    
    
    /**
     * Get the list of node IDs that should receive extended cache times
     * @return array
     */
    protected function getExtendedCacheNodes()
    {
        $nodeString = $this->options->wfCacheOptimizer_extendedCacheNodes ?? '4';
        $nodes = explode(',', $nodeString);
        $nodes = array_map('trim', $nodes);
        $nodes = array_map('intval', $nodes);
        $nodes = array_filter($nodes); // Remove any zero values
        return $nodes;
    }
    
    /**
     * Get content age from Last-Modified header if available
     * @return int|null Age in seconds, or null if not available
     */
    protected function getContentAge()
    {
        // Check if XenForo has set a Last-Modified header
        $headers = $this->response->headers();
        if (isset($headers['Last-Modified'])) {
            $lastModified = strtotime($headers['Last-Modified']);
            if ($lastModified !== false) {
                return time() - $lastModified;
            }
        }
        
        // Alternative: Check for X-Last-Modified or other custom headers
        if (isset($headers['X-Last-Modified'])) {
            $lastModified = strtotime($headers['X-Last-Modified']);
            if ($lastModified !== false) {
                return time() - $lastModified;
            }
        }
        
        return null;
    }
    
    /**
     * Clear existing cache headers
     */
    protected function clearCacheHeaders()
    {
        $this->response->removeHeader('Cache-Control');
        $this->response->removeHeader('Pragma');
        $this->response->removeHeader('Expires');
        $this->response->removeHeader('X-LiteSpeed-Cache-Control');
        $this->response->removeHeader('X-LiteSpeed-Tag');

        // Also use PHP's header_remove for extra assurance
        if (!headers_sent()) {
            @header_remove('Cache-Control');
            @header_remove('Pragma');
            @header_remove('Expires');
            @header_remove('X-LiteSpeed-Cache-Control');
            @header_remove('X-LiteSpeed-Tag');
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
     * Generate and set ETag header for cache validation
     */
    protected function setETagHeader($type, $id, $age = null)
    {
        // Build ETag components
        $components = [
            $type,
            $id,
            $age !== null ? round($age / 3600) : 'na', // Age in hours
            \XF::$versionId // XenForo version for cache busting on upgrades
        ];

        // Generate ETag
        $etag = '"' . md5(implode('-', $components)) . '"';
        $this->response->header('ETag', $etag);

        // Check if client sent If-None-Match
        $request = $this->app->request();
        $ifNoneMatch = $request->getServer('HTTP_IF_NONE_MATCH');

        if ($ifNoneMatch === $etag) {
            // Content hasn't changed, send 304
            $this->response->httpCode(304);
            $this->response->header('X-Cache-Status', 'HIT-ETAG');
        }
    }

    /**
     * Get the actual age of a thread based on last post time
     * @param int $threadId
     * @return int|null Age in seconds, or null if not found
     */
    protected function getThreadAge($threadId)
    {
        try {
            // Try to get thread from finder to avoid direct SQL
            $finder = \XF::finder('XF:Thread');
            $thread = $finder->where('thread_id', $threadId)->fetchOne();

            if ($thread && $thread->last_post_date) {
                return time() - $thread->last_post_date;
            }
        } catch (\Exception $e) {
            // If entity system fails, fall back to null
            \XF::logError('CacheOptimizer: Failed to get thread age: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Get the node ID (forum ID) for a thread
     * @param int $threadId
     * @return int|null Node ID, or null if not found
     */
    protected function getThreadNodeId($threadId)
    {
        try {
            // Try to get thread from finder
            $finder = \XF::finder('XF:Thread');
            $thread = $finder->where('thread_id', $threadId)->fetchOne();

            if ($thread && $thread->node_id) {
                return $thread->node_id;
            }
        } catch (\Exception $e) {
            // If entity system fails, fall back to null
            \XF::logError('CacheOptimizer: Failed to get thread node: ' . $e->getMessage());
        }

        return null;
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