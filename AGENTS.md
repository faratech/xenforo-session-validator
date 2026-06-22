# AGENTS.md

This file provides guidance to Codex (Codex.ai/code) when working with code in this repository.

## Project Overview

**Session Validator & Cache Optimizer** — a XenForo 2.2+ addon for WindowsForum.com that:
1. Validates user sessions and exposes verification headers for Cloudflare WAF rules
2. Sets intelligent cache headers (Cache-Control, Cloudflare-CDN-Cache-Control, LiteSpeed) based on content type and age
3. Sets accurate `Last-Modified` headers from DB timestamps (replacing LiteSpeed's bogus "current time" value)
4. Handles cache-busting on login/logout/style-change to prevent stale cached pages

## Essential Commands

All commands run from **`/web/public_html/`**, not the addon directory.

```bash
# Rebuild addon after changing _data/ XML files
php cmd.php xf:addon-rebuild WindowsForum/SessionValidator

# Regenerate file hashes (can run from any directory)
php /web/regen_addon_hashes.php WindowsForum/SessionValidator

# Full deploy cycle after PHP changes
php cmd.php xf:addon-rebuild WindowsForum/SessionValidator && \
php /web/regen_addon_hashes.php WindowsForum/SessionValidator && \
rm -rf /dev/shm/lscache/* && redis-cli -n 1 FLUSHDB && service lsws restart

# Verify headers on live site (bypass CDN cache with _nc param)
curl -sI "https://windowsforum.com/threads/some-thread.123/?_nc=$(date +%s)" | grep -iE 'last-modified|x-cache-optimizer|x-litespeed-tag'
```

**Important**: After editing PHP files, LiteSpeed must be restarted (`service lsws restart`) to flush opcache. The CLI `opcache_reset()` does NOT affect the web server's opcache. `opcache.revalidate_freq = 60` means changes take up to 60s without a restart.

## Architecture

### Generic Shell Capsule

The no-bootstrap generic shell/capsule path is documented in
[`GENERIC-SHELL.md`](GENERIC-SHELL.md). Read it before changing `pagecache.php`,
`Service/GenericShellFragment.php`, `Service/CapsuleSnapshot.php`, `wf-capsule.php`, or
`js/windowsforum/capsule/hydrate.js`.

### Request Lifecycle (Event Listeners -> `Listener.php`)

The addon hooks into five XenForo events, registered in `_data/code_event_listeners.xml`:

| Event | Method | Order | Purpose |
|---|---|---|---|
| `app_pub_complete` | `appPubCompleteValidator` | 10 | Session validation headers (public). Runs at `_complete` rather than `_setup` so the visitor is fully authenticated. |
| `app_admin_complete` | `appAdminComplete` | 10 | Session validation headers for admin panel |
| `app_api_complete` | `appApiComplete` | 10 | Session validation headers for API |
| `controller_post_dispatch` | `controllerPostDispatch` | 1 | Disables XenForo page cache for logged-in users; sets `X-LiteSpeed-Cache-Control: no-cache` via the response object |
| `app_pub_complete` | `appPubComplete` | 9999 | Sets cache headers on cacheable responses (200, 301, 302, 303, 308, 400, 404, 410). 403 is intentionally excluded because it can be per-visitor (banned users). |

The two `app_pub_complete` listeners run in order — validation first (10), CacheOptimizer last (9999) so it can clear and re-set headers cleanly. Each listener gates on its respective option (`wfSessionValidator_enabled` / `wfCacheOptimizer_enabled`).

### Service Layer

**`Service/SessionValidator.php`** — Sets `XF-Verified-User`, `XF-Verified-Session`, `XF-User-Group` headers. Security: only sets headers for requests from Cloudflare IP ranges (validated via `\XF\Util\Ip::ipMatchesCidrRange()` against `\XF\Http\Request::$cloudFlareIps`).

**`Service/CacheOptimizer.php`** — Core caching logic. Processing order in `setCacheHeaders()`:
1. `clearCacheHeaders()` — removes all cache headers including `Last-Modified` (both XF `removeHeader()` and PHP `header_remove()`)
2. Auth route check — no-cache for login/logout/register/etc.
3. 301/308 redirects — aggressive caching, no DB query
4. Logged-in user check — private no-cache
5. Error codes (400/404/410) — short edge cache to absorb bot probes
6. Route matching — content-specific handlers with DB queries for age-tiered TTLs and `Last-Modified`

### Route Matching (CacheOptimizer)

Uses prefix-based dispatch: extracts the first path segment with `strpos`/`substr`, then a `switch` statement tests only the relevant regex(es) for that prefix. Thread pages (most common) test exactly 1 regex instead of up to 16. Within each prefix, sub-paths are tested before general paths. All regexes extract the numeric ID from `title.123` URL format.

| Route Pattern | Handler | X-Cache-Optimizer | LiteSpeed Tag | Last-Modified Source |
|---|---|---|---|---|
| `threads/title.123/` | `setThreadCacheHeaders` | `thread-{age}d` | `T123` | `xf_thread.last_post_date` |
| `forums/name.45/` | `setForumCacheHeaders` | `forum` / `forum-extended` | — | `xf_forum.last_post_date` |
| `media/albums/title.123/` | `setAlbumCacheHeaders` | `album-{age}d` | `A123` | `GREATEST(create_date, last_update_date, last_comment_date)` |
| `media/categories/name.5/` | `setMediaCategoryCacheHeaders` | `media-category` | — | none (no date columns) |
| `media/title.123/` | `setMediaCacheHeaders` | `media-{age}d` | `M123` | `GREATEST(media_date, last_edit_date, last_comment_date)` |
| `resources/categories/name.5/` | `setResourceCategoryCacheHeaders` | `resource-category` | — | `xf_rm_category.last_update` |
| `resources/title.123/` | `setResourceCacheHeaders` | `resource-{age}d` | `R123` | `xf_rm_resource.last_update` |
| `members/name.123/` | `setMemberCacheHeaders` | `member` | — | `GREATEST(last_activity, avatar_date, username_date, banner_date)` |
| `members/` | `setListingCacheHeaders` | `member-index` | — | none |
| `media/` | `setListingCacheHeaders` | `media-index` | — | none |
| `resources/` | `setListingCacheHeaders` | `resource-index` | — | none |
| `tags/slug/` | `setTagCacheHeaders` | `tag` | — | `xf_tag.last_use_date` |
| `tags/` | `setListingCacheHeaders` | `tag-index` | — | none |
| `whats-new/` | `setWhatsNewCacheHeaders` | `whats-new` | — | none |
| `help/` | `setStaticPageCacheHeaders` | `help` | — | none |
| `pages/name` | `setStaticPageCacheHeaders` | `page` | — | none |
| *(everything else)* | `setDefaultCacheHeaders` | `default` | — | none |

### Cache TTL Tiers

**Age-tiered content** (threads, media, albums, resources) uses configurable thresholds:
- Fresh (<24h): short TTL (default 600s browser / 1800s edge)
- Recent (1-7d): medium TTL
- Older (7-30d): longer TTL
- Archived (30d+): aggressive TTL
- Ancient (10y+): 1 year cache

Threads in "extended cache nodes" (configurable, default: node 4 = Windows News) get longer TTLs at each tier. XFMG/XFRM content uses the non-extended thread TTLs.

**Flat-TTL pages**: forums/listings use forum-level options, whats-new uses homepage options, help/pages use hardcoded 1d/7d.

### Triple-Layer Cache Headers

`setCacheControlHeaders()` sets three parallel cache directives:
- `Cache-Control` — browser cache (`max-age`) + shared cache (`s-maxage`)
- `Cloudflare-CDN-Cache-Control` — Cloudflare edge (overrides Cache-Control at edge)
- `X-LiteSpeed-Cache-Control` — LiteSpeed origin proxy cache

Plus `X-LiteSpeed-Vary: cookie=xf_style_variation, cookie=xf_style_id, cookie=xf_language_id` for per-theme/language cache entries, and `stale-while-revalidate` / `stale-if-error` directives.

### DB Query Pattern

All queries use raw `\XF::db()->fetchRow()` (no entity overhead), wrapped in try/catch with `\XF::logError()`. Each returns a single row by PK. Computed timestamps use `GREATEST()` with `COALESCE(col, 0)` for nullable date columns.

### LiteSpeed Purge Tags

All LiteSpeed purge tags are set by `CacheOptimizer::setCacheControlHeaders()` via the `app_pub_complete` event using `$this->response->header()`. Tags use a compact format: `T123` (thread), `M123` (media), `A123` (album), `R123` (resource), always paired with `public`.

### Class Extensions (`_data/class_extensions.xml`)

Four XFCP controller extensions and two entity extensions:

**`XF/Pub/Controller/LoginController.php`** — After successful login or 2FA, appends `_sc={timestamp}` to redirect URL. Sets `ls=1` cookie via `Response::setCookie()` (which prepends the `xf_` cookie prefix, so the on-the-wire cookie is `xf_ls`), httpOnly=false, 700s TTL — for JS detection on cached guest pages.

**`XF/Pub/Controller/LogoutController.php`** — Clears the `ls`/`xf_ls` cookie via try/finally so the cookie is removed even if the parent throws.

**`XF/Pub/Controller/MiscController.php`** — Extends `actionStyle()` and `actionStyleVariation()` to append `_sc` cache-bust param and re-set style cookies as non-httpOnly (XenForo defaults to httpOnly) so client JS and Cloudflare Worker can read them.

**`XF/Pub/Controller/SearchController.php`** — Redis-backed canonical search cache + per-IP rate limiter for guest search execution paths (actionResults, actionSearch, actionOlder, actionMember). Skips CSRF for guest POSTs so Cloudflare managed challenges work.

**`XF/Entity/Thread.php`** + **`XF/Entity/Post.php`** — `_postSave`/`_postDelete` hooks that call `Service\LiveNewsCacheInvalidator::purgeForThread()` to flush the addon's `wf_co:thread:*`/`wf_co:forum:*` Redis cache and (for threads in news nodes) enqueue a Cloudflare + LiteSpeed cache purge job.

### Template Modifications (`_data/template_modifications.xml`)

Six modifications, mostly inline `<script>` injections into `PAGE_CONTAINER`:

1. **`wf_inp_containment`** (order 7) — CSS `contain` rules to reduce layout/style cost on widgets.
2. **`wf_variation_fix`** (order 9) — Reads `xf_style_variation` cookie, sets `data-color-scheme`/`data-variation` on `<html>`. Prevents flash of wrong theme on CDN-cached pages.
3. **`wf_login_cache_reload`** (order 10) — Guest-only. Detects `xf_ls` cookie, deletes it, hides page, triggers `location.reload()`. Forces fresh fetch instead of stale cached guest page after login.
4. **`wf_carousel_lazy_js`** + **`wf_carousel_lazy_init`** + **`wf_carousel_lazy_loader`** (orders 10/11) — IntersectionObserver-driven lazy loading of XFMG slider JS to defer cost until viewport entry.
5. **`wf_inline_preamble`** (order 10) — Inlines XF's `preamble.js` into the page to eliminate a render-blocking request.

### Cache-Busting Flow (Login)

1. User logs in -> `LoginController::applyCacheBusting()` sets `xf_ls=1` cookie + `_sc` URL param
2. Browser redirects to destination with `_sc` -> cache miss -> fresh page for logged-in user
3. If browser serves cached guest page (Safari, aggressive LiteSpeed), `wf_login_cache_reload` JS detects `xf_ls` cookie -> clears cookie -> reloads
4. On logout, `LogoutController` clears `xf_ls` cookie

### Live-News Cache Invalidation

When a thread/post is saved or deleted, the entity hooks in `XF/Entity/Thread.php` and `XF/Entity/Post.php` call `Service\LiveNewsCacheInvalidator::purgeForThread()`. The invalidator:

1. **Always** flushes the addon's own Redis cache keys (`wf_co:thread:<id>`, `wf_co:forum:<node_id>` for both old and new node when a thread is moved). This keeps the `Last-Modified` header on the next render in sync with the new `last_post_date`, regardless of news status.
2. **For threads in news nodes only** (intersection of `wfCacheOptimizer_extendedCacheNodes` and `wfNewsFilterNodeIds` from the sibling `WindowsForum/NewsFilter` addon, defaulting to `[4]`), enqueues a `Job\LiveNewsCachePurge` job with a deduplicated set of URLs (homepage, what's-new, what's-new posts pointer, thread `/latest` redirect, and affected forum index pages, each with and without trailing slash).
3. The job: purges Cloudflare via `DigitalPoint\Cloudflare:Cloudflare` repo (URL list), purges LiteSpeed via fire-and-forget background `curl` calls to `lscache_purge.php` for tags `H`, `WN`, `F<nodeId>` (Host header derived from `boardUrl`), and warms the URLs in the background to seed fresh cache entries.

## Configuration

All options in `_data/options.xml`, managed via Admin CP -> Options -> Session Validator. Prefixed `wfSessionValidator_*` and `wfCacheOptimizer_*`.

Key options:
- `wfSessionValidator_enabled` / `wfCacheOptimizer_enabled` — master switches
- `wfSessionValidator_cloudflareOnly` — restrict headers to Cloudflare requests (default: true)
- `wfCacheOptimizer_extendedCacheNodes` — comma-separated node IDs for extended cache (default: "4")
- Thread age thresholds (`ageThreshold1Day`, `ageThreshold7Days`, `ageThreshold30Days`, `ancientThreshold`) and cache durations (`threadFresh`, `thread1Day`, `thread7Days`, `thread30Days`, `ancientCache` + extended variants) are all configurable

## Debugging

```bash
# Check which caching path a URL takes
curl -sI "https://windowsforum.com/some-url/?_nc=$(date +%s)" | grep -iE 'x-cache-optimizer|last-modified|x-litespeed-tag|cache-control'
```

- `X-Cache-Optimizer` — identifies the handler (e.g., `thread-3.5d`, `media-4043.4d`, `member`, `tag`, `whats-new`, `help`, `no-cache-user`, `error-404`, `redirect-301`, `redirect-temp-302`, `default`)
- `Last-Modified` — actual content timestamp from DB (threads, forums, media, albums, resources, members, tags)
- `X-LiteSpeed-Tag` — cache purge tags (e.g., `public, T401039`, `public, F4`, `public, H`, `public, WN`)
- Error logging: `\XF::logError('message')` -> Admin CP -> Logs -> Server error log
- Set headers via `$this->response->header(...)` so they go through XF's response lifecycle (clearable by later listeners). Reserve raw `header()` for early hooks where the response object isn't available yet, and only after a `headers_sent()` guard.
- Wrap DB queries in try/catch with `\XF::logError()`; the addon must never break the request path on cache logic.

### Verifying live-news invalidation

```bash
# 1. Note current Last-Modified on a news thread
curl -sI "https://windowsforum.com/threads/some-news-thread.NNN/?_nc=$(date +%s)" | grep -i last-modified
# 2. Reply to that thread
# 3. Confirm the addon's Redis cache key is gone:
redis-cli GET "wf_co:thread:NNN"   # should be (nil)
# 4. Re-fetch with bypass; Last-Modified should now match the new reply
curl -sI "https://windowsforum.com/threads/some-news-thread.NNN/?_nc=$(date +%s)" | grep -i last-modified
```
