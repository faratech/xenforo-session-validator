<?php

namespace WindowsForum\SessionValidator\Service;

/**
 * XenForo Session Validator for Cloudflare Security Rules
 * 
 * This service validates XenForo sessions and adds verification headers
 * that can be used in Cloudflare security rules for enhanced authentication.
 * 
 * Simplified version that relies on XenForo's internal session validation
 * instead of performing redundant checks.
 */
class SessionValidator
{
    /**
     * Main function - sets headers based on XenForo's already-validated session
     * @return void
     */
    public function validateAndSetHeaders()
    {
        try
        {
            // Security check: Only set headers for Cloudflare requests
            if (!$this->isCloudflareRequest())
            {
                // Not from Cloudflare, don't expose any headers
                return;
            }
            
            // Get the visitor (current user) - already validated by XenForo
            $visitor = \XF::visitor();
            
            // If user is logged in, set user headers
            if ($visitor->user_id > 0)
            {
                $this->setUserVerificationHeaders([
                    'user_id' => $visitor->user_id,
                    'username' => $visitor->username,
                    'is_staff' => $visitor->is_staff,
                    'is_admin' => $visitor->is_admin,
                    'is_moderator' => $visitor->is_moderator
                ]);
            }
            else
            {
                // Guest user - just set session headers
                $this->setSessionVerificationHeaders();
            }
        }
        catch (\Exception $e)
        {
            // If anything fails, log it but don't disrupt the request
            error_log("XenForo Session Validator Error: " . $e->getMessage());
        }
    }
    
    /**
     * Check if the request is coming through Cloudflare
     * Uses the same logic as XenForo's internal IP handling
     * @return bool
     */
    private function isCloudflareRequest()
    {
        // Check if Cloudflare-only mode is enabled
        $cloudflareOnly = \XF::options()->wfSessionValidator_cloudflareOnly ?? true;
        if (!$cloudflareOnly)
        {
            // Admin has disabled Cloudflare-only mode, allow all requests
            return true;
        }
        
        // Get the request object
        $request = \XF::app()->request();
        
        // Check if we have CF-Connecting-IP header
        $cfConnectingIp = $request->getServer('HTTP_CF_CONNECTING_IP');
        if (empty($cfConnectingIp))
        {
            return false;
        }
        
        // Get the actual remote IP (the IP that connected to the server)
        $remoteIp = $request->getServer('REMOTE_ADDR');
        
        // If CF-Connecting-IP exists and is different from REMOTE_ADDR,
        // AND the remote IP is from Cloudflare's ranges, then it's a valid CF request
        // This is exactly how XenForo validates it in getTrustedRealIp()
        if ($cfConnectingIp !== $remoteIp && $this->ipMatchesCloudflareRanges($remoteIp))
        {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if an IP matches Cloudflare's IP ranges
     * Reimplemented since XenForo's method is protected
     * @param string $ip
     * @return bool
     */
    private function ipMatchesCloudflareRanges($ip)
    {
        if (!$ip)
        {
            return false;
        }
        
        // Check against Cloudflare's IP ranges from XenForo's Request class
        $ranges = \XF\Http\Request::$cloudFlareIps;
        
        // Determine if IPv4 or IPv6
        $isIpv6 = strpos($ip, ':') !== false;
        $checkRanges = $isIpv6 ? ($ranges['v6'] ?? []) : ($ranges['v4'] ?? []);
        
        foreach ($checkRanges as $range)
        {
            // Parse CIDR notation
            if (strpos($range, '/') !== false)
            {
                list($rangeIp, $cidr) = explode('/', $range);
                if (\XF\Util\Ip::ipMatchesCidrRange($ip, $rangeIp, $cidr))
                {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Set full user verification headers
     * @param array $userData
     * @return void
     */
    private function setUserVerificationHeaders($userData)
    {
        // Only set headers if they haven't been sent yet
        if (!headers_sent())
        {
            // Always set the primary validation headers
            header('XF-Verified-User: true');
            header('XF-Verified-Session: true');
            header('XF-Session-Validated: ' . date('c'));
            
            // Check if verbose output is enabled
            $verboseOutput = \XF::options()->wfSessionValidator_verboseOutput ?? false;
            if ($verboseOutput)
            {
                header('XF-User-ID: ' . $userData['user_id']);
                header('XF-Username: ' . $userData['username']);
                header('XF-Is-Staff: ' . ($userData['is_staff'] ? 'true' : 'false'));
                header('XF-Is-Admin: ' . ($userData['is_admin'] ? 'true' : 'false'));
                header('XF-Is-Moderator: ' . ($userData['is_moderator'] ? 'true' : 'false'));
            }
        }
    }
    
    /**
     * Set session-only verification headers
     * @return void
     */
    private function setSessionVerificationHeaders()
    {
        // Only set headers if they haven't been sent yet
        if (!headers_sent())
        {
            header('XF-Verified-Session: true');
            header('XF-Session-Validated: ' . date('c'));
        }
    }
}