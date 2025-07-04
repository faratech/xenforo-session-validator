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
        $options = $app->options();
        if (empty($options->wfCacheOptimizer_enabled))
        {
            return;
        }
        
        // Check if user is authenticated
        $visitor = \XF::visitor();
        
        // If user is logged in, disable XenForo's page caching
        if ($visitor->user_id > 0)
        {
            \XF\Pub\App::$allowPageCache = false;
        }
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
    
    
}