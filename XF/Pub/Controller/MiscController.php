<?php

namespace WindowsForum\SessionValidator\XF\Pub\Controller;

use XF\Mvc\Reply\Redirect;

class MiscController extends XFCP_MiscController
{
    public function actionStyle()
    {
        $reply = parent::actionStyle();

        // Append a cache-busting parameter to the redirect URL so the
        // browser fetches a fresh page instead of serving the old
        // style from its local cache (max-age).
        if ($reply instanceof Redirect && $this->request->exists('style_id'))
        {
            $url = $reply->getUrl();
            $separator = strpos($url, '?') !== false ? '&' : '?';
            $reply->setUrl($url . $separator . '_sc=' . \XF::$time);

            // Tell the browser to clear its HTTP cache so stale pages
            // with the old style are not served on subsequent navigation.
            $this->app->response()->header('Clear-Site-Data', '"cache"');

            // Set a JS-readable cookie so cached pages can detect the
            // style change and reload (Safari fallback — ignores Clear-Site-Data).
            // httpOnly=false so JS can read it. Expires after 700s (> max-age=600).
            $this->app->response()->setCookie('sc', '1', 700, null, false);
        }

        return $reply;
    }

    public function actionStyleVariation(): \XF\Mvc\Reply\AbstractReply
    {
        $reply = parent::actionStyleVariation();

        // Append a cache-busting parameter to the redirect URL so the
        // browser fetches a fresh page instead of serving the old
        // dark/light version from its local cache (max-age).
        if ($reply instanceof Redirect)
        {
            $url = $reply->getUrl();
            $separator = strpos($url, '?') !== false ? '&' : '?';
            $reply->setUrl($url . $separator . '_sc=' . \XF::$time);

            // Tell the browser to clear its HTTP cache so stale pages
            // with the old variation are not served on subsequent navigation.
            $this->app->response()->header('Clear-Site-Data', '"cache"');

            // Safari fallback cookie (same as actionStyle above).
            $this->app->response()->setCookie('sc', '1', 700, null, false);
        }

        return $reply;
    }
}
