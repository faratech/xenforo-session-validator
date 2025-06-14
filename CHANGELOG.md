# Changelog

## Version 1.3.2

### Enhanced Cache Management for Authentication
- **Improved session cookie detection**: Now properly detects XenForo's session cookies (`xf_session` and `xf_user`) with support for custom cookie prefixes
- **Authentication route protection**: Login, logout, and other authentication pages now always receive no-cache headers
- **Better cache busting**: Added multiple layers of cache prevention for authenticated users:
  - Checks both visitor object and authentication cookies
  - Disables XenForo's built-in page caching via `controller_post_dispatch` event
  - Forces aggressive no-cache headers for logged-in users
  - Uses Cloudflare-specific headers (`Cloudflare-CDN-Cache-Control`) to prevent edge caching
- **Improved header clearing**: Now uses both XenForo's `removeHeader()` and PHP's `header_remove()` for better compatibility
- **Debug headers**: Added `X-Cache-Optimizer` header to identify which cache rules are being applied

### Cookie Configuration
The addon now properly respects XenForo's cookie configuration:
- Default cookie prefix: `xf_`
- Session cookie: `[prefix]session`
- User (remember me) cookie: `[prefix]user`
- Admin cookie: `[prefix]admin`

### Vary Header Improvements
- Cache now varies on specific authentication cookies instead of all cookies
- Includes `Accept-Encoding` in Vary header for proper compression handling
- More efficient cache key generation at CDN level

### Key Changes
1. Added `isUserAuthenticated()` method that checks both visitor object and cookies
2. Added `isAuthenticationRoute()` method to identify login/logout pages
3. Enhanced `controllerPostDispatch` listener to disable XenForo's page cache for authenticated users
4. Improved cache header management with Cloudflare-specific directives
5. Added fallback header removal using PHP's native functions

## Version 1.3.1
- Previous version changes...