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
     */
    public function validateAndSetHeaders()
    {
        try
        {
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
     * Set full user verification headers
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