<?php

namespace WindowsForum\SessionValidator\Service;

/**
 * XenForo Session Validator for Cloudflare Security Rules
 * 
 * This service validates XenForo sessions and adds verification headers
 * that can be used in Cloudflare security rules for enhanced authentication.
 * 
 * Based on the proven gold standard xenforo-session-validator.php
 */
class SessionValidator
{
    private $config;
    private $db;
    
    public function __construct()
    {
        // Load XenForo config
        $configPath = \XF::getRootDirectory() . '/src/config.php';
        if (file_exists($configPath))
        {
            $this->config = require $configPath;
        }
    }
    
    /**
     * Main validation function - call this early in your request cycle
     * Based on the original xenforo-session-validator.php logic
     */
    public function validateAndSetHeaders()
    {
        // Process all request methods for authenticated users
        // (GET, POST, PUT, DELETE, PATCH, OPTIONS, HEAD, etc.)
        
        $sessionId = $_COOKIE['xf_session'] ?? null;
        $userCookie = $_COOKIE['xf_user'] ?? null;
        $csrfToken = $_COOKIE['xf_csrf'] ?? null;
        
        // Scenario 1: Full user authentication (all 3 cookies) - from original
        if ($sessionId && $userCookie && $csrfToken && strlen($sessionId) === 32)
        {
            // URL decode the user cookie first
            $userCookie = urldecode($userCookie);
            
            // Extract user ID from xf_user cookie (format: "userID,hash")
            $userId = $this->extractUserIdFromCookie($userCookie);
            if ($userId > 0)
            {
                // Validate the full user session
                $validationResult = $this->validateUserSession($sessionId, $userId, $userCookie);
                
                if ($validationResult && $validationResult['is_valid'])
                {
                    // Add full user verification headers
                    $this->setUserVerificationHeaders($validationResult);
                    return true;
                }
            }
        }
        
        // Scenario 2: Session-only verification (just CSRF token) - from original
        if ($csrfToken && !empty($csrfToken))
        {
            $sessionValidation = $this->validateCsrfSession($csrfToken);
            if ($sessionValidation)
            {
                // Add session-only verification header
                $this->setSessionVerificationHeaders();
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Extract user ID from XenForo user cookie
     */
    private function extractUserIdFromCookie($userCookie)
    {
        $parts = explode(',', $userCookie);
        if (count($parts) >= 1)
        {
            $userId = intval($parts[0]);
            return $userId > 0 ? $userId : 0;
        }
        return 0;
    }
    
    /**
     * Validate full user session against XenForo database
     * Based on the original xenforo-session-validator.php
     */
    private function validateUserSession($sessionId, $userId, $userCookie)
    {
        try
        {
            // Get database connection
            $db = $this->getDatabase();
            if (!$db)
            {
                return false;
            }
            
            // Check if user exists and has recent activity (within configured window)
            $stmt = $db->prepare("
                SELECT sa.user_id, u.username, u.is_staff, u.is_admin, u.is_moderator 
                FROM xf_session_activity sa
                LEFT JOIN xf_user u ON sa.user_id = u.user_id 
                WHERE sa.user_id = ? AND sa.user_id > 0 AND sa.view_date > ?
                ORDER BY sa.view_date DESC
                LIMIT 1
            ");
            
            // Get activity window from XenForo options (default 24 hours)
            $activityWindow = \XF::options()->wfSessionValidator_activityWindow ?? 86400;
            $recentTime = time() - $activityWindow;
            $stmt->execute([$userId, $recentTime]);
            $userResult = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($userResult && $userResult['user_id'] == $userId)
            {
                // Additional validation: check if user cookie format is reasonable
                if ($this->validateUserCookieFormat($userCookie, $userId))
                {
                    return [
                        'is_valid' => true,
                        'user_id' => $userResult['user_id'],
                        'username' => $userResult['username'] ?: '',
                        'is_staff' => (bool)$userResult['is_staff'],
                        'is_admin' => (bool)$userResult['is_admin'],
                        'is_moderator' => (bool)$userResult['is_moderator']
                    ];
                }
            }
        }
        catch (\Exception $e)
        {
            error_log("XenForo Session Validator Error: " . $e->getMessage());
        }
        
        return false;
    }
    
    /**
     * Validate user cookie format (basic check for XenForo format)
     */
    private function validateUserCookieFormat($userCookie, $expectedUserId)
    {
        $parts = explode(',', $userCookie);
        
        // Must have at least user ID and hash
        if (count($parts) < 2)
        {
            return false;
        }
        
        // First part must be the user ID
        $cookieUserId = intval($parts[0]);
        if ($cookieUserId !== $expectedUserId)
        {
            return false;
        }
        
        // Second part should be a hash (base64-like characters)
        $hash = $parts[1];
        if (strlen($hash) < 10 || !preg_match('/^[A-Za-z0-9_-]+$/', $hash))
        {
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate CSRF token format (basic session verification)
     */
    private function validateCsrfSession($csrfToken)
    {
        // Basic CSRF token format validation for XenForo
        // XenForo CSRF tokens are typically base64-like strings
        if (strlen($csrfToken) >= 8 && preg_match('/^[A-Za-z0-9_-]+$/', $csrfToken))
        {
            return true;
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
        
        if (!$this->config || !isset($this->config['db']))
        {
            return null;
        }
        
        try
        {
            $dbConfig = $this->config['db'];
            $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['dbname']};charset=utf8mb4";
            
            $this->db = new \PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT => 5
            ]);
            
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