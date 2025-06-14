<?php

namespace WindowsForum\SessionValidator\Service;

use XF\App;
use XF\Http\Response;

class CacheOptimizer
{
    protected $app;
    protected $response;
    protected $options;
    
    public function __construct()
    {
        $this->app = \XF::app();
        $this->response = $this->app->response();
        $this->options = $this->app->options();
    }
    
    /**
     * Set cache headers based on user status
     */
    public function setCacheHeaders()
    {
        // Don't set headers if they've already been sent
        if (headers_sent()) {
            return;
        }
        
        $visitor = \XF::visitor();
        
        // Clear any existing cache headers
        $this->clearCacheHeaders();
        
        if ($visitor->user_id) {
            // Logged-in users get no-cache
            $this->setNoCacheForUser();
        } else {
            // Guests get cache
            $this->setCacheForGuest();
        }
    }
    
    /**
     * Set no-cache headers for logged-in users
     */
    protected function setNoCacheForUser()
    {
        $this->response->header('Cache-Control', 'private, no-cache, max-age=0');
    }
    
    /**
     * Set cache headers for guests
     */
    protected function setCacheForGuest()
    {
        $this->response->header('Cache-Control', 'max-age=600, s-maxage=600');
    }
    
    
    
    /**
     * Clear existing cache headers
     */
    protected function clearCacheHeaders()
    {
        $this->response->removeHeader('Cache-Control');
        $this->response->removeHeader('Pragma');
        $this->response->removeHeader('Expires');
    }
}