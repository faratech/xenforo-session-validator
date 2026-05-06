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
     * Listen to the app_pub_complete event to validate sessions and set verification headers.
     * Must run at app_pub_complete (not app_setup) because the visitor/session
     * isn't authenticated until after XF\App::start() completes.
     */
    public static function appPubCompleteValidator(\XF\App $app, \XF\Http\Response $response)
    {
        if (!self::isValidatorEnabled())
        {
            return;
        }

        $validator = new Service\SessionValidator();
        $validator->validateAndSetHeaders();
    }

    /**
     * Listen to the app_admin_complete event to validate admin sessions
     */
    public static function appAdminComplete(\XF\App $app, \XF\Http\Response $response)
    {
        if (!self::isValidatorEnabled())
        {
            return;
        }

        $validator = new Service\SessionValidator();
        $validator->validateAndSetHeaders();
    }

    /**
     * Listen to the app_api_complete event to validate API sessions
     */
    public static function appApiComplete(\XF\App $app, \XF\Http\Response $response)
    {
        if (!self::isValidatorEnabled())
        {
            return;
        }

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

            // Set a header for LiteSpeed to skip caching for this user. Goes
            // through the XF response object so it participates in the response
            // lifecycle (clearable by CacheOptimizer if it overrides later).
            $app->response()->header('X-LiteSpeed-Cache-Control', 'no-cache');
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
        // 200 OK, redirects handled by CacheOptimizer, 400/404/410 bot probes.
        // 403 is intentionally excluded because it can be per-visitor.
        $httpCode = $response->httpCode();
        $cacheableStatuses = [200, 301, 302, 303, 308, 400, 404, 410];
        if (!in_array($httpCode, $cacheableStatuses))
        {
            return;
        }
        
        // Initialize and run the cache optimizer
        $optimizer = new Service\CacheOptimizer();
        $optimizer->setCacheHeaders();
    }
    
    
}
