<?php

namespace WindowsForum\SessionValidator\Cache;

use Symfony\Component\Cache\Marshaller\DefaultMarshaller;
use Symfony\Component\Cache\Marshaller\MarshallerInterface;

/**
 * Transparent at-rest zstd compression for the guest page cache (Redis DB1
 * xf:page_* entries). Wraps an inner Symfony marshaller (DefaultMarshaller in
 * serialize() mode) and zstd-compresses the marshalled bytes with a 2-byte
 * magic prefix so reads can tell compressed from legacy-raw values.
 *
 * The pre-bootstrap reader (public_html/pagecache.php) reads xf:page_* with a
 * raw Redis GET and must apply the SAME magic-prefix + zstd_uncompress before
 * unserialize() — keep the two in sync (MAGIC, codec).
 *
 * Fail-open: if ext-zstd is absent, values are stored verbatim (no prefix) =
 * legacy behaviour. The magic 'Z1' can never collide with a PHP serialize()
 * payload (those start with a/O/s/i/d/b/N/{), so detection is unambiguous.
 */
class ZstdMarshaller implements MarshallerInterface
{
    public const MAGIC = 'Z1';
    // L9 compresses the typical 129KB page body in ~1.3ms at 5.37x; L19 took ~38.5ms
    // for only 5.62x — the save was 66% of the median cache-miss render time.
    public const LEVEL = 9;
    // Don't bother compressing tiny values (overhead > benefit).
    public const MIN_SIZE = 512;

    protected MarshallerInterface $inner;

    public function __construct(?MarshallerInterface $inner = null)
    {
        // Force serialize() (not igbinary) so the raw value stays unserialize()-able
        // by pagecache.php's pre-bootstrap reader.
        $this->inner = $inner ?? new DefaultMarshaller(false);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<int, string>|null $failed
     * @return array<string, string>
     */
    public function marshall(array $values, ?array &$failed): array
    {
        // Bake the stable shared-guest CSRF token into page-cache bodies at STORE
        // time so the stored body serves verbatim to any guest (and, later, to
        // httpjet) with no per-serve rewriting needed. This is the cleanest store
        // hook available: XF\PageCache isn't XFCP-extendable and there's no event
        // between body-render and saveToCache, but every page value flows through
        // this page-context marshaller. Behaviourally identical to pagecache.php's
        // existing per-serve rewrite (same shared token), so pagecache.php keeps
        // working unchanged — on a baked entry its rewrite just refreshes the
        // token timestamp. Fail-open per value.
        foreach ($values as $id => $value)
        {
            // A page-cache entry (has a rendered body + store date). CSRF baking
            // is gated internally on a non-empty token; now: baking applies to all.
            if (is_array($value)
                && isset($value['body'], $value['date'])
                && is_string($value['body']))
            {
                $values[$id] = static::bakePageBody($value);
            }
        }

        $marshalled = $this->inner->marshall($values, $failed);

        if (!function_exists('zstd_compress'))
        {
            return $marshalled;
        }

        foreach ($marshalled as $id => $value)
        {
            if (!is_string($value) || strlen($value) < static::MIN_SIZE)
            {
                continue;
            }
            $compressed = @zstd_compress($value, static::LEVEL);
            if (is_string($compressed) && strlen($compressed) + 2 < strlen($value))
            {
                $marshalled[$id] = static::MAGIC . $compressed;
            }
        }

        return $marshalled;
    }

    /**
     * Make a page-cache body self-sufficient (serve-verbatim-safe) at STORE time,
     * so the pre-bootstrap reader (and, later, httpjet) needs no per-serve rewrite:
     *  (1) CSRF — replace the renderer's per-session token with the stable
     *      shared-guest token (valid for any guest); mirrors pagecache.php/vc.php.
     *  (2) now: — replace XF's static server timestamp (`now: <ts>,`) with a
     *      client-evaluated expression so relative times reflect the visitor's
     *      current time regardless of cache age.
     * Both steps fail-open independently (body unchanged on any problem).
     *
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    protected static function bakePageBody(array $value): array
    {
        $body = (string) $value['body'];

        try
        {
            $salt = \XF::config('globalSalt');
            if (is_string($salt) && $salt !== '')
            {
                $rendered = (string) ($value['csrfToken'] ?? '');
                if ($rendered !== '')
                {
                    $cookie = substr(hash_hmac('md5', 'wf-shared-guest-csrf', $salt), 0, 16);
                    $now = \XF::$time ?: time();
                    $shared = $now . ',' . hash_hmac('md5', $cookie . $now, $salt);
                    if ($rendered !== $shared)
                    {
                        $body = str_replace($rendered, $shared, $body);
                        $body = str_replace(urlencode($rendered), urlencode($shared), $body);
                        $body = str_replace(rawurlencode($rendered), rawurlencode($shared), $body);
                        $value['csrfToken'] = $shared;
                    }
                }
            }
        }
        catch (\Throwable $e)
        {
        }

        try
        {
            $date = isset($value['date']) ? (int) $value['date'] : 0;
            if ($date)
            {
                // XF's `now: {$xf.time},` JS-config literal (PageCache.php:177).
                $body = str_replace('now: ' . $date . ',', 'now: Math.floor(Date.now()/1e3),', $body);
            }
        }
        catch (\Throwable $e)
        {
        }

        $value['body'] = $body;

        return $value;
    }

    /**
     * @return mixed
     */
    public function unmarshall(string $value)
    {
        if (strncmp($value, static::MAGIC, 2) === 0)
        {
            if (!function_exists('zstd_uncompress'))
            {
                throw new \Symfony\Component\Cache\Exception\CacheException(
                    'ZstdMarshaller: ext-zstd unavailable to inflate a compressed cache value'
                );
            }
            $plain = @zstd_uncompress(substr($value, 2));
            if (!is_string($plain))
            {
                throw new \Symfony\Component\Cache\Exception\CacheException(
                    'ZstdMarshaller: zstd inflate failed'
                );
            }
            $value = $plain;
        }

        return $this->inner->unmarshall($value);
    }
}
