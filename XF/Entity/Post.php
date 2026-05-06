<?php

namespace WindowsForum\SessionValidator\XF\Entity;

use WindowsForum\SessionValidator\Service\LiveNewsCacheInvalidator;

class Post extends XFCP_Post
{
    protected function _postSave()
    {
        parent::_postSave();

        if ($this->thread_id && $this->Thread) {
            LiveNewsCacheInvalidator::purgeForThread($this->Thread);
        }
    }

    protected function _postDelete()
    {
        $thread = $this->Thread;

        parent::_postDelete();

        if ($thread) {
            LiveNewsCacheInvalidator::purgeForThread($thread);
        }
    }
}

if (false)
{
    class XFCP_Post extends \XF\Entity\Post {}
}
