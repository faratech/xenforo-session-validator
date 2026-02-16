<?php

namespace WindowsForum\SessionValidator\XF\Pub\Controller;

class LogoutController extends XFCP_LogoutController
{
    public function actionIndex()
    {
        $reply = parent::actionIndex();

        // Clear the login-state cookie to prevent the login-cache-reload JS
        // from triggering an infinite reload loop on cached guest pages.
        $this->app->response()->setCookie('ls', false);

        // Tell the browser to clear its HTTP cache so stale logged-in pages
        // are not served after logout. Safari ignores this header.
        $this->app->response()->header('Clear-Site-Data', '"cache"');

        return $reply;
    }
}
