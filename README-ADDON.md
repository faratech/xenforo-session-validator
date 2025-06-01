# Session Validator for Cloudflare

A XenForo 2.2+ add-on that validates user sessions and adds verification headers for use with Cloudflare's Web Application Firewall (WAF) rules. This allows you to create security rules that treat authenticated forum members differently from guests or bots.

## Features

- **Automatic Session Validation**: Validates XenForo sessions early in the request cycle
- **Security Headers**: Adds custom headers that Cloudflare can read to identify verified users
- **Flexible Configuration**: Control what information is exposed via headers
- **Lightweight**: Minimal performance impact with efficient database queries
- **Privacy Conscious**: Verbose output can be disabled to limit exposed information

## Requirements

- XenForo 2.2.0 or higher
- PHP 7.2 or higher
- Cloudflare (Free, Pro, Business, or Enterprise plan)

## Installation

1. Download the add-on package
2. Extract the contents to `src/addons/WindowsForum/SessionValidator/`
3. In your XenForo Admin Control Panel, go to **Add-ons**
4. Click **Install/upgrade from archive** or **Install from file system**
5. Select the Session Validator add-on and click **Install**

## Configuration

### XenForo Settings

Navigate to **Admin CP → Options → Session Validator** to configure:

- **Enable Session Validator**: Turn the add-on on/off
- **Activity Window**: How long (in seconds) a user is considered active (default: 24 hours)
- **Verbose Output**: Whether to include detailed user information in headers

### Headers Explained

The add-on sets the following HTTP headers that Cloudflare can read:

#### Always Set (when validated):
- `XF-Verified-User: true` - User has valid session with all required cookies
- `XF-Verified-Session: true` - Valid session exists
- `XF-Session-Validated: [timestamp]` - When validation occurred

#### With Verbose Output Enabled:
- `XF-User-ID: [user_id]` - The user's numeric ID
- `XF-Username: [username]` - The user's username
- `XF-Is-Staff: true/false` - Whether user is staff
- `XF-Is-Admin: true/false` - Whether user is admin
- `XF-Is-Moderator: true/false` - Whether user is moderator

## Cloudflare Configuration

### Creating WAF Rules

1. Log into your Cloudflare dashboard
2. Select your domain
3. Go to **Security → WAF → Custom rules**
4. Click **Create rule**

### Example Rules

#### Skip Security Checks for Verified Users
```
Rule name: Allow Verified Forum Users
Expression: (http.request.headers["xf-verified-user"][0] eq "true")
Action: Skip → All remaining custom rules
```

#### Rate Limit Non-Members More Aggressively
```
Rule name: Strict Rate Limit for Guests
Expression: (not http.request.headers["xf-verified-session"][0] eq "true") and (http.request.uri.path contains "/forums/")
Action: Block
```

#### Allow Staff Bypass During Attacks
```
Rule name: Staff Always Allowed
Expression: (http.request.headers["xf-is-staff"][0] eq "true")
Action: Skip → All security measures
```

#### Block Specific Actions for Non-Members
```
Rule name: Members Only Actions
Expression: (http.request.uri.path contains "/post-thread" or http.request.uri.path contains "/post-reply") and (not http.request.headers["xf-verified-user"][0] eq "true")
Action: Block
```

### Important Cloudflare Settings

1. **Page Rules**: Ensure "Cache Level: Bypass" for paths like `/admin.php`, `/login/`, etc.
2. **Configuration Rules**: Set "Cache Eligibility: Bypass cache" when cookie `xf_user` exists
3. **Security Level**: Can be set higher since members won't see challenges

## How It Works

1. The add-on runs very early in XenForo's request cycle
2. It checks for XenForo session cookies (`xf_session`, `xf_user`, `xf_csrf`)
3. When all three cookies are present, it validates against the database
4. If validation succeeds, it sets HTTP headers before any output
5. Cloudflare reads these headers and applies your custom rules

## Security Considerations

- **Verbose Output**: Only enable if you need the extra information. User IDs and usernames in headers could be logged by proxies.
- **HTTPS Required**: Always use HTTPS to prevent header spoofing
- **Rule Order**: Place skip rules before block rules in Cloudflare
- **Cache Headers**: The validator doesn't interfere with XenForo's cache headers

## Troubleshooting

### Headers Not Appearing

1. Check if the add-on is enabled in XenForo options
2. Verify you're logged into the forum
3. Clear Cloudflare cache: **Caching → Configuration → Purge Everything**
4. Check cookies are being sent: Browser DevTools → Network → Request Headers

### Validation Not Working

- Ensure all three cookies are present: `xf_session`, `xf_user`, `xf_csrf`
- Check user has activity within the configured window (default 24 hours)
- Verify database connectivity in XenForo

### Testing Headers

```bash
# Test with curl (replace with your actual cookies)
curl -I -H "Cookie: xf_session=YOUR_SESSION; xf_user=YOUR_USER; xf_csrf=YOUR_CSRF" https://yourdomain.com/

# Look for XF-* headers in response
```

## Performance

- Adds one database query per request (cached by XenForo)
- Headers are set before any output processing
- No impact on page rendering time
- Works with XenForo's built-in caching

## Privacy Policy

If you enable verbose output, consider updating your privacy policy to mention that user identifiers may be included in HTTP headers for security purposes.

## Support

- **Bug Reports**: Please include XenForo version, PHP version, and any error messages
- **Feature Requests**: We welcome suggestions for improvement
- **Cloudflare Help**: See [Cloudflare's WAF documentation](https://developers.cloudflare.com/waf/custom-rules/)

## License

This add-on is released under the MIT License. See LICENSE file for details.

## Credits

Developed by WindowsForum for the XenForo community. Based on proven session validation techniques.