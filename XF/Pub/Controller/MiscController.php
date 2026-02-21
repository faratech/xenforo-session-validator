<?php

namespace WindowsForum\SessionValidator\XF\Pub\Controller;

use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\Redirect;
use XF\Repository\StyleRepository;

class MiscController extends XFCP_MiscController
{
    /**
     * Skip CSRF pre-dispatch check for guest style-variation requests.
     * On CDN-cached pages the data-csrf token is signed with a different xf_csrf
     * cookie than what vc.php later sets, so CSRF always fails for guests.
     * Style variation is a non-destructive preference cookie — safe to skip.
     */
    public function checkCsrfIfNeeded($action, ParameterBag $params)
    {
        if ($action === 'StyleVariation' && !\XF::visitor()->user_id)
        {
            return;
        }

        parent::checkCsrfIfNeeded($action, $params);
    }

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
        try
        {
            $reply = parent::actionStyleVariation();
        }
        catch (\XF\Mvc\Reply\Exception $e)
        {
            // CSRF fails on CDN-cached pages: the data-csrf token baked into cached HTML
            // was signed with a different xf_csrf cookie than what vc.php later sets.
            // For guests, style variation is just a preference cookie — handle without CSRF.
            if (!\XF::visitor()->user_id)
            {
                return $this->handleGuestStyleVariationFallback();
            }
            throw $e;
        }

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

    /**
     * Handle style variation for guests when CSRF validation fails (CDN-cached pages).
     * Replicates the essential logic from XF's actionStyleVariation().
     */
    protected function handleGuestStyleVariationFallback(): \XF\Mvc\Reply\AbstractReply
    {
        $styleRepo = \XF::repository(StyleRepository::class);
        $selectedStyleId = $styleRepo->getSelectedStyleIdForUser(\XF::visitor());
        $style = \XF::app()->style($selectedStyleId);

        if ($this->request->exists('variation'))
        {
            $variation = $this->filter('variation', 'str');
            if (!in_array($variation, $style->getVariations()))
            {
                $variation = '';
            }
        }
        else if ($this->filter('reset', 'bool'))
        {
            $variation = '';
        }
        else
        {
            return $this->redirect($this->buildLink('index'));
        }

        // Set cookie with httpOnly=false so JS and CF Worker can read it
        $this->app->response()->setCookie('style_variation', $variation ?: false, 0, null, false);

        $icon = $style->getVariationIcon($variation);
        $colorScheme = $variation
            ? $style->getPropertyVariation('styleType', $variation)
            : '';
        $metaThemeColor = $variation
            ? $style->getPropertyVariation('metaThemeColor', $variation)
            : '';
        if ($metaThemeColor)
        {
            $metaThemeColor = $this->app()->templater()->func('parse_less_color', [$metaThemeColor]);
        }

        $redirect = $this->getDynamicRedirectIfNot(
            $this->buildLink('misc/style-variation')
        );
        $separator = strpos($redirect, '?') !== false ? '&' : '?';
        $redirect .= $separator . '_sc=' . \XF::$time;

        $this->app->response()->header('Clear-Site-Data', '"cache"');

        $reply = $this->redirect($redirect);
        $reply->setJsonParams([
            'variation' => $variation,
            'colorScheme' => $colorScheme,
            'icon' => $icon,
            'properties' => ['metaThemeColor' => $metaThemeColor ?: null],
        ]);
        return $reply;
    }
}
