<?php

namespace WindowsForum\SessionValidator\XF\Entity;

use WindowsForum\SessionValidator\Service\LiveNewsCacheInvalidator;

class Post extends XFCP_Post
{
    protected function _postSave()
    {
        $releasedToVisible = $this->isUpdate()
            && $this->isChanged('message_state')
            && $this->message_state === 'visible'
            && in_array($this->getExistingValue('message_state'), ['moderated', 'unapproved', 'deleted'], true);

        parent::_postSave();

        if ($this->thread_id && $this->Thread) {
            LiveNewsCacheInvalidator::purgeForThread($this->Thread);

            if ($releasedToVisible) {
                LiveNewsCacheInvalidator::purgeContentForThread($this->Thread, $this->post_id);
            }
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
