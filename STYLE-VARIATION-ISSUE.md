# Style Variation Cookie Not Persisting Through Cache Layers

## Symptom

Guest users switching style variation (dark/light mode) via the footer picker see the change apply momentarily, but on page refresh the page reverts to the default (dark) mode. The `xf_style_variation` cookie is never set in the browser.

## Root Cause (Two-Part)

### Part 1: CacheOptimizer was publicly caching the style-variation endpoint (FIXED)

The `/misc/style-variation` AJAX endpoint returns HTTP 200 (not 303) in AJAX context. The `CacheOptimizer` did not list `misc/style` in `isAuthenticationRoute()`, so the endpoint fell through to `setDefaultCacheHeaders()` which stamped it with:

```
Cache-Control: public, max-age=900, s-maxage=1800
```

Cloudflare and LiteSpeed cached this response. **CDNs strip `Set-Cookie` headers from cached responses** (by design — serving user-specific cookies to thousands of users would be a security risk per RFC 7234). So the first request got the cookie, but all subsequent requests for the next 30 minutes got the cached copy without `Set-Cookie`.

**Fix applied:** Added `'misc/style'` to the `isAuthenticationRoute()` array in `Service/CacheOptimizer.php` (line 415). Since it uses `strpos($routePath, $route) === 0`, this covers both `misc/style` and `misc/style-variation`. The endpoint now returns:

```
Cache-Control: private, no-cache, no-store, must-revalidate, max-age=0
X-Cache-Optimizer: no-cache-auth
```

### Part 2: Guests on cached pages had no CSRF cookie (FIXED by user)

Even after Part 1, the cookie still wasn't being set. The style-variation endpoint calls `assertValidCsrfToken()` which requires a valid `xf_csrf` cookie. Guests hitting cached pages (pagecache.php / LiteSpeed / Cloudflare) never bootstrapped XenForo, so they never received the `xf_csrf` cookie. Without it, the CSRF validation fails (HTTP 400) and the cookie is never set.

**Fix applied by user:** The `vc.php` beacon endpoint (which fires on every cached page via `navigator.sendBeacon()`) now sets the `xf_csrf` cookie for guests who don't have one (lines 264-276 of `vc.php`). This ensures guests on cached pages get a CSRF token before they interact with any AJAX endpoints.

## Verification

### Origin (localhost) - Confirmed Working

After both fixes, testing on origin shows:
- Style-variation endpoint returns `X-Cache-Optimizer: no-cache-auth` with `private, no-cache` headers
- `xf_style_variation` cookie is set (httpOnly: false) after clicking Light/Dark
- Cookie persists across page reload and navigation to other pages
- `data-color-scheme` and `data-variation` HTML attributes reflect the correct mode

### Through Cloudflare - Not Yet Fully Verified

Testing through Cloudflare is complicated by `/etc/hosts` mapping `windowsforum.com` to `127.0.0.1` on the server. Playwright's Chromium uses the system resolver and hits origin directly. Partial verification via curl with `--resolve` confirmed:
- The style-variation endpoint returns `cf-cache-status: MISS` (not cached by CF)
- Cache headers are correct (`private, no-cache`)

Full end-to-end testing through Cloudflare (guest loads cached page -> beacon fires -> gets CSRF -> switches variation -> cookie persists on reload) should be done from an external client (not the server).

## Request Flow

```
Guest visits cached page (e.g., /help/)
  -> CF/LiteSpeed/pagecache serves cached HTML (no Set-Cookie)
  -> vc.php beacon fires via sendBeacon()
     -> Sets xf_csrf cookie (non-httpOnly)
     -> Sets xf_session cookie (httpOnly)
  -> Guest clicks "Light" in style variation picker
  -> XF.ajax GET /misc/style-variation?variation=default&_xfResponseType=json
     -> CSRF validates against xf_csrf cookie
     -> Server sets xf_style_variation=default cookie (non-httpOnly)
     -> CacheOptimizer marks response as no-cache-auth (private, no-cache)
     -> CF passes response through uncached, Set-Cookie intact
  -> XF JS applies DOM changes (data-color-scheme, data-variation)
  -> On next page load, wf_variation_fix JS reads cookie and applies attributes
  -> pagecache.php varies Redis key by xf_style_variation cookie
  -> LiteSpeed varies via X-LiteSpeed-Vary: cookie=xf_style_variation
```

## Files Modified

| File | Change |
|------|--------|
| `Service/CacheOptimizer.php` line 415 | Added `'misc/style'` to `isAuthenticationRoute()` auth routes array |
| `/web/public_html/vc.php` lines 264-276 | (By user) Sets `xf_csrf` cookie for guests without one |

## Key Insight

Two independent systems needed to work together:
1. **CacheOptimizer** must not publicly cache cookie-setting endpoints (otherwise CDNs strip Set-Cookie)
2. **vc.php beacon** must bootstrap essential cookies (xf_csrf, xf_session) for guests on cached pages, since XenForo never runs for cache hits

Without both fixes, the chain breaks: either the CDN strips the cookie (Part 1) or CSRF validation fails before the cookie can be set (Part 2).
