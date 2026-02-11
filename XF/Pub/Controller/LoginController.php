<?php

namespace WindowsForum\SessionValidator\XF\Pub\Controller;

use XF\Mvc\Reply\Redirect;

class LoginController extends XFCP_LoginController
{
    public function actionLogin(\XF\Mvc\ParameterBag $params)
    {
        $reply = parent::actionLogin($params);

        // After a successful login, append a cache-busting parameter
        // to the redirect URL so the browser fetches a fresh page
        // instead of serving the cached guest version (max-age=600).
        if ($reply instanceof Redirect && \XF::visitor()->user_id)
        {
            $url = $reply->getUrl();
            $separator = strpos($url, '?') !== false ? '&' : '?';
            $reply->setUrl($url . $separator . '_sc=' . \XF::$time);

            // Tell the browser to clear its HTTP cache so stale guest pages
            // are not served after login. Supported by Chrome/Edge/Firefox.
            // Safari ignores this, so the JS fallback below handles it.
            $this->app->response()->header('Clear-Site-Data', '"cache"');

            // Set a JS-readable cookie so cached guest pages can detect
            // the login and reload (Safari fallback). httpOnly=false so JS can read it.
            // Expires after 700s — slightly longer than max-age=600 so all
            // stale browser cache entries will have expired by then.
            $this->app->response()->setCookie('ls', '1', 700, null, false);
        }

        return $reply;
    }
}
