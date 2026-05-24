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

    /**
     * Bypass CSRF on logout for an authenticated, same-origin click.
     *
     * The shared guest CSRF system (pagecache.php / vc.php / Dispatcher)
     * keeps guest tokens stable, but a logged-in user's xf_csrf cookie can
     * drift from the `t=` baked into the page they're looking at (CDN/LS
     * cache hand-offs, multi-tab transitions across cached/uncached paths).
     * Every other GET-with-side-effects route has the DP/Cloudflare + DP/PWA
     * AppPubStartEnd listeners as a safety net (Sec-Fetch-Site / same-host
     * referrer fake a valid _xfToken), but those listeners deliberately
     * exclude `logout/`. Without a compensating bypass, the drift surfaces
     * as "Security error" on logout.
     *
     * Mirror the DP listener's bypass criteria — same-origin Sec-Fetch-Site
     * (or same-host referrer for Safari < 16.4) — but narrow it further to
     * logged-in visitors only. A cross-site forged logout still fails
     * (Sec-Fetch-Site=cross-site, no matching referrer), so the CSRF
     * protection's actual purpose (preventing a third party from logging
     * the user out) remains intact.
     */
    public function assertValidCsrfToken($token = null, $validityPeriod = null)
    {
        if (\XF::visitor()->user_id && $this->isSameOriginRequest())
        {
            return;
        }

        parent::assertValidCsrfToken($token, $validityPeriod);
    }

    protected function isSameOriginRequest(): bool
    {
        $request = $this->request;

        $secFetchSite = (string) $request->getServer('HTTP_SEC_FETCH_SITE', '');
        if ($secFetchSite !== '')
        {
            // `none` = user-initiated navigation (typed URL, bookmark, restored
            // tab, history click) per W3C Fetch Metadata. Cannot be 3rd-party
            // CSRF — there is no initiating document. Only `cross-site` is the
            // forged-from-another-origin case we must keep blocking.
            return $secFetchSite !== 'cross-site';
        }

        // Safari < 16.4 didn't send Sec-Fetch-Site. Fall back to a same-host
        // referrer check against boardUrl, matching the DP listener pattern.
        // Also: missing referrer is treated as same-origin here because a
        // 3rd-party forgery would normally carry one (Referrer-Policy can
        // strip it, but a same-origin click on this site does so too), and
        // the SameSite=Lax default on xf_session limits the cross-site GET
        // surface anyway.
        $referrer = (string) $request->getReferrer();
        if ($referrer === '')
        {
            return true;
        }

        $boardUrl = (string) $this->options()->boardUrl;
        if ($boardUrl === '')
        {
            return false;
        }

        $stripWww = static fn (string $h): string => preg_replace('/^www\./', '', $h);

        $refHost = $stripWww(strtolower((string) parse_url($referrer, PHP_URL_HOST)));
        $boardHost = $stripWww(strtolower((string) parse_url($boardUrl, PHP_URL_HOST)));

        return $refHost !== '' && $refHost === $boardHost;
    }
}
