<?php

namespace WindowsForum\SessionValidator\XF\Entity;

use WindowsForum\SessionValidator\Service\LiveNewsCacheInvalidator;

class Thread extends XFCP_Thread
{
    protected function _postSave()
    {
        $nodeIds = [(int) $this->node_id];
        $shouldPurge = $this->shouldPurgeLiveNewsListings();

        if ($this->isUpdate() && $this->isChanged('node_id')) {
            $nodeIds[] = (int) $this->getExistingValue('node_id');
        }

        parent::_postSave();

        if ($shouldPurge) {
            LiveNewsCacheInvalidator::purgeForThread($this, $nodeIds);
        }
    }

    protected function _postDelete()
    {
        $nodeIds = [(int) $this->node_id];

        parent::_postDelete();

        LiveNewsCacheInvalidator::purgeForThread($this, $nodeIds);
    }

    protected function shouldPurgeLiveNewsListings()
    {
        if ($this->isInsert()) {
            return true;
        }

        foreach (['last_post_date', 'node_id', 'discussion_state', 'sticky', 'title', 'prefix_id'] as $field) {
            if ($this->isChanged($field)) {
                return true;
            }
        }

        return false;
    }
}

if (false)
{
    class XFCP_Thread extends \XF\Entity\Thread {}
}
