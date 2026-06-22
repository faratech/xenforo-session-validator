<?php

namespace WindowsForum\SessionValidator\XF\SubContainer;

class Proxy extends XFCP_Proxy
{
	    public function initialize()
	    {
	        parent::initialize();

	        $this->container['controller'] = function ($c)
	        {
	            $class = $this->app->extendClass(\XF\Proxy\Controller::class);

	            return new $class(
	                $this->app,
	                $c['linker'],
	                $this->app->request()
	            );
	        };
    }
}
