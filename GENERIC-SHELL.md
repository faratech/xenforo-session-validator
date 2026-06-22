# Generic Shell Capsule

This note documents the XenForo no-bootstrap generic shell path added for WindowsForum.
It is intentionally close to `WindowsForum/SessionValidator` because the add-on publishes
and invalidates the Redis fragments, while `pagecache.php` serves them before XF loads.

## Goal

Avoid booting XenForo on hot public-view requests when the DB1 XF page-cache entry is
missing, corrupt, or too old, and avoid full XF reloads for capsule members when their
member chrome can hydrate asynchronously.

Target behavior:

- Guests: serve a public generic shell from Redis DB1, then let normal client-side code run.
- Members with the existing `xf_wf_capsule_member=1` marker: serve the same public shell
  with private/no-store headers, then hydrate account navigation, counts, CSRF, and live
  config from `wf-capsule.php` or local stale snapshot.
- No new cookies. The path uses the already-present capsule marker/bypass cookies only.

## Main Files

- `pagecache.php`
  - Defines the pre-bootstrap generic shell reader.
  - Allows only marked capsule members past the old logged-in bypass.
  - Emits `PREBGENERIC` / `PREBGENERICSTALE` when Redis shell output is served.
  - Uses private/no-store headers for member-marked requests.
- `Service/GenericShellFragment.php`
  - Publishes Redis DB1 shell fragments after a successful public XF render.
  - Indexes fragments by existing purge tags.
  - Purges fragments by tag alongside page-cache invalidation.
- `Listener.php`
  - Calls `CapsuleSnapshot::publishFromApp()`.
  - Calls `GenericShellFragment::publishFromApp()` after `CacheOptimizer` has set public
    capsule/cache headers.
- `Service/CapsuleSnapshot.php`
  - Publishes member snapshots with a short fresh window and a bounded stale window.
- `js/windowsforum/capsule/hydrate.js`
  - Accepts stale-but-usable member snapshots so a stale shell does not force a full reload.
- `Job/LiveNewsCachePurge.php` and `Service/CacheOptimizer.php`
  - Purge generic shell fragments using the same tags as page-cache/LiteSpeed.

## Redis Layout

Generic shell fragments live in Redis DB1, not DB0.

- Fragment key:
  - `wf_gs:v1:{sha256(fullUri, styleId, styleVariation, languageId, consentHash)}`
- Tag index:
  - `wf_gsidx:tag:{tag}`
- Core fields:
  - `status=ok`
  - `version=generic-shell-v1`
  - `generated_at`
  - `expires_at`
  - `stale_until`
  - `content_type`
  - `charset`
  - `route`
  - `tags`
  - `csrf_token`
  - `prefix`
  - `main`
  - `suffix`

The key tuple must stay aligned between publisher and reader. Both paths normalize the
full URI using `src/page_cache_query_normalizer.php`, then include style, variation,
language, and consent hash.

Member capsule snapshots remain in Redis DB0:

- `wf_capsule:snap:{sha256(version, xf_user, xf_session, style, variation, language)}`

## Request Flow

1. `pagecache.php` runs as the auto-prepend file before XF.
2. Non-GET, XHR, admin/API/script paths, auth routes, RSS, service-worker routes, and
   dynamic routes fall through to XF.
3. Ordinary logged-in users still fall through to XF.
4. Marked capsule members may continue only when:
   - `xf_wf_capsule_member=1`
   - no `xf_wf_capsule_bypass`
   - no admin/API marker cookies
   - route is allowlisted as a public view route
5. DB1 XF page-cache is checked first.
6. If DB1 page-cache is absent/corrupt/expired/error-body, `wf_gs:v1:*` is checked.
7. On shell hit:
   - guest response gets public cache headers
   - member response gets private/no-store headers
   - response body is assembled from `prefix + main + suffix`
   - XF JS `now` and cached guest CSRF token are rewritten

Expected shell headers:

```text
X-XF-Cache-Status: PREBGENERIC
X-WF-Generic-Shell: hit
X-WF-Hot-Path: l0-generic-shell
X-WF-Shell-Age: <seconds>
```

Member shell responses must include:

```text
Cache-Control: private, no-store, no-cache, max-age=0
Cloudflare-CDN-Cache-Control: no-store
```

Guest shell responses may include:

```text
Cache-Control: public, max-age=<ttl>, s-maxage=<ttl>, stale-while-revalidate=600, stale-if-error=86400
Cloudflare-CDN-Cache-Control: public, max-age=<ttl>, stale-if-error=86400
X-WF-Capsule: public-shell, hydrate=account-nav-v1, max-age=<ttl>
```

## Publication Rules

`GenericShellFragment::publishFromApp()` only publishes when all are true:

- HTTP 200.
- `text/html`.
- visitor is a guest.
- response already has `X-WF-Capsule: public-shell, hydrate=account-nav-v1`.
- response does not set non-CSRF cookies.
- route is an allowlisted public view route.
- body is below the max size cap.
- body is not an XF error template.
- body has `.p-body-pageContent` or `.p-body-content` so it can be split safely.

This is deliberately conservative. If a route is not eligible, it falls back to normal
XF/page-cache behavior.

## Invalidation

Generic shell fragments are indexed and purged by the same compact tags already used by
the cache optimizer:

