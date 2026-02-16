<?php

namespace WindowsForum\SessionValidator\XF\Pub\Controller;

use XF\Mvc\Reply\Redirect;

class MiscController extends XFCP_MiscController
{
    public function actionStyle()
    {
        $reply = parent::actionStyle();

        if ($reply instanceof Redirect && $this->request->exists('style_id'))
        {
            $url = $reply->getUrl();
            $separator = strpos($url, '?') !== false ? '&' : '?';
            $reply->setUrl($url . $separator . '_sc=' . \XF::$time);

            $this->app->response()->header('Clear-Site-Data', '"cache"');

            // Re-set style cookies as non-httpOnly for JS access on cached pages.
            // The core sets both style_id and style_variation as httpOnly.
            // The wf_variation_fix JS and CF Worker cache-key variation both
            // need JS-readable cookies.
            if (!\XF::visitor()->user_id)
            {
                // Read style_id directly from request — don't rely on getCookies()
                // which may have unexpected values for the default style (0).
                $styleId = $this->filter('style_id', 'uint');
                $style = $this->app->style($styleId);
                $actualStyleId = $style->getId();

                // Always set non-httpOnly cookie so JS/Worker can read it.
                // For default style (0 or 40), this overwrites the old value
                // from a custom style like 17.
                $this->app->response()->setCookie('style_id', $actualStyleId, 0, null, false);

                // style_variation: read what core set and re-set non-httpOnly
                $cookies = $this->app->response()->getCookies();
                $prefix = $this->app->response()->getCookiePrefix();
                $varKey = $prefix . 'style_variation';
                if (isset($cookies[$varKey]))
                {
                    $this->app->response()->setCookie('style_variation', $cookies[$varKey][1], 0, null, false);
                }
            }
        }

        return $reply;
    }

    public function actionStyleVariation(): \XF\Mvc\Reply\AbstractReply
    {
        $reply = parent::actionStyleVariation();

        if ($reply instanceof Redirect)
        {
            $url = $reply->getUrl();
            $separator = strpos($url, '?') !== false ? '&' : '?';
            $reply->setUrl($url . $separator . '_sc=' . \XF::$time);

            $this->app->response()->header('Clear-Site-Data', '"cache"');

            // XenForo sets style_variation as httpOnly by default, but the
            // wf_variation_fix JS and CF Worker cache-key variation both need
            // to read it. Re-set with httpOnly=false so the cookie is
            // JS-readable and visible to the Worker.
            if (!\XF::visitor()->user_id)
            {
                $variation = $this->filter('variation', 'str');
                $reset = $this->filter('reset', 'bool');
                if ($reset)
                {
                    $this->app->response()->setCookie('style_variation', false, 0, null, false);
                }
                elseif ($variation)
                {
                    $this->app->response()->setCookie('style_variation', $variation, 0, null, false);
                }
            }
        }

        return $reply;
    }
}
