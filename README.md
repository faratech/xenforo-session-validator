# XenForo Session Validator for Cloudflare

A XenForo 2.2+ add-on that validates user sessions and provides verification headers for Cloudflare WAF rules, enabling advanced security configurations.

## 🛡️ Overview

This add-on bridges XenForo's session management with Cloudflare's Web Application Firewall, allowing you to create sophisticated security rules based on actual session validation rather than just cookie presence.

## ✨ Key Features

- **Server-side session validation** - Validates sessions against XenForo's database, not just cookies
- **Security headers** - Adds HTTP headers that Cloudflare WAF rules can use
- **Flexible configuration** - Control verbosity and activity windows
- **Lightweight** - Minimal performance impact
- **Privacy-conscious** - Optional verbose output

## 🚀 Installation

1. Download the latest release from the [Releases](../../releases) page
2. Extract to `src/addons/WindowsForum/SessionValidator/`
3. Install via XenForo Admin CP → Add-ons
4. Configure in Admin CP → Options → Session Validator
5. Create Cloudflare WAF rules using the headers

## 📋 Requirements

- XenForo 2.2.0 or higher
- PHP 7.2 or higher
- Cloudflare account (Free tier works!)

## 🔧 Configuration

### Available Options

- **Enable Session Validator** - Turn validation on/off
- **Activity Window** - How long to consider sessions active (default: 24 hours)
- **Verbose Output** - Include detailed user information in headers

### Headers Set

Basic validation:
- `XF-Verified-User: true`
- `XF-Verified-Session: true`
- `XF-Session-Validated: [timestamp]`

With verbose output:
- `XF-User-ID: [user_id]`
- `XF-Username: [username]`
- `XF-Is-Staff: true/false`
- `XF-Is-Admin: true/false`

## 🔥 Cloudflare WAF Examples

### Block non-members from attachments
```
(http.request.uri.path contains "/attachments/" and 
 not http.request.headers["xf-verified-user"][0] eq "true")
Action: Block
```

### Rate limit guests
```
(not http.request.headers["xf-verified-session"][0])
Action: Rate Limit (10 requests/minute)
```

### Protect member areas
```
(http.request.uri.path contains "/account/" and 
 not http.request.headers["xf-verified-user"][0] eq "true")
Action: Managed Challenge
```

## 🤝 Why Server Validation Matters

Unlike simple cookie-based rules, this add-on:
- Validates cookies against the actual session database
- Checks for recent activity within your configured window
- Prevents cookie replay attacks
- Uses XenForo's session_activity table as the single source of truth

## 📊 Cloudflare Plan Compatibility

Works with all Cloudflare plans:
- **Free**: 5 active custom rules
- **Pro**: 20 active custom rules
- **Business**: 100 active custom rules
- **Enterprise**: 1000+ active custom rules

## 🔗 Resources

- [XenForo Resource Page](https://xenforo.com/community/resources/)
- [Support Thread](https://xenforo.com/community/threads/)
- [DigitalPoint Cloudflare Add-on](https://xenforo.com/community/resources/digitalpoint-app-for-cloudflare-r.8750/) - Recommended companion

## 📄 License

This project is licensed under the MIT License - see the [LICENSE.md](LICENSE.md) file for details.

## 🤖 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## 🐛 Issues

Found a bug? Please open an issue with:
- XenForo version
- PHP version
- Steps to reproduce
- Expected vs actual behavior

## 💡 Credits

Developed by [WindowsForum](https://windowsforum.com) for the XenForo community.

---

**Note**: This is not affiliated with or endorsed by Cloudflare, Inc. Cloudflare is a registered trademark of Cloudflare, Inc.