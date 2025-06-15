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
        
        // Identify that these headers came from us
        $this->response->header('X-Cache-Optimizer', 'no-cache-user');
    }
    
    /**
     * Set cache headers for thread pages based on Last-Modified header
     */
    protected function setThreadCacheHeaders($threadId)
    {
        // Try to determine age from Last-Modified header if XenForo sends it
        $age = $this->getContentAge();
        
        // Get age thresholds from options
        $threshold1Day = $this->options->wfCacheOptimizer_ageThreshold1Day ?? 86400;
        $threshold7Days = $this->options->wfCacheOptimizer_ageThreshold7Days ?? 604800;
        $threshold30Days = $this->options->wfCacheOptimizer_ageThreshold30Days ?? 2592000;
        
        // Determine cache times based on age
        if ($age === null) {
            // No Last-Modified header, use default
            $cacheTime = $this->options->wfCacheOptimizer_thread7Days ?? 86400;
            $edgeCache = $this->options->wfCacheOptimizer_thread7DaysEdgeCache ?? 259200;
        } elseif ($age < $threshold1Day) {
            // Fresh content (< 24 hours)
            $cacheTime = $this->options->wfCacheOptimizer_threadFresh ?? 600;
            $edgeCache = $this->options->wfCacheOptimizer_threadFreshEdgeCache ?? 1800;
        } elseif ($age < $threshold7Days) {
            // Recent content (1-7 days)
            $cacheTime = $this->options->wfCacheOptimizer_thread1Day ?? 7200;
            $edgeCache = $this->options->wfCacheOptimizer_thread1DayEdgeCache ?? 21600;
        } elseif ($age < $threshold30Days) {
            // Older content (7-30 days)
            $cacheTime = $this->options->wfCacheOptimizer_thread7Days ?? 86400;
            $edgeCache = $this->options->wfCacheOptimizer_thread7DaysEdgeCache ?? 259200;
        } else {
            // Archived content (30+ days)
            $cacheTime = $this->options->wfCacheOptimizer_thread30Days ?? 604800;
            $edgeCache = $this->options->wfCacheOptimizer_thread30DaysEdgeCache ?? 2592000;
        }
        
        // Set cache headers
        $this->setCacheControlHeaders($cacheTime, $edgeCache);
        
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
        // Set standard cache control
        $this->response->header('Cache-Control', "public, max-age=$maxAge, s-maxage=$sMaxAge");
        
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
        $this->response->header('Cloudflare-CDN-Cache-Control', "max-age=$sMaxAge");
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
        
        // Also use PHP's header_remove for extra assurance
        if (!headers_sent()) {
            @header_remove('Cache-Control');
            @header_remove('Pragma');
            @header_remove('Expires');
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
        
        // Identify that these headers came from us
        $this->response->header('X-Cache-Optimizer', 'no-cache-auth');
    }
}