<?php

namespace WindowsForum\SessionValidator\SV\BrowserDetection;

use function in_array, is_string;

/**
 * CF-Device-Type short-circuit for browser detection (~1.7% of render CPU).
 *
 * SV/BrowserDetection answers isMobile()/isTablet() by running the bundled
 * Mobile_Detect 4.8.10 UA regex battery on every request. Cloudflare's
 * "Add device type header" Managed Transform already classifies the visitor
 * at the edge and sends CF-Device-Type: mobile|tablet|desktop, so the regex
 * work is redundant when that header is present and trusted.
 *
 * Gate: BOTH conditions must hold, otherwise this is a pure pass-through to
 * the parent (identical behaviour when the flag is unset — safe to deploy
 * before the Managed Transform is enabled):
 * - $config['wfCfDeviceType'] is truthy (src/config.php kill switch,
 *   read per-request, no restart needed to flip), AND
 * - $_SERVER['HTTP_CF_DEVICE_TYPE'] is exactly 'mobile', 'tablet' or
 *   'desktop' (any other/missing value delegates to the UA regexes).
 *
 * Truth table mirrors the parent EXACTLY (verified empirically on the
 * bundled Mobile_Detect 4.8.10, 2026-07-17 — tablet implies mobile):
 *
 *   UA class        parent isMobile  parent isTablet   CF-Device-Type
 *   phone           true             false             mobile
 *   tablet          true             true              tablet
 *   desktop         false            false             desktop
 *
 * so:  isMobile = (type !== 'desktop'),  isTablet = (type === 'tablet').
 *
 * Decisions are cached per instance in the parent's own $cache slots
 * ('isMobile'/'isTablet'), so getHtmlCss() and repeat calls cost an array
 * lookup either way, and the header is only inspected once per instance.
 */
class MobileDetectCache extends XFCP_MobileDetectCache
{
    /**
     * @var string|null|false false = not yet resolved, null = unavailable
     */
    protected $wfCfDeviceType = false;

    public function isMobile(): bool
    {
        $result = $this->cache['isMobile'] ?? null;
        if ($result === null)
        {
            $deviceType = $this->wfGetCfDeviceType();
            if ($deviceType !== null)
            {
                $this->cache['isMobile'] = $result = ($deviceType !== 'desktop');
            }
            else
            {
                $result = parent::isMobile();
            }
        }

        return $result;
    }

    public function isTablet(): bool
    {
        $result = $this->cache['isTablet'] ?? null;
        if ($result === null)
        {
            $deviceType = $this->wfGetCfDeviceType();
            if ($deviceType !== null)
            {
                $this->cache['isTablet'] = $result = ($deviceType === 'tablet');
            }
            else
            {
                $result = parent::isTablet();
            }
        }

        return $result;
    }

    protected function wfGetCfDeviceType(): ?string
    {
        if ($this->wfCfDeviceType === false)
        {
            $this->wfCfDeviceType = null;

            if (\XF::config('wfCfDeviceType'))
            {
                $deviceType = $_SERVER['HTTP_CF_DEVICE_TYPE'] ?? null;
                if (is_string($deviceType)
                    && in_array($deviceType, ['mobile', 'tablet', 'desktop'], true)
                )
                {
                    $this->wfCfDeviceType = $deviceType;
                }
            }
        }

        return $this->wfCfDeviceType;
    }
}
