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
}