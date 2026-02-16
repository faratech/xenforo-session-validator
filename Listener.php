<?php

namespace WindowsForum\SessionValidator;

class Listener
{
    private static ?bool $validatorEnabled = null;
    private static ?bool $cacheOptimizerEnabled = null;

    private static function isValidatorEnabled(): bool
    {
        if (self::$validatorEnabled === null) {
            self::$validatorEnabled = !empty(\XF::app()->options()->wfSessionValidator_enabled);
        }
        return self::$validatorEnabled;
    }

    private static function isCacheOptimizerEnabled(): bool
    {
        if (self::$cacheOptimizerEnabled === null) {
            self::$cacheOptimizerEnabled = !empty(\XF::app()->options()->wfCacheOptimizer_enabled);
        }
        return self::$cacheOptimizerEnabled;
    }

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
        if (!self::isValidatorEnabled())
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
        if (!self::isValidatorEnabled())
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
        if (!self::isValidatorEnabled())
        {
            return;
        }

        // Initialize and run the session validator for API requests
        $validator = new Service\SessionValidator();
        $validator->validateAndSetHeaders();
    }
    
    /**
     * Listen to the controller_post_dispatch event to disable XenForo's page caching for logged-in users
     */
    public static function controllerPostDispatch(\XF\Mvc\Controller $controller, $action, \XF\Mvc\ParameterBag $params, \XF\Mvc\Reply\AbstractReply &$reply)
    {
        // Only run on public-facing requests
        $app = \XF::app();
        if (!($app instanceof \XF\Pub\App))
        {
            return;
        }

        // Check if cache optimization is enabled
        if (!self::isCacheOptimizerEnabled())
        {
            return;
        }

        // Check if user is authenticated
        $visitor = \XF::visitor();

        // If user is logged in, disable XenForo's page caching
        if ($visitor->user_id > 0)
        {
            \XF\Pub\App::$allowPageCache = false;

            // Also set a header for LiteSpeed to skip caching for this user
            if (!headers_sent())
            {
                header('X-LiteSpeed-Cache-Control: no-cache');
            }
        }
        // Guest LiteSpeed tags are set by CacheOptimizer::setCacheControlHeaders()
        // via the app_pub_complete event — no need to duplicate here.
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
        if (!self::isCacheOptimizerEnabled())
        {
            return;
        }

        // Skip if not a cacheable response
        // 200 OK, 301/308 permanent redirects, 404 not found, 410 gone
        // Caching 404/410 prevents bots from hammering origin with junk URLs
        $httpCode = $response->httpCode();
        $cacheableStatuses = [200, 301, 308, 400, 403, 404, 410];
        if (!in_array($httpCode, $cacheableStatuses))
        {
            return;
        }
        
        // Initialize and run the cache optimizer
        $optimizer = new Service\CacheOptimizer();
        $optimizer->setCacheHeaders();
    }
    
    
}