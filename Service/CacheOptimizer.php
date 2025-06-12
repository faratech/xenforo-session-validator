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
        
        // Check for cache bypass cookie (set after login/logout)
        if ($request->getCookie('cache_bypass') == '1') {
            $this->setNoCacheHeaders();
            return;
        }
        
        // If user is logged in, ensure no caching
        if ($visitor->user_id) {
            $this->setNoCacheHeaders();
            return;
        }
        
        // For guests, set cache headers based on content
        $routePath = $request->getRoutePath();
        
        // Determine the content type and set appropriate headers
        if (empty($routePath) || $routePath === '/') {
            // Homepage
            $this->setHomepageCacheHeaders();
        } elseif (preg_match('#^whats-new/?#', $routePath)) {
            // What's New section
            $this->setWhatsNewCacheHeaders();
        } elseif (preg_match('#^find-new/#', $routePath)) {
            // Find New content
            $this->setFindNewCacheHeaders();
        } elseif (preg_match('#^search/#', $routePath)) {
            // Search results
            $this->setSearchCacheHeaders();
        } elseif (preg_match('#^members/#', $routePath)) {
            // Members directory
            $this->setMembersCacheHeaders();
        } elseif (preg_match('#^help/#', $routePath)) {
            // Help pages
            $this->setHelpCacheHeaders();
        } elseif (preg_match('#^pages/.*\.(\d+)/#', $routePath, $matches)) {
            // Static pages
            $this->setPageCacheHeaders($matches[1]);
        } elseif (preg_match('#^media/#', $routePath)) {
            // Media gallery
            $this->setMediaCacheHeaders();
        } elseif (preg_match('#^resources/#', $routePath)) {
            // Resource manager
            $this->setResourcesCacheHeaders();
        } elseif (preg_match('#^threads/.*\.(\d+)/#', $routePath, $matches)) {
            // Thread pages
            $threadId = $matches[1];
            $this->setThreadCacheHeaders($threadId);
        } elseif (preg_match('#^forums/.*\.(\d+)/#', $routePath, $matches)) {
            // Forum listings
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
    protected function setNoCacheHeaders()
    {
        // Remove any existing cache headers
        $this->response->removeHeader('Cache-Control');
        $this->response->removeHeader('Pragma');
        $this->response->removeHeader('Expires');
        
        // Set headers to prevent caching
        $this->response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $this->response->header('Pragma', 'no-cache');
        $this->response->header('Expires', '0');
        $this->response->header('Vary', 'Cookie');
        
        // Only show debug headers in verbose mode
        if ($this->options->wfSessionValidator_verboseOutput) {
            $this->response->header('X-Cache-Status', 'logged-in-user');
        }
    }
    
    /**
     * Set cache headers for thread pages based on thread age
     */
    protected function setThreadCacheHeaders($threadId)
    {
        try {
            $thread = $this->app->em()->find('XF:Thread', $threadId);
            if (!$thread) {
                return;
            }
            
            $postDate = $thread->post_date;
            $nodeId = $thread->node_id;
            $currentTime = time();
            $age = $currentTime - $postDate;
            
            // Get extended cache nodes from options
            $extendedCacheNodes = $this->getExtendedCacheNodes();
            $isExtendedCache = in_array($nodeId, $extendedCacheNodes);
            
            // Get age thresholds from options
            $threshold1Day = $this->options->wfCacheOptimizer_ageThreshold1Day ?? 86400;
            $threshold7Days = $this->options->wfCacheOptimizer_ageThreshold7Days ?? 604800;
            $threshold30Days = $this->options->wfCacheOptimizer_ageThreshold30Days ?? 2592000;
            $ancientThreshold = $this->options->wfCacheOptimizer_ancientThreshold ?? 315360000;
            
            // Calculate cache duration based on age and node type
            if ($age > $ancientThreshold) { // Ancient content (e.g., 10+ years)
                $cacheTime = $this->options->wfCacheOptimizer_ancientCache ?? 31536000; // 1 year
                $edgeCache = $this->options->wfCacheOptimizer_ancientCacheEdgeCache ?? 31536000; // 1 year
                $staleTime = 2592000; // 30 days
            } elseif ($age > $threshold30Days) { // Older than 30 days
                if ($isExtendedCache) {
                    $cacheTime = $this->options->wfCacheOptimizer_extendedThread30Days ?? 2592000; // 30 days
                    $edgeCache = $this->options->wfCacheOptimizer_extendedThread30DaysEdgeCache ?? 7776000; // 90 days
                } else {
                    $cacheTime = $this->options->wfCacheOptimizer_thread30Days ?? 604800; // 7 days
                    $edgeCache = $this->options->wfCacheOptimizer_thread30DaysEdgeCache ?? 2592000; // 30 days
                }
                $staleTime = intval($cacheTime / 5);
            } elseif ($age > $threshold7Days) { // 7-30 days old
                if ($isExtendedCache) {
                    $cacheTime = $this->options->wfCacheOptimizer_extendedThread7Days ?? 604800; // 7 days
                    $edgeCache = $this->options->wfCacheOptimizer_extendedThread7DaysEdgeCache ?? 1209600; // 14 days
                } else {
                    $cacheTime = $this->options->wfCacheOptimizer_thread7Days ?? 86400; // 1 day
                    $edgeCache = $this->options->wfCacheOptimizer_thread7DaysEdgeCache ?? 259200; // 3 days
                }
                $staleTime = intval($cacheTime / 7);
            } elseif ($age > $threshold1Day) { // 1-7 days old
                if ($isExtendedCache) {
                    $cacheTime = $this->options->wfCacheOptimizer_extendedThread1Day ?? 86400; // 1 day
                    $edgeCache = $this->options->wfCacheOptimizer_extendedThread1DayEdgeCache ?? 172800; // 2 days
                } else {
                    $cacheTime = $this->options->wfCacheOptimizer_thread1Day ?? 7200; // 2 hours
                    $edgeCache = $this->options->wfCacheOptimizer_thread1DayEdgeCache ?? 21600; // 6 hours
                }
                $staleTime = intval($cacheTime / 8);
            } else { // Less than 24 hours old
                if ($isExtendedCache) {
                    $cacheTime = $this->options->wfCacheOptimizer_extendedThreadFresh ?? 3600; // 1 hour
                    $edgeCache = $this->options->wfCacheOptimizer_extendedThreadFreshEdgeCache ?? 7200; // 2 hours
                } else {
                    $cacheTime = $this->options->wfCacheOptimizer_threadFresh ?? 600; // 10 minutes
                    $edgeCache = $this->options->wfCacheOptimizer_threadFreshEdgeCache ?? 1800; // 30 minutes
                }
                $staleTime = intval($cacheTime / 2);
            }
            
            // Clear ALL cache headers to ensure ours take precedence
            $this->clearAllCacheHeaders();
            
            // Set cache headers with separate edge cache
            $this->response->header('Cache-Control', "public, max-age=$cacheTime, s-maxage=$edgeCache, stale-while-revalidate=$staleTime");
            $this->response->header('Vary', 'Cookie');
            
            // Only show debug headers in verbose mode
            if ($this->options->wfSessionValidator_verboseOutput) {
                $this->response->header('X-Cache-Age', round($age / 86400, 1) . ' days');
                $this->response->header('X-Cache-Node', $nodeId);
            }
            
            // Add last modified header
            $this->response->header('Last-Modified', gmdate('D, d M Y H:i:s', $postDate) . ' GMT');
            
        } catch (\Exception $e) {
            \XF::logError('Cache Optimizer Error: ' . $e->getMessage());
        }
    }
    
    /**
     * Set cache headers for forum listing pages
     */
    protected function setForumCacheHeaders($forumId)
    {
        try {
            $forum = $this->app->em()->find('XF:Forum', $forumId);
            if (!$forum) {
                return;
            }
            
            $nodeId = $forum->node_id;
            
            // Remove any existing cache headers to ensure ours take precedence
            $this->response->removeHeader('Cache-Control');
            $this->response->removeHeader('Pragma');
            $this->response->removeHeader('Expires');
            
            // Get extended cache nodes from options
            $extendedCacheNodes = $this->getExtendedCacheNodes();
            $isExtendedCache = in_array($nodeId, $extendedCacheNodes);
            
            // Forum listings get different cache based on node type
            if ($isExtendedCache) {
                $cacheTime = $this->options->wfCacheOptimizer_windowsNews ?? 3600;
                $edgeCache = $this->options->wfCacheOptimizer_windowsNewsEdgeCache ?? 7200;
                $staleTime = intval($cacheTime / 2);
            } else {
                $cacheTime = $this->options->wfCacheOptimizer_forums ?? 900;
                $edgeCache = $this->options->wfCacheOptimizer_forumsEdgeCache ?? 1800;
                $staleTime = intval($cacheTime / 2);
            }
            
            $this->response->header('Cache-Control', "public, max-age=$cacheTime, s-maxage=$edgeCache, stale-while-revalidate=$staleTime");
            $this->response->header('Vary', 'Cookie');
            
            // Only show debug headers in verbose mode
            if ($this->options->wfSessionValidator_verboseOutput) {
                $this->response->header('X-Cache-Type', 'forum-listing');
                $this->response->header('X-Cache-Node', $nodeId);
            }
            
        } catch (\Exception $e) {
            \XF::logError('Cache Optimizer Error: ' . $e->getMessage());
        }
    }
    
    /**
     * Set cache headers for homepage
     */
    protected function setHomepageCacheHeaders()
    {
        $this->clearCacheHeaders();
        $cacheTime = $this->options->wfCacheOptimizer_homepage ?? 600;
        $edgeCache = $this->options->wfCacheOptimizer_homepageEdgeCache ?? 600;
        $staleTime = intval($cacheTime / 2);
        $this->response->header('Cache-Control', "public, max-age=$cacheTime, s-maxage=$edgeCache, stale-while-revalidate=$staleTime");
        $this->response->header('Vary', 'Cookie');
        
        // Only show debug headers in verbose mode
        if ($this->options->wfSessionValidator_verboseOutput) {
            $this->response->header('X-Cache-Type', 'homepage');
        }
    }
    
    /**
     * Set cache headers for What's New section
     */
    protected function setWhatsNewCacheHeaders()
    {
        $this->clearAllCacheHeaders();
        $cacheTime = $this->options->wfCacheOptimizer_whatsNew ?? 300;
        $edgeCache = $this->options->wfCacheOptimizer_whatsNewEdgeCache ?? 300;
        $staleTime = intval($cacheTime / 5);
        $this->response->header('Cache-Control', "public, max-age=$cacheTime, s-maxage=$edgeCache, stale-while-revalidate=$staleTime");
        $this->response->header('Vary', 'Cookie');
        
        // Only show debug headers in verbose mode
        if ($this->options->wfSessionValidator_verboseOutput) {
            $this->response->header('X-Cache-Type', 'whats-new');
            $this->response->header('X-Cache-Optimizer', 'WindowsForum/SessionValidator');
        }
        $this->response->header('Cloudflare-CDN-Cache-Control', "max-age=$edgeCache");
    }
    
    /**
     * Set cache headers for Find New content
     */
    protected function setFindNewCacheHeaders()
    {
        $this->clearAllCacheHeaders();
        // Find New uses same settings as What's New
        $cacheTime = $this->options->wfCacheOptimizer_whatsNew ?? 300;
        $edgeCache = $this->options->wfCacheOptimizer_whatsNewEdgeCache ?? 300;
        $staleTime = intval($cacheTime / 5);
        $this->response->header('Cache-Control', "public, max-age=$cacheTime, s-maxage=$edgeCache, stale-while-revalidate=$staleTime");
        $this->response->header('Vary', 'Cookie');
        
        // Only show debug headers in verbose mode
        if ($this->options->wfSessionValidator_verboseOutput) {
            $this->response->header('X-Cache-Type', 'find-new');
            $this->response->header('X-Cache-Optimizer', 'WindowsForum/SessionValidator');
        }
        $this->response->header('Cloudflare-CDN-Cache-Control', "max-age=$edgeCache");
    }
    
    /**
     * Set cache headers for search results
     */
    protected function setSearchCacheHeaders()
    {
        $this->clearAllCacheHeaders();
        $cacheTime = $this->options->wfCacheOptimizer_search ?? 1800;
        $edgeCache = $this->options->wfCacheOptimizer_searchEdgeCache ?? 3600;
        $staleTime = intval($cacheTime / 3);
        $this->response->header('Cache-Control', "public, max-age=$cacheTime, s-maxage=$edgeCache, stale-while-revalidate=$staleTime");
        $this->response->header('Vary', 'Cookie, Accept-Encoding');
        
        // Only show debug headers in verbose mode
        if ($this->options->wfSessionValidator_verboseOutput) {
            $this->response->header('X-Cache-Type', 'search');
            $this->response->header('X-Cache-Optimizer', 'WindowsForum/SessionValidator');
        }
        $this->response->header('Cloudflare-CDN-Cache-Control', "max-age=$edgeCache");
    }
    
    /**
     * Set cache headers for members directory
     */
    protected function setMembersCacheHeaders()
    {
        $this->clearAllCacheHeaders();
        $cacheTime = $this->options->wfCacheOptimizer_members ?? 3600;
        $edgeCache = $this->options->wfCacheOptimizer_membersEdgeCache ?? 7200;
        $staleTime = intval($cacheTime / 2);
        $this->response->header('Cache-Control', "public, max-age=$cacheTime, s-maxage=$edgeCache, stale-while-revalidate=$staleTime");
        $this->response->header('Vary', 'Cookie');
        
        // Only show debug headers in verbose mode
        if ($this->options->wfSessionValidator_verboseOutput) {
            $this->response->header('X-Cache-Type', 'members');
            $this->response->header('X-Cache-Optimizer', 'WindowsForum/SessionValidator');
        }
        $this->response->header('Cloudflare-CDN-Cache-Control', "max-age=$edgeCache");
    }
    
    /**
     * Set cache headers for help pages
     */
    protected function setHelpCacheHeaders()
    {
        $this->clearAllCacheHeaders();
        $cacheTime = $this->options->wfCacheOptimizer_help ?? 604800;
        $edgeCache = $this->options->wfCacheOptimizer_helpEdgeCache ?? 604800;
        $staleTime = intval($cacheTime / 7);
        $this->response->header('Cache-Control', "public, max-age=$cacheTime, s-maxage=$edgeCache, stale-while-revalidate=$staleTime, immutable");
        $this->response->header('Vary', 'Cookie');
        
        // Only show debug headers in verbose mode
        if ($this->options->wfSessionValidator_verboseOutput) {
            $this->response->header('X-Cache-Type', 'help');
            $this->response->header('X-Cache-Optimizer', 'WindowsForum/SessionValidator');
        }
        $this->response->header('Cloudflare-CDN-Cache-Control', "max-age=$edgeCache");
    }
    
    /**
     * Set cache headers for static pages
     */
    protected function setPageCacheHeaders($pageId)
    {
        $this->clearAllCacheHeaders();
        // Static pages use help page settings since they're similarly static
        $cacheTime = $this->options->wfCacheOptimizer_help ?? 604800;
        $edgeCache = $this->options->wfCacheOptimizer_helpEdgeCache ?? 604800;
        $staleTime = intval($cacheTime / 7);
        $this->response->header('Cache-Control', "public, max-age=$cacheTime, s-maxage=$edgeCache, stale-while-revalidate=$staleTime, immutable");
        $this->response->header('Vary', 'Cookie');
        
        // Only show debug headers in verbose mode
        if ($this->options->wfSessionValidator_verboseOutput) {
            $this->response->header('X-Cache-Type', 'page');
            $this->response->header('X-Page-ID', $pageId);
            $this->response->header('X-Cache-Optimizer', 'WindowsForum/SessionValidator');
        }
        $this->response->header('Cloudflare-CDN-Cache-Control', "max-age=$edgeCache");
    }
    
    /**
     * Set cache headers for media gallery
     */
    protected function setMediaCacheHeaders()
    {
        $this->clearAllCacheHeaders();
        $cacheTime = $this->options->wfCacheOptimizer_media ?? 1800;
        $edgeCache = $this->options->wfCacheOptimizer_mediaEdgeCache ?? 3600;
        $staleTime = intval($cacheTime / 2);
        $this->response->header('Cache-Control', "public, max-age=$cacheTime, s-maxage=$edgeCache, stale-while-revalidate=$staleTime");
        $this->response->header('Vary', 'Cookie');
        
        // Only show debug headers in verbose mode
        if ($this->options->wfSessionValidator_verboseOutput) {
            $this->response->header('X-Cache-Type', 'media');
            $this->response->header('X-Cache-Optimizer', 'WindowsForum/SessionValidator');
        }
        $this->response->header('Cloudflare-CDN-Cache-Control', "max-age=$edgeCache");
    }
    
    /**
     * Set cache headers for resource manager
     */
    protected function setResourcesCacheHeaders()
    {
        $this->clearAllCacheHeaders();
        $cacheTime = $this->options->wfCacheOptimizer_resources ?? 3600;
        $edgeCache = $this->options->wfCacheOptimizer_resourcesEdgeCache ?? 7200;
        $staleTime = intval($cacheTime / 2);
        $this->response->header('Cache-Control', "public, max-age=$cacheTime, s-maxage=$edgeCache, stale-while-revalidate=$staleTime");
        $this->response->header('Vary', 'Cookie');
        
        // Only show debug headers in verbose mode
        if ($this->options->wfSessionValidator_verboseOutput) {
            $this->response->header('X-Cache-Type', 'resources');
            $this->response->header('X-Cache-Optimizer', 'WindowsForum/SessionValidator');
        }
        $this->response->header('Cloudflare-CDN-Cache-Control', "max-age=$edgeCache");
    }
    
    /**
     * Set default cache headers for unmatched pages
     */
    protected function setDefaultCacheHeaders()
    {
        $this->clearAllCacheHeaders();
        $cacheTime = $this->options->wfCacheOptimizer_default ?? 900;
        $edgeCache = $this->options->wfCacheOptimizer_defaultEdgeCache ?? 1800;
        $staleTime = intval($cacheTime / 3);
        $this->response->header('Cache-Control', "public, max-age=$cacheTime, s-maxage=$edgeCache, stale-while-revalidate=$staleTime");
        $this->response->header('Vary', 'Cookie');
        
        // Only show debug headers in verbose mode
        if ($this->options->wfSessionValidator_verboseOutput) {
            $this->response->header('X-Cache-Type', 'default');
            $this->response->header('X-Cache-Optimizer', 'WindowsForum/SessionValidator');
        }
        $this->response->header('Cloudflare-CDN-Cache-Control', "max-age=$edgeCache");
    }
    
    /**
     * Clear existing cache headers
     */
    protected function clearCacheHeaders()
    {
        $this->response->removeHeader('Cache-Control');
        $this->response->removeHeader('Pragma');
        $this->response->removeHeader('Expires');
    }
    
    /**
     * Aggressively clear ALL cache-related headers that might interfere
     */
    protected function clearAllCacheHeaders()
    {
        // Standard cache headers
        $this->response->removeHeader('Cache-Control');
        $this->response->removeHeader('Pragma');
        $this->response->removeHeader('Expires');
        $this->response->removeHeader('Last-Modified');
        $this->response->removeHeader('ETag');
        
        // Cloudflare specific headers
        $this->response->removeHeader('CF-Cache-Status');
        $this->response->removeHeader('Cloudflare-CDN-Cache-Control');
        $this->response->removeHeader('CDN-Cache-Control');
        
        // XenForo might set these
        $this->response->removeHeader('X-Accel-Expires');
        $this->response->removeHeader('X-Cache');
        $this->response->removeHeader('X-Cache-Status');
        
        // Remove any surrogate control headers
        $this->response->removeHeader('Surrogate-Control');
        $this->response->removeHeader('Surrogate-Key');
        
        // If force mode is enabled, use PHP's header_remove for extra aggression
        if ($this->options->wfCacheOptimizer_forceHeaders ?? true) {
            if (!headers_sent()) {
                @header_remove('Cache-Control');
                @header_remove('Pragma');
                @header_remove('Expires');
                @header_remove('Last-Modified');
                @header_remove('ETag');
                @header_remove('CF-Cache-Status');
                @header_remove('Cloudflare-CDN-Cache-Control');
                @header_remove('CDN-Cache-Control');
                @header_remove('X-Accel-Expires');
                @header_remove('Surrogate-Control');
            }
        }
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
     * Set a header with force option if enabled
     * @param string $name
     * @param string $value
     * @param bool $replace
     */
    protected function setHeader($name, $value, $replace = true)
    {
        // Always set through XenForo's response object
        $this->response->header($name, $value);
        
        // If force mode is enabled and headers haven't been sent, also use PHP's header()
        if (($this->options->wfCacheOptimizer_forceHeaders ?? true) && !headers_sent()) {
            @header("$name: $value", $replace);
        }
    }
}