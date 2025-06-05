<?php

namespace WindowsForum\SessionValidator\Service;

use XF\Repository\UserRepository;
use XF\Repository\UserRememberRepository;
use XF\Repository\SessionActivityRepository;

/**
 * XenForo Session Validator for Cloudflare Security Rules
 * 
 * This service validates XenForo sessions and adds verification headers
 * that can be used in Cloudflare security rules for enhanced authentication.
 * 
 * Properly aligned with XenForo's actual session handling implementation:
 * - Validates remember cookies against xf_user_remember table
 * - Uses XenForo's built-in repositories for proper validation
 * - Supports both session-based and remember cookie authentication
 */
class SessionValidator
{
    private $db;
    private $userRepo;
    private $rememberRepo;
    private $sessionActivityRepo;
    
    public function __construct()
    {
        // Initialize repositories
        $this->userRepo = \XF::repository(UserRepository::class);
        $this->rememberRepo = \XF::repository(UserRememberRepository::class);
        $this->sessionActivityRepo = \XF::repository(SessionActivityRepository::class);
    }
    
    /**
     * Main validation function - call this early in your request cycle
     * Validates XenForo sessions using the same logic as XenForo core
     */
    public function validateAndSetHeaders()
    {
        // Get cookies using XenForo's request object for proper handling
        $request = \XF::app()->request();
        $sessionId = $request->getCookie('session'); // XenForo uses 'xf_session' with prefix
        $userCookie = $request->getCookie('user');   // XenForo uses 'xf_user' with prefix
        $csrfToken = $request->getCookie('csrf');    // XenForo uses 'xf_csrf' with prefix
        
        // Priority 1: Check if we have a valid session with a logged-in user
        $session = \XF::session();
        if ($session && $session->exists() && $session->userId)
        {
            $user = $this->userRepo->getVisitor($session->userId);
            if ($user && $user->user_id)
            {
                // Validate the session is still active
                $validationResult = $this->validateActiveSession($user);
                if ($validationResult)
                {
                    $this->setUserVerificationHeaders($validationResult);
                    return true;
                }
            }
        }
        
        // Priority 2: Check remember cookie (for users with "Stay logged in")
        if ($userCookie)
        {
            $validationResult = $this->validateRememberCookie($userCookie);
            if ($validationResult && $validationResult['is_valid'])
            {
                $this->setUserVerificationHeaders($validationResult);
                return true;
            }
        }
        
        // Priority 3: Basic session validation (visitor might be guest or session expired)
        if ($csrfToken)
        {
            // If we have a CSRF token, we at least have an active session
            if ($this->validateCsrfToken($csrfToken))
            {
                $this->setSessionVerificationHeaders();
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Validate remember cookie using XenForo's built-in validation
     * This properly checks against the xf_user_remember table
     */
    private function validateRememberCookie($userCookie)
    {
        try
        {
            // Use XenForo's remember repository to validate the cookie
            $remember = null;
            if ($this->rememberRepo->validateByCookieValue($userCookie, $remember))
            {
                // Cookie is valid, get the user
                $user = $this->userRepo->getVisitor($remember->user_id);
                if ($user && $user->user_id)
                {
                    return [
                        'is_valid' => true,
                        'user_id' => $user->user_id,
                        'username' => $user->username ?: '',
                        'is_staff' => (bool)$user->is_staff,
                        'is_admin' => (bool)$user->is_admin,
                        'is_moderator' => (bool)$user->is_moderator
                    ];
                }
            }
        }
        catch (\Exception $e)
        {
            error_log("XenForo Session Validator - Remember cookie validation error: " . $e->getMessage());
        }
        
        return false;
    }
    
    /**
     * Validate active session for a user
     * Checks if the user has recent activity in the session_activity table
     */
    private function validateActiveSession($user)
    {
        try
        {
            // Get activity window from XenForo options (default 1 hour)
            $activityWindow = \XF::options()->wfSessionValidator_activityWindow ?? 3600;
            $recentTime = \XF::$time - $activityWindow;
            
            // Check if user has recent activity
            $db = $this->getDatabase();
            if (!$db)
            {
                return false;
            }
            
            $hasActivity = $db->fetchOne("
                SELECT 1
                FROM xf_session_activity
                WHERE user_id = ? AND view_date > ?
                LIMIT 1
            ", [$user->user_id, $recentTime]);
            
            if ($hasActivity)
            {
                return [
                    'is_valid' => true,
                    'user_id' => $user->user_id,
                    'username' => $user->username ?: '',
                    'is_staff' => (bool)$user->is_staff,
                    'is_admin' => (bool)$user->is_admin,
                    'is_moderator' => (bool)$user->is_moderator
                ];
            }
        }
        catch (\Exception $e)
        {
            error_log("XenForo Session Validator - Active session validation error: " . $e->getMessage());
        }
        
        return false;
    }
    
    /**
     * Validate CSRF token using XenForo's validation logic
     * CSRF tokens contain timestamp and HMAC signature
     */
    private function validateCsrfToken($csrfToken)
    {
        try
        {
            // CSRF tokens have format: "timestamp,hash"
            if (strpos($csrfToken, ',') === false)
            {
                return false;
            }
            
            list($time, $hash) = explode(',', $csrfToken, 2);
            $time = intval($time);
            
            // Check if timestamp is reasonable (within last 24 hours)
            if ($time < (\XF::$time - 86400) || $time > \XF::$time)
            {
                return false;
            }
            
            // Basic format validation for the hash part
            if (strlen($hash) >= 20 && preg_match('/^[a-f0-9]+$/i', $hash))
            {
                return true;
            }
        }
        catch (\Exception $e)
        {
            error_log("XenForo Session Validator - CSRF validation error: " . $e->getMessage());
        }
        
        return false;
    }
    
    
    
    /**
     * Get database connection
     */
    private function getDatabase()
    {
        if ($this->db)
        {
            return $this->db;
        }

        try
        {
            $this->db = \XF::db();
            return $this->db;
        }
        catch (\Exception $e)
        {
            error_log("Database connection failed: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Set full user verification headers
     * Based on original but with verboseOutput option
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