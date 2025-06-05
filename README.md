# XenForo Session Validator for Cloudflare

A XenForo 2.2+ add-on that validates user sessions server-side and provides verification headers for Cloudflare WAF rules, enabling advanced security configurations.

## Overview

This add-on validates XenForo sessions against the actual database, not just cookies. It checks:
- Active sessions in the `xf_session_activity` table
- Remember cookies against the `xf_user_remember` table  
- CSRF tokens for basic session validation

This provides much stronger security than simple cookie-based rules.

## How It Works

1. **Session Validation Priority**:
   - **Active Session**: First checks if there's an active XenForo session with a logged-in user
   - **Remember Cookie**: Validates "Stay logged in" cookies against the `xf_user_remember` table
   - **CSRF Token**: Basic session validation for guests or expired sessions

2. **Cookie Handling**: Uses XenForo's request object to properly handle cookie prefixes:
   - `xf_session`: The session identifier
   - `xf_user`: Remember me cookie (format: "userId,rememberKey")
   - `xf_csrf`: CSRF protection token (format: "timestamp,hash")

3. **Database Verification**:
   - Uses XenForo's built-in repositories for proper validation
   - Checks `xf_session_activity` table for recent user activity
   - Validates remember cookies against `xf_user_remember` table
   - Respects configured activity window timeout

4. **Headers**: Based on validation results, it sets HTTP headers that Cloudflare can use:
   - `XF-Verified-User: true` - User is fully authenticated
   - `XF-Verified-Session: true` - Valid session exists
   - `XF-User-ID`, `XF-Username`, etc. - Additional user details (if verbose mode enabled)

## Installation

1. Upload the `WindowsForum/SessionValidator` folder to `src/addons/`
2. Install via Admin CP → Add-ons → Install/upgrade from archive or file system
3. Configure options in Admin CP → Options → Session Validator

## Configuration Options

- **Enable Session Validator** - Master on/off switch
- **Activity Window** - How long to consider a session active (default: 3600 seconds/1 hour)
- **Verbose Output** - Include user details in headers (ID, username, permissions)

## Security Headers

### Always Set (when validated):
- `XF-Verified-User: true` - Authenticated user with valid session
- `XF-Verified-Session: true` - Valid session exists
- `XF-Session-Validated: [ISO 8601 timestamp]`

### With Verbose Output Enabled:
- `XF-User-ID: [numeric user ID]`
- `XF-Username: [username]`
- `XF-Is-Staff: true/false`
- `XF-Is-Admin: true/false`
- `XF-Is-Moderator: true/false`

## Cloudflare WAF Rule Examples

### Example Cloudflare WAF Rules

#### Basic Bot Protection
```
# Block requests to protected areas without valid XenForo session
(http.request.uri.path contains "/forums/" and 
 not any(http.request.headers.names[*] contains "XF-Verified-Session"))
```

#### Protect Admin/Moderator Areas
```
# Only allow staff to admin areas
(http.request.uri.path contains "/admin.php" and 
 not http.request.headers["xf-is-admin"][0] eq "true")
```

#### Rate Limiting for Non-Authenticated Users
```
# Aggressive rate limiting for non-authenticated users
(not any(http.request.headers.names[*] contains "XF-Verified-User") and 
 rate(5m) > 100)
```

#### Allow Verified Users Through Challenge
```
# Skip challenges for authenticated users
(cf.bot_management.score lt 30 and 
 http.request.headers["xf-verified-user"][0] eq "true")
Action: Skip remaining rules
```

### Advanced Examples

#### Protect Attachments
```
# Require authentication for attachment downloads
(http.request.uri.path contains "/attachments/" and 
 not http.request.headers["xf-verified-user"][0] eq "true")
Action: Block
```

#### Geographic + Authentication Combined
```
# Allow only authenticated users from specific countries
(not ip.geoip.country in {"US" "CA" "GB"} and 
 not http.request.headers["xf-verified-user"][0] eq "true")
Action: Managed Challenge
```

#### Protect Against Scraping
```
# Block rapid requests to thread pages from non-members
(http.request.uri.path matches "^/threads/.*" and 
 not http.request.headers["xf-verified-user"][0] eq "true" and
 rate(1m) > 20)
Action: Block
```

## Technical Details

### Cookie Processing
- Uses XenForo's request object for proper cookie handling with prefixes
- Validates remember cookies using XenForo's built-in `UserRememberRepository`
- Properly parses CSRF tokens with timestamp validation
- Handles missing or malformed cookies gracefully

### Performance Optimizations
- Uses XenForo's existing database connection (`\XF::db()`)
- Leverages XenForo's repository pattern for efficient queries
- Minimal database queries - only when necessary
- Only sets headers if not already sent

### Security Features
- Validates remember cookies against `xf_user_remember` table
- Checks session activity within configurable time window
- CSRF token timestamp validation prevents replay attacks
- Validates user permissions (staff, admin, moderator)
- Prevents header injection with proper sanitization
- Graceful fallback for database errors

### XenForo Integration
- Fully aligned with XenForo's session handling implementation
- Uses same validation logic as XenForo core
- Respects XenForo's cookie configuration and prefixes
- Compatible with XenForo's remember me functionality

## Privacy Considerations

- User data is only included in headers when verbose output is enabled
- Headers are only visible server-side (between your server and Cloudflare)
- No sensitive data (passwords, emails, IPs) is ever included
- You control what data is exposed via the verbose output option

## Troubleshooting

### Headers Not Appearing
1. Check if the add-on is enabled in options
2. Verify cookies are being sent by the browser
3. Check error logs for database connection issues
4. Ensure headers aren't already sent by other code

### False Negatives (Valid Users Blocked)
1. Increase the activity window setting
2. Check if users have cookies enabled
3. Verify your Cloudflare rules syntax
4. Check time sync between server and database

### Performance Impact
- Minimal - adds one database query per request
- Use activity window to balance security vs performance
- Consider caching rules in Cloudflare Page Rules

## Requirements

- XenForo 2.2.0 or higher
- PHP 7.2 or higher
- Standard XenForo database access
- Headers must not be already sent

## Support

- [XenForo Community Thread](https://xenforo.com/community/)
- [GitHub Issues](https://github.com/windowsforum/xenforo-session-validator)
- [WindowsForum Support](https://windowsforum.com/forums/tech-support.40/)

## License

MIT License - see LICENSE.md

## Credits

Developed by WindowsForum for the XenForo community, with insights from the original standalone validator implementation.