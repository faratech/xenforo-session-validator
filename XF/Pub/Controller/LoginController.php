<?php

namespace WindowsForum\SessionValidator\XF\Pub\Controller;

use XF\Mvc\Reply\Redirect;

class LoginController extends XFCP_LoginController
{
    public function actionLogin(\XF\Mvc\ParameterBag $params)
    {
        $reply = parent::actionLogin($params);

        if ($reply instanceof Redirect && \XF::visitor()->user_id)
        {
            $this->applyCacheBusting($reply);
        }

        return $reply;
    }

    public function actionTwoStep()
    {
        $reply = parent::actionTwoStep();

        // After successful 2FA completion, the redirect URL lacks cache-busting.
        // Without this, LiteSpeed serves a cached guest page because the URL
        // (e.g. https://windowsforum.com/) matches the cache key exactly.
        if ($reply instanceof Redirect && \XF::visitor()->user_id)
        {
            $this->applyCacheBusting($reply);
        }

        return $reply;
    }

    /**
     * Append cache-busting parameter and set cookies/headers to ensure
     * the browser fetches a fresh page after login instead of serving
     * a cached guest version from LiteSpeed.
     */
    protected function applyCacheBusting(Redirect $reply): void
    {
        $url = $reply->getUrl();
        $separator = strpos($url, '?') !== false ? '&' : '?';
        $reply->setUrl($url . $separator . '_sc=' . \XF::$time);

        // Only send Clear-Site-Data on non-AJAX (full page) requests.
        // On AJAX overlay login, this header forces synchronous cache clearing
        // which stalls mobile browsers before the redirect can happen.
        if (!$this->request->isXhr())
        {
            $this->app->response()->header('Clear-Site-Data', '"cache"');
        }

        // Set a JS-readable cookie so cached guest pages can detect
        // the login and reload (Safari fallback). httpOnly=false so JS can read it.
        // Expires after 700s — slightly longer than max-age=600 so all
        // stale browser cache entries will have expired by then.
        $this->app->response()->setCookie('ls', '1', 700, null, false);
    }
}
