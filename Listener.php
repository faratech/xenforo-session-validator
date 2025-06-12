<?php

namespace WindowsForum\SessionValidator;

class Listener
{
    /**
     * Listen to the app_setup event to validate sessions early in the request cycle
     */
    public static function appSetup(\XF\App $app)
    {
        // Only run on public-facing requests, not admin or API
        if (!($app instanceof \XF\Pub\App))
        {
            return;
        }

        // Check if the add-on is enabled
        $options = $app->options();
        if (empty($options->wfSessionValidator_enabled))
        {
            return;
        }

        // Initialize and run the session validator using the gold standard code
        $validator = new Service\SessionValidator();
        $validator->validateAndSetHeaders();
    }

    /**
     * Listen to the app_admin_setup event to validate admin sessions early in the request cycle
     */
    public static function appAdminSetup(\XF\Admin\App $app)
    {
        // Check if the add-on is enabled
        $options = $app->options();
        if (empty($options->wfSessionValidator_enabled))
        {
            return;
        }

        // Initialize and run the session validator for admin requests
        $validator = new Service\SessionValidator();
        $validator->validateAndSetHeaders();
    }

    /**
     * Listen to the app_api_setup event to validate API sessions early in the request cycle
     */
    public static function appApiSetup(\XF\Api\App $app)
    {
        // Check if the add-on is enabled
        $options = $app->options();
        if (empty($options->wfSessionValidator_enabled))
        {
            return;
        }

        // Initialize and run the session validator for API requests
        $validator = new Service\SessionValidator();
        $validator->validateAndSetHeaders();
    }
    
    /**
     * Listen to the app_pub_complete event to set cache headers based on content age
     */
    public static function appPubComplete(\XF\App $app, \XF\Http\Response $response)
    {
        // Only run on public-facing requests
        if (!($app instanceof \XF\Pub\App))
        {
            return;
        }
        
        // Check if cache optimization is enabled
        $options = $app->options();
        if (empty($options->wfCacheOptimizer_enabled))
        {
            return;
        }
        
        // Skip if not a successful response
        if ($response->httpCode() !== 200)
        {
            return;
        }
        
        // Initialize and run the cache optimizer
        $optimizer = new Service\CacheOptimizer();
        $optimizer->setCacheHeaders();
    }
    
    /**
     * Listen to controller_post_dispatch to pre-emptively disable XenForo's page caching headers
     */
    public static function controllerPostDispatch(\XF\Mvc\Controller $controller, $action, \XF\Mvc\ParameterBag $params, \XF\Mvc\Reply\AbstractReply &$reply)
    {
        // Only run on public-facing requests
        $app = $controller->app();
        if (!($app instanceof \XF\Pub\App))
        {
            return;
        }
        
        // Check if cache optimization is enabled
        $options = $app->options();
        if (empty($options->wfCacheOptimizer_enabled))
        {
            return;
        }
        
        // If this is a View reply (normal page), disable XenForo's page caching
        if ($reply instanceof \XF\Mvc\Reply\View)
        {
            // Disable XenForo's internal page caching for this response
            $reply->setPageParam('noPageCache', true);
            
            // Also try to clear any cache control headers that might have been set early
            $response = $app->response();
            if ($response && ($options->wfCacheOptimizer_forceHeaders ?? true))
            {
                $response->removeHeader('Cache-Control');
                $response->removeHeader('Pragma');
                $response->removeHeader('Expires');
            }
        }
    }
    
