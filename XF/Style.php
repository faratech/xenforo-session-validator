<?php

namespace WindowsForum\SessionValidator\XF;

use XF\Entity\User;

/**
 * Gated style preview: lets any visitor — including guests — use one specific
 * non-selectable style, designated by $config['wfPreviewStyleId'] (per-node
 * src/config.php; absent/0 = feature off, instant kill switch, no restart).
 *
 * Core behaviour (XF\Style::isUsable) limits non-selectable styles to admins,
 * so a guest carrying an xf_style_id cookie for the preview style would
 * silently fall back to the default (XF\Pub\App ~L478). This override admits
 * exactly the one configured style id; everything else keeps core rules.
 *
 * The style stays invisible in pickers: account preferences and misc/style
 * list only user_selectable styles — usability and selectability are
 * independent checks. Anyone who learns the cookie value can see the preview;
 * that is the intended bar (shareable, not secret).
 *
 * Cache safety is inherited, verified 2026-06-12: pagecache.php keys on the
 * xf_style_id cookie, X-LiteSpeed-Vary includes it, and Cloudflare already
 * bypasses edge cache for requests carrying it (cf-cache-status: DYNAMIC) —
 * per-style guest rendering is an existing, exercised path (style 17).
 */
class Style extends XFCP_Style
{
    public function isUsable(User $user)
    {
        if (parent::isUsable($user))
        {
            return true;
        }

        $previewStyleId = (int) (\XF::config('wfPreviewStyleId') ?? 0);

        return $previewStyleId > 0 && $this->getId() === $previewStyleId;
    }
}
