# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a **XenForo 2.2+ addon** that validates user sessions server-side and provides verification headers for Cloudflare WAF rules. It properly integrates with XenForo's session management system.

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

**Event-Driven Design**: The addon hooks into XenForo's app lifecycle events (`app_setup`, `app_admin_setup`, `app_api_setup`) to validate sessions before any controller logic runs.

**Key Components**:
- `Listener.php`: Registers the session validator service early in the request
- `Service/SessionValidator.php`: Core validation logic that:
  - Checks active XenForo sessions via `\XF::session()`
  - Validates remember cookies against `xf_user_remember` table
  - Verifies CSRF tokens with timestamp validation
  - Sets HTTP headers for Cloudflare WAF

**Data Flow**:
1. Request arrives → Listener hooks app setup
2. SessionValidator checks (in priority order):
   - Active session with logged-in user
   - Remember cookie validation
   - CSRF token for basic session
3. Headers set based on validation result
4. Cloudflare WAF rules use headers for security decisions

## How the Validator Works

### Session Validation Flow (Priority Order)

1. **Active Session Check**:
   - Uses `\XF::session()` to check for active logged-in user
   - Validates against `xf_session_activity` table for recent activity
   - Most reliable method for currently active users

2. **Remember Cookie Validation**:
   - Validates `xf_user` cookie (format: "userId,rememberKey")
   - Uses `UserRememberRepository::validateByCookieValue()`
   - Checks against `xf_user_remember` table
   - Handles "Stay logged in" functionality

3. **CSRF Token Fallback**:
   - Validates `xf_csrf` token format ("timestamp,hash")
   - Checks timestamp is within reasonable range
   - Provides basic session validation for guests

### Cookie Handling
- Uses XenForo's request object: `$request->getCookie()`
- Automatically handles cookie prefix (e.g., 'xf_')
- No manual URL decoding needed

## Configuration

Options are defined in `_data/options.xml` and accessible via `\XF::options()->optionName`:
- `wfSessionValidator_enabled`: Enable/disable addon
- `wfSessionValidator_activityWindow`: Session activity timeout (default: 3600 seconds)
- `wfSessionValidator_verboseOutput`: Include detailed user info in headers

## Technical Implementation Details

### XenForo Integration
- Uses XenForo repositories for proper data access:
  - `UserRepository` - User data retrieval
  - `UserRememberRepository` - Remember cookie validation
  - `SessionActivityRepository` - Activity tracking

### Database Tables Used
- `xf_session_activity` - Tracks active user sessions
- `xf_user_remember` - Stores remember cookie tokens
- `xf_user` - User account data

### Cookie Formats
- `xf_session`: 32-character random string
- `xf_user`: "userId,rememberKey" (e.g., "123,AbCdEfGhIjKl")
- `xf_csrf`: "timestamp,hash" (e.g., "1701234567,a1b2c3d4e5f6")

## Headers Set

### Basic Validation Headers
- `XF-Verified-User: true` - Authenticated user confirmed
- `XF-Verified-Session: true` - Valid session exists
- `XF-Session-Validated: [timestamp]` - ISO 8601 validation time

### Verbose Mode Headers (optional)
- `XF-User-ID: [user_id]`
- `XF-Username: [username]`
- `XF-Is-Staff: true/false`
- `XF-Is-Admin: true/false`
- `XF-Is-Moderator: true/false`

## Troubleshooting Guide

### Issue: Headers not appearing
1. Check if add-on is enabled in Admin CP
2. Verify cookies are present: Developer Tools → Application → Cookies
3. Check XenForo's cookie prefix in config.php
4. Ensure no output before headers (check for whitespace)
5. Check error_log for exceptions

### Issue: Valid users being blocked
1. Verify activity window setting (default 3600 = 1 hour)
2. Check if user has active session: 
   ```sql
   SELECT * FROM xf_session_activity WHERE user_id = ? AND view_date > ?
   ```
3. Verify remember cookies:
   ```sql
   SELECT * FROM xf_user_remember WHERE user_id = ?
   ```
4. Check cookie domain/path settings match XenForo config

### Issue: Remember cookies not validating
1. Check xf_user_remember table has entries
2. Verify cookie format is "userId,key"
3. Ensure remember cookies haven't expired
4. Check UserRememberRepository logs

## Development Notes

- No build process - XenForo compiles at runtime
- Changes to XML files in `_data/` require rebuild or reinstall
- The `_releases/` directory is gitignored for package output
- `hashes.json` is auto-generated for file integrity
- Always use XenForo's abstractions:
  - Database: `\XF::db()` or repositories
  - Request: `\XF::app()->request()`
  - Session: `\XF::session()`

## Integration Best Practices

1. **Early Loading**: Listener on `app_setup` for early execution
2. **Repository Pattern**: Uses XenForo's repositories for consistency
3. **Error Handling**: Try-catch blocks with error logging
4. **Performance**: Minimal queries, leverages XenForo's caching
5. **Security**: 
   - Validates remember tokens properly
   - CSRF timestamp validation
   - No direct cookie manipulation
6. **Privacy**: Verbose output controlled by admin option

## Key Differences from Standalone Version

1. **Proper Cookie Validation**:
   - Old: Basic format checks
   - New: Full validation against database tables

2. **XenForo Integration**:
   - Old: Direct database queries
   - New: Repository pattern, proper abstraction

3. **Session Priority**:
   - Old: Cookie-first approach
   - New: Active session → Remember cookie → CSRF

4. **Cookie Handling**:
   - Old: Manual $_COOKIE access
   - New: XenForo's request object with prefix handling

## Testing Checklist

1. [ ] Install addon via Admin CP
2. [ ] Enable in options
3. [ ] Test with logged-in user (should set XF-Verified-User)
4. [ ] Test with remember cookie (logout, close browser, return)
5. [ ] Test with guest (should only set XF-Verified-Session if CSRF present)
6. [ ] Check headers in browser developer tools
7. [ ] Create Cloudflare rule using headers
8. [ ] Test with activity window edge cases
9. [ ] Verify error handling with DB issues