    /**
     * Listen to visitor_setup to detect fresh login/logout and set cache bypass
     */
    public static function visitorSetup(\XF\Entity\User $visitor)
    {
        $app = \XF::app();
        $session = $app->session();
        $request = $app->request();
        $response = $app->response();
        
        // Check if user just logged in or out by looking for auth changes
        $previousUserId = $session->get('previousUserId');
        $currentUserId = $visitor->user_id;
        
        // Also check session cookie changes
        $sessionCookie = $request->getCookie('xf_session');
        $lastSessionCookie = $session->get('lastSessionCookie');
        
        // Detect if this is a new session or auth change
        $isAuthChange = false;
        
        // Case 1: User ID changed (login/logout/switch)
        if ($previousUserId !== null && $previousUserId != $currentUserId)
        {
            $isAuthChange = true;
        }
        
        // Case 2: Session cookie changed (new session)
        if ($lastSessionCookie !== null && $lastSessionCookie != $sessionCookie)
        {
            $isAuthChange = true;
        }
        
        // Case 3: Check for explicit login/logout indicators
        $routePath = $request->getRoutePath();
        if (preg_match('#^(login|logout)/#', $routePath))
        {
            $isAuthChange = true;
        }
        
        // Store current values for next request
        $session->set('previousUserId', $currentUserId);
        $session->set('lastSessionCookie', $sessionCookie);
        
        // If auth changed, set cache bypass
        if ($isAuthChange)
        {
            // Set a cookie to bypass cache for next few page loads
            $response->setCookie('cache_bypass', '1', 600); // 10 minutes
            
            // Also set cache-busting headers for this request
            $response->header('Vary', 'Cookie');
            $response->header('Cache-Control', 'no-cache, no-store, must-revalidate, private');
            $response->header('Pragma', 'no-cache');
            $response->header('Expires', '0');
            
            // Clear page cache for this user
            self::clearUserPageCache($visitor);
        }
    }
    
    /**
     * Clear page cache entries for a specific user
     */
    protected static function clearUserPageCache($visitor)
    {
        try {
            $app = \XF::app();
            $cache = $app->cache();
            
            if ($cache)
            {
                // Clear common cache keys that might contain user-specific data
                $cacheKeys = [
                    'page_' . $visitor->user_id,
                    'user_' . $visitor->user_id,
                    'session_' . $visitor->user_id
                ];
                
                foreach ($cacheKeys as $key)
                {
                    $cache->delete($key);
                }
            }
        } catch (\Exception $e) {
            // Log but don't interrupt
            \XF::logError('Cache clear error: ' . $e->getMessage());
        }
    }
    
    /**
     * Listen to login controller actions to set cache bypass
     */
    public static function controllerPreAction(\XF\Mvc\Controller $controller, $action)
    {
        $app = \XF::app();
        $response = $app->response();
        
        // Check if this is a login/logout action
        if ($controller instanceof \XF\Pub\Controller\Login || 
            $controller instanceof \XF\Pub\Controller\Logout)
        {
            // Set cache bypass cookie with longer duration
            $response->setCookie('cache_bypass', '1', 900); // 15 minutes
            
            // Set a session flag to track auth change
            $app->session()->set('auth_change_pending', true);
            
            // Force no-cache on the response
            $response->header('Cache-Control', 'no-cache, no-store, must-revalidate, private');
            $response->header('Pragma', 'no-cache');
            $response->header('Expires', '0');
            $response->header('X-Auth-Change', 'true');
            
            // If this is specifically a logout action, clear more cache
            if ($controller instanceof \XF\Pub\Controller\Logout && $action == 'actionIndex')
            {
                self::clearAllUserCache();
            }
        }
    }
    
    /**
     * Clear all user-related cache entries
     */
    protected static function clearAllUserCache()
    {
        try {
            $app = \XF::app();
            $cache = $app->cache();
            
            if ($cache)
            {
                // Clear page cache context specifically
                $pageCache = $app->cache('page');
                if ($pageCache)
                {
                    // Try to flush the page cache database
                    $pageCache->deleteMultiple(['*']); 
                }
            }
        } catch (\Exception $e) {
            \XF::logError('Cache clear error: ' . $e->getMessage());
        }
    }
    
    /**
     * Listen to controller post-action to modify redirects after login/logout
     */
    public static function controllerPostAction(\XF\Mvc\Controller $controller, $action, \XF\Mvc\ParameterBag $params, \XF\Mvc\Reply\AbstractReply &$reply)
    {
        // Only handle login/logout controllers
        if (!($controller instanceof \XF\Pub\Controller\Login || 
              $controller instanceof \XF\Pub\Controller\Logout))
        {
            return;
        }
        
        // If this is a redirect reply, add cache buster
        if ($reply instanceof \XF\Mvc\Reply\Redirect)
        {
            $url = $reply->getUrl();
            
            // Add cache buster parameter to the redirect URL
            $separator = (strpos($url, '?') !== false) ? '&' : '?';
            $cacheBuster = 'cb=' . time();
            
            $reply->setUrl($url . $separator . $cacheBuster);
        }
    }
}