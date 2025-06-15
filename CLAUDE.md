# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a **XenForo 2.2+ addon** (version 1.3.4) that provides two main features:
1. **Session Validator**: Validates user sessions server-side and provides verification headers for Cloudflare WAF rules
2. **Cache Optimizer**: Sets intelligent cache headers based on content age with special handling for Windows News forum

**Important**: This addon is designed to work within XenForo's framework at `/web/public_html/`. All commands must be run from the XenForo root directory, not from the addon directory.

## Essential Commands

```bash
# Install addon (from XenForo root)
php cmd.php xf-addon:install WindowsForum/SessionValidator

# Rebuild addon data after changes
php cmd.php xf-addon:rebuild WindowsForum/SessionValidator

# Export development changes to XML files
php cmd.php xf-addon:export WindowsForum/SessionValidator

# Update version number
php cmd.php xf-addon:bump-version WindowsForum/SessionValidator

# Build release package
php cmd.php xf-addon:build-release WindowsForum/SessionValidator
```

## Architecture

### Event-Driven Design
The addon hooks into XenForo's app lifecycle events:
- `app_setup`, `app_admin_setup`, `app_api_setup`: Early session validation
- `controller_post_dispatch`: Disables XenForo page caching for logged-in users
- `app_pub_complete`: Late-stage cache header optimization

### Key Components

**SessionValidator** (`Service/SessionValidator.php`):
- Validates sessions using XenForo's visitor system
- Only exposes headers to Cloudflare requests (security feature)
- Sets verification headers for WAF rules

**CacheOptimizer** (`Service/CacheOptimizer.php`):
- Sets cache headers based on content type and age
- Special handling for Windows News forum (node ID 4)
- Prevents caching for logged-in users

**Listener** (`Listener.php`):
- Registers services at appropriate lifecycle events
- Checks addon options before execution

## How It Works

### Session Validation
1. Checks if request is from Cloudflare (via CF-Connecting-IP header)
2. Uses XenForo's visitor object (`\XF::visitor()`) for authentication
3. Sets headers based on user status:
   - `XF-Verified-User: true` - Authenticated user
   - `XF-Verified-Session: true` - Valid session exists
   - Additional headers in verbose mode (user ID, permissions)

### Cache Optimization
1. Analyzes route path to determine content type
2. For threads: Calculates age and sets cache duration accordingly
3. Windows News (node 4) gets extended cache times
4. Logged-in users always get no-cache headers

## Configuration Options

Defined in `_data/options.xml`:

### Session Validator Options
- `wfSessionValidator_enabled`: Enable session validation
- `wfSessionValidator_cloudflareOnly`: Only set headers for Cloudflare requests
- `wfSessionValidator_verboseOutput`: Include user details in headers

### Cache Optimizer Options
- `wfCacheOptimizer_enabled`: Enable cache optimization
- `wfCacheOptimizer_extendedCacheNodes`: Comma-separated node IDs for extended cache (default: "4")
- `wfCacheOptimizer_homepage`: Homepage cache duration (default: 600)
- `wfCacheOptimizer_homepageEdgeCache`: Homepage edge cache s-maxage (default: 600)

### Thread Age Thresholds
- `wfCacheOptimizer_ageThreshold1Day`: Fresh content threshold (default: 86400)
- `wfCacheOptimizer_ageThreshold7Days`: Recent content threshold (default: 604800)
- `wfCacheOptimizer_ageThreshold30Days`: Older content threshold (default: 2592000)
- `wfCacheOptimizer_ancientThreshold`: Ancient content threshold (default: 315360000 = 10 years)

### Cache Durations
Standard nodes:
- `wfCacheOptimizer_threadFresh`: <24h old threads (default: 600)
- `wfCacheOptimizer_thread1Day`: 1-7 days old (default: 7200)
- `wfCacheOptimizer_thread7Days`: 7-30 days old (default: 86400)
- `wfCacheOptimizer_thread30Days`: 30+ days old (default: 604800)
- `wfCacheOptimizer_ancientCache`: Ancient content (default: 31536000 = 1 year)

Extended cache nodes:
- `wfCacheOptimizer_extendedThreadFresh`: <24h old (default: 3600)
- `wfCacheOptimizer_extendedThread1Day`: 1-7 days (default: 86400)
- `wfCacheOptimizer_extendedThread7Days`: 7-30 days (default: 604800)
- `wfCacheOptimizer_extendedThread30Days`: 30+ days (default: 2592000)

## Technical Implementation

### XenForo Integration
- Uses `\XF::visitor()` for user authentication
- Leverages `\XF::app()->request()` for request data
- Respects XenForo's response object for header management

### Security Features
- Cloudflare IP validation using `\XF\Http\Request::$cloudFlareIps`
- Uses `\XF\Util\Ip::ipMatchesCidrRange()` for IP range checking
- Headers only exposed to verified Cloudflare requests

### Cache Header Protection
- **Force Headers Mode**: Aggressively overwrites headers from XenForo/other addons
- **Early Intervention**: Uses `controller_post_dispatch` to disable XenForo's page caching
- **Multiple Clear Methods**: Both XenForo's removeHeader() and PHP's header_remove()
- **Cloudflare-CDN-Cache-Control**: Sets specific header for Cloudflare to respect
- **X-Cache-Optimizer**: Identifies our headers for debugging

### Cache Strategy
- **Fresh content** (<24h): Short cache (10 min - 1 hour)
- **Recent content** (1-7 days): Medium cache (2-24 hours)
- **Older content** (7-30 days): Long cache (1-7 days)
- **Archived content** (>30 days): Extended cache (7-30 days)
- **Ancient content** (>10 years): Maximum cache (1 year)
- **Extended cache nodes**: Get longer cache times at each tier
- **Edge cache (s-maxage)**: Set separately for CDN/proxy caching

## Development Notes

- No build process - XenForo compiles at runtime
- Changes to XML files in `_data/` require rebuild command
- Headers must be set before any output
- Always check `headers_sent()` before setting headers
- Use try-catch blocks to prevent disrupting requests

## Testing Checklist

1. [ ] Enable addon in Admin CP options
2. [ ] Verify headers appear in browser dev tools (Network tab)
3. [ ] Test with logged-in user (should see verification headers)
4. [ ] Test with guest (should see session headers only)
5. [ ] Verify cache headers on different content types
6. [ ] Check Windows News threads get extended cache
7. [ ] Confirm headers only appear for Cloudflare requests
8. [ ] Test error handling with invalid thread/forum IDs

## Common Development Tasks

### Modify Session Validation Logic
Edit `Service/SessionValidator.php` to change how sessions are validated or which headers are set.

### Add New Cache Rules
Edit `Service/CacheOptimizer.php` to add new content types or modify cache durations.

### Update Configuration Options
1. Edit `_data/options.xml` to add/modify options
2. Run `php cmd.php xf-addon:rebuild WindowsForum/SessionValidator` from XenForo root
3. New options appear in Admin CP → Options

### Debug Header Issues
1. Enable debug mode in `/web/public_html/src/config.php`: `$config['debug'] = true;`
2. Add logging in services: `\XF::logError('Debug: ' . $message);`
3. Check XenForo error logs in Admin CP → Logs → Server error log

## Cloudflare Integration Notes

- Headers are only visible to Cloudflare when `wfSessionValidator_cloudflareOnly` is enabled
- Use Cloudflare's WAF rules to create security policies based on these headers
- The addon validates against Cloudflare's known IP ranges for security
- See README.md for example Cloudflare WAF rules