- `H` homepage
- `WN` whats-new
- `T{id}` thread
- `F{id}` forum
- `M{id}` media
- `A{id}` album
- `R{id}` resource
- `TS{hash}` tag slug
- `TG{id}` tag id
- `public` sweep group

Edit-time invalidation runs for every thread/post save/edit/delete via the Thread/Post
entity hooks -> `LiveNewsCacheInvalidator::flushInternalCache()` ->
`CacheOptimizer::purgePageCacheForThread()`, which purges both the DB1 page-cache index
and the `T{id}` generic shell (`GenericShellFragment::purgeByTag()`) for ALL threads
(news and non-news). Tag purges call `purgePageCacheForTag()` -> `purgeByTags()` for the
`TG{id}`/`TS{hash}` shells. The news-only `LiveNewsCachePurge` job additionally deletes
`H`, `WN`, and `F{id}` shell fragments before calling the LiteSpeed purge endpoint.

## Hydration

Member chrome hydration is intentionally asynchronous:

- `CapsuleSnapshot::TTL` is the fresh window.
- `CapsuleSnapshot::STALE_TTL` is the bounded fallback window.
- `hydrate.js` applies a local snapshot if `expires_at` is fresh or `stale_until` is still
  in the future.
- If `/wf-capsule.php` returns a miss but stale local data already hydrated the page, the
  browser does not force a full reload.

This keeps the shell usable through short Redis/session hiccups while still allowing the
next successful full XF/member response to refresh the snapshot.

## Benchmarks From 2026-06-22

These were loopback/local measurements after deployment. Re-run before drawing long-term
conclusions.

```text
direct_local n=200 bytes=163578 avg=0.628ms p50=0.497ms p95=0.852ms p99=2.699ms
direct_arm n=100 bytes=163578 avg=1.707ms p50=1.048ms p95=5.097ms p99=7.239ms
http_generic_member n=30 avg=4.172ms p50=4.125ms p95=4.519ms min=3.832ms max=4.807ms
http_generic_guest n=30 avg=5.139ms p50=5.059ms p95=5.531ms min=4.749ms max=5.625ms
http_full_xf_guest n=10 avg=410.1ms p50=203.0ms p95=288.8ms min=177.9ms max=2206.4ms
```

Interpretation:

- The no-XF PHP/Redis shell path is sub-millisecond locally and around 1 ms median on ARM.
- End-to-end HTTP loopback is around 4-5 ms because it includes httpjet/TLS/LSAPI/curl
  overhead.
- Full XF render remains roughly two orders of magnitude slower on origin misses.

## Verification Commands

Count shell fragments:

```bash
php -r '$r=new Redis(); $r->connect("127.0.0.1",6379,1); $r->select(1); echo "generic_shell_fragments=",count($r->keys("wf_gs:v1:*"))," tag_indexes=",count($r->keys("wf_gsidx:tag:*")),"\n";'
```

Direct no-XF shell reader benchmark:

```bash
php -r 'require __DIR__ . "/pagecache.php"; $r=new Redis(); $r->connect("127.0.0.1",6379,1); $r->select(1); $times=[]; for($i=0;$i<100;$i++){ $s=hrtime(true); ob_start(); $ok=wf_pagecache_generic_shell_try_serve($r,"https://windowsforum.com/",40,"",1,md5(""),"root","https","/",false); ob_get_clean(); if(!$ok){echo "miss\n"; exit(1);} $times[]=(hrtime(true)-$s)/1e6; } sort($times); printf("p50=%.3fms p95=%.3fms\n",$times[49],$times[94]);'
```

HTTP member-shell probe, forcing DB1 page-cache out of the way:

```bash
redis-cli -n 1 del 'xf:page_5c90421b164026a9b77988dbffda83d1764d090a_25_s40_sv_l1_cd41d8cd98f00b204e9800998ecf8427e_v1'
curl -sk --resolve windowsforum.com:443:127.0.0.1 \
  -H 'Cookie: xf_session=memberprobe; xf_user=memberprobe; xf_wf_capsule_member=1' \
  -D - -o /tmp/wf-member-shell.html \
  "https://windowsforum.com/?_shellmember=$(date +%s%N)" \
  | grep -iE 'x-xf-cache-status|x-wf-generic-shell|x-wf-hot-path|cache-control|cloudflare-cdn-cache-control|set-cookie'
```

Expected:

- `X-XF-Cache-Status: PREBGENERIC`
- `X-WF-Generic-Shell: hit`
- `X-WF-Hot-Path: l0-generic-shell`
- private/no-store cache headers
- no `Set-Cookie`

## Deployment Notes

For PHP-only add-on changes:

```bash
php /web/regen_addon_hashes.php WindowsForum/SessionValidator
systemctl restart httpjet-lsphp
ssh root@10.10.0.3 'systemctl restart httpjet-lsphp && systemctl is-active httpjet-lsphp'
```

Do not run a full add-on rebuild unless `_data/*.xml` changed.

## Failure Modes

- Shell miss: expected for routes that have not published a fragment yet.
- Stale shell: expected within `stale_until`; header becomes `PREBGENERICSTALE`.
- Bad route/action query: falls through to XF by design.
- Member without capsule marker or with bypass marker: falls through to XF.
- Missing hydrator or invalid member snapshot: browser may reload through existing capsule
  behavior.

The safe default is always to fall through to XenForo.
