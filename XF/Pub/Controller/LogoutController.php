<?php

namespace WindowsForum\SessionValidator\XF\Pub\Controller;

class LogoutController extends XFCP_LogoutController
{
    public function actionIndex()
    {
        try
        {
            $reply = parent::actionIndex();
        }
        finally
        {
            // Clear the login-state cookie to prevent the login-cache-reload JS
            // from triggering an infinite reload loop on cached guest pages.
            // Using try/finally ensures the cookie is cleared even if the parent
            // throws (e.g., CSRF validation failure).
            $this->app->response()->setCookie('ls', false);
        }

        return $reply;
    }
}
