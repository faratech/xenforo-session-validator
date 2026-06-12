<?php

namespace WindowsForum\SessionValidator\XF\Mvc;

/**
 * Globally suppresses XenForo's "Cookies are required to use this site"
 * (XF\Mvc\Controller::assertValidCsrfToken() -> phrase
 * cookies_required_to_use_this_site, thrown on the validateCsrfToken()
 * 'no_cookie' branch).
 *
 * Heavy guest page caching (pagecache.php / CF / LiteSpeed) means a guest can
 * hold a baked _xfToken with no matching xf_csrf cookie. pagecache.php and
 * vc.php already pin a stable shared guest csrf value; this guarantees the XF
 * runtime sees that same value even on non-cached / full-bootstrap requests,
 * so the 'no_cookie' branch is unreachable. Genuine token mismatch still
 * yields the normal 'invalid' security error — CSRF protection is preserved,
 * only the cookie-missing failure mode (and its popup) is removed.
 *
 * XF\Http\Request is not class-extendable (App.php builds it with `new`,
 * not extendClass), and the abstract base controller can't be globally
 * extended, so Dispatcher (extendClass-resolved, shared by pub/admin/api)
 * is the single global chokepoint that runs before any controller's
 * preDispatch() CSRF check.
 *
 * SCOPE — GUESTS ONLY. The shared value is a non-httpOnly cookie identical
 * for every guest, so it is trivially readable by anyone who simply visits
 * as a guest. That means guest CSRF is, by design, NOT enforced in this
 * cache-heavy model (this already predated the change: SessionValidator's
 * SearchController/MiscController skip guest CSRF, and cached guest pages
 * ship empty tokens). We therefore inject ONLY for true guests. Injecting
 * the publicly-observable value for an authenticated/cookied user would make
 * THAT account's CSRF forgeable — so requests carrying xf_user/xf_session
 * are left alone (they keep their unguessable per-visitor csrf; XF
 * regenerates one on a full render if missing).
 *
 * globalSalt rotation is deliberately NOT the fix: it does not help (the
 * shared cookie is observable regardless of salt) and it would break every
 * historically-stored proxied image/link (src/XF/SubContainer/Proxy.php
 * signs with globalSalt . imageLinkProxyKey). Do not "harden" this by
 * removing the shared token either — that resurrects the popup.
 */
class Dispatcher extends XFCP_Dispatcher
{
	public function run($routePath = null)
	{
		$this->ensureSharedGuestCsrfCookie();
		$this->applyPreviewStyleParam();

		return parent::run($routePath);
	}

	/**
	 * ?_wfStyle=<id> — shareable entry/exit link for the gated preview style
	 * (see WindowsForum\SessionValidator\XF\Style). Guests only: logged-in
	 * users take their account style preference instead. `?_wfStyle=0` exits.
	 *
	 * Sets the cookie for subsequent requests AND applies the style to the
	 * current one (visitor style_id was already populated from cookies at
	 * App::start before dispatch, so the cookie alone wouldn't show until the
	 * next page). Param'd URLs are unique cache keys end to end, so cached
	 * guest pages can't mask the switch.
	 */
	protected function applyPreviewStyleParam(): void
	{
		try
		{
			$request = $this->request;
			if (!$request || !$request->exists('_wfStyle'))
			{
				return;
			}

			$visitor = \XF::visitor();
			if ($visitor->user_id)
			{
				return;
			}

			$previewStyleId = (int) (\XF::config('wfPreviewStyleId') ?? 0);
			if ($previewStyleId <= 0)
			{
				return;
			}

			$requested = $request->filter('_wfStyle', 'uint');
			$response = $this->app->response();

			if ($requested === $previewStyleId)
			{
				$response->setCookie('style_id', $previewStyleId, 30 * 86400, null, false);
				$applyStyleId = $previewStyleId;
			}
			else
			{
				$response->setCookie('style_id', false);
				$applyStyleId = 0;
			}

			$visitor->setReadOnly(false);
			$visitor->setAsSaved('style_id', $applyStyleId);
			$visitor->setReadOnly(true);
		}
		catch (\Throwable $e)
		{
			\XF::logException($e, false, 'SessionValidator preview style param: ');
		}
	}

	protected function ensureSharedGuestCsrfCookie(): void
	{
		try
		{
			$request = $this->request;
			if (!$request || $request->getCookie('csrf'))
			{
				return;
			}

			// Guests only — see class doc. An authenticated/cookied user
			// must keep their own unguessable per-visitor csrf.
			if ($request->getCookie('user') || $request->getCookie('session'))
			{
				return;
			}

			$salt = (string) \XF::app()->config('globalSalt');
			if ($salt === '')
			{
				return;
			}

			$shared = substr(hash_hmac('md5', 'wf-shared-guest-csrf', $salt), 0, 16);
			$prefix = $request->getCookiePrefix();

			static $cookieProp = null;
			if ($cookieProp === null)
			{
				$cookieProp = new \ReflectionProperty(\XF\Http\Request::class, 'cookie');
				$cookieProp->setAccessible(true);
			}

			$cookies = $cookieProp->getValue($request);
			if (!is_array($cookies))
			{
				return;
			}
			$cookies[$prefix . 'csrf'] = $shared;
			$cookieProp->setValue($request, $cookies);

			// Persist for subsequent requests + client JS (httpOnly=false,
			// matching XF's own csrf cookie and pagecache.php/vc.php).
			$this->app->response()->setCookie('csrf', $shared, 0, null, false);
		}
		catch (\Throwable $e)
		{
			// Never let CSRF-cookie hardening break dispatch. Worst case the
			// original XF behaviour (and its popup) simply returns.
			\XF::logException($e, false, 'SessionValidator shared guest csrf: ');

			// Machine-detectable sentinel: a swallowed failure here (e.g. a
			// future XF rename of Request::$cookie) silently resurrects the
			// popup. ai/botstatus.py polls this key. Auto-expires so it
			// clears once healthy again.
			try
			{
				$r = new \Redis();
				$r->connect('127.0.0.1', 6379, 0.5);
				$r->setex(
					'wf_csrf_inject_broken',
					3600,
					get_class($e) . ': ' . $e->getMessage()
				);
				$r->close();
			}
			catch (\Throwable $ignored)
			{
			}
		}
	}
}
