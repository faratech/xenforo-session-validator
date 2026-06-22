<?php

namespace WindowsForum\SessionValidator\Service;

use WindowsForum\SharedRedis;
use XF\App;
use XF\Http\Response;

class GenericShellFragment
{
    public const VERSION = 'generic-shell-v1';
    public const KEY_PREFIX = 'wf_gs:v1:';
    public const TAG_INDEX_PREFIX = 'wf_gsidx:tag:';
    public const PAGE_CACHE_REDIS_DB = 1;
    public const MAX_BODY_BYTES = 2097152;
    public const DEFAULT_TTL = 600;
    public const MAX_TTL = 86400;

    public static function publishFromApp(App $app, Response $response): void
    {
        try
        {
            static::publish($app, $response);
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'Generic shell fragment: ');
        }
    }

    public static function purgeByTag(string $tag): void
    {
        $tag = static::sanitizeTag($tag);
        if ($tag === '')
        {
            return;
        }

        $redis = SharedRedis::raw();
        if (!$redis)
        {
            return;
        }

        try
        {
            $redis->select(static::PAGE_CACHE_REDIS_DB);
            $idx = static::TAG_INDEX_PREFIX . $tag;
            $keys = $redis->sMembers($idx);
            if ($keys)
            {
                $redis->del(array_values(array_unique($keys)));
            }
            $redis->del($idx);
        }
        catch (\Throwable $e)
        {
        }
        finally
        {
            try { $redis->select(0); } catch (\Throwable $e) {}
        }
    }

    public static function purgeByTags(array $tags): void
    {
        foreach ($tags as $tag)
        {
            static::purgeByTag((string) $tag);
        }
    }

    protected static function publish(App $app, Response $response): void
    {
        if ($response->httpCode() !== 200)
        {
            return;
        }

        $visitor = \XF::visitor();
        if ($visitor && $visitor->user_id)
        {
            return;
        }

        if ($response->contentType() !== 'text/html')
        {
            return;
        }

        $capsuleHeader = static::headerValue($response, 'X-WF-Capsule');
        if (stripos($capsuleHeader, 'public-shell') === false
            || stripos($capsuleHeader, 'hydrate=account-nav-v1') === false
        )
        {
            return;
        }

        if (static::responseSetsNonCsrfCookie($response))
        {
            return;
        }

        $request = $app->request();
        $routePath = trim((string) $request->getRoutePath(), '/');
        if (!static::routeAllowed($routePath, (string) $request->getRequestUri()))
        {
            return;
        }

        $body = $response->body();
        if (!is_string($body) || $body === '' || strlen($body) > static::MAX_BODY_BYTES)
        {
            return;
        }
        if (strpos(substr($body, 0, 1024), 'data-template="error"') !== false)
        {
            return;
        }

        $range = static::findElementRangeByClass($body, 'p-body-pageContent');
        if (!$range)
        {
            $range = static::findElementRangeByClass($body, 'p-body-content');
        }
        if (!$range)
        {
            return;
        }

        [$start, $end] = $range;
        $main = substr($body, $start, $end - $start);
        if ($main === false || $main === '')
        {
            return;
        }

        $key = static::cacheKey($app);
        if ($key === '')
        {
            return;
        }

        $ttl = static::responseTtl($response);
        $now = \XF::$time ?: time();
        $staleExtra = max(600, min(static::MAX_TTL, $ttl * 4));
        $staleUntil = $now + $ttl + $staleExtra;
        $tags = static::responseTags($response, $routePath);

        $payload = [
            'status' => 'ok',
            'version' => static::VERSION,
            'generated_at' => (string) $now,
            'expires_at' => (string) ($now + $ttl),
            'stale_until' => (string) $staleUntil,
            'content_type' => $response->contentType(),
            'charset' => $response->charset(),
            'route' => $routePath,
            'tags' => implode(', ', $tags),
            'csrf_token' => static::extractCsrfToken($body),
            'prefix' => substr($body, 0, $start),
            'main' => $main,
            'suffix' => substr($body, $end),
        ];

        $redis = SharedRedis::raw();
        if (!$redis)
        {
            return;
        }

        try
        {
            $redis->select(static::PAGE_CACHE_REDIS_DB);
            $redis->hMSet($key, $payload);
            $redisTtl = max($ttl, $staleUntil - $now);
            $redis->expire($key, $redisTtl);

            foreach ($tags as $tag)
            {
                $idx = static::TAG_INDEX_PREFIX . $tag;
                $redis->sAdd($idx, $key);
                $redis->expire($idx, $redisTtl);
            }
        }
        catch (\Throwable $e)
        {
        }
        finally
        {
            try { $redis->select(0); } catch (\Throwable $e) {}
        }
    }

    protected static function cacheKey(App $app): string
    {
        $request = $app->request();
        $options = \XF::options();

        $styleId = (int) $request->getCookie('style_id', 0);
        if (!$styleId)
        {
            $styleId = (int) $options->defaultStyleId;
        }

        $styleVariation = (string) $request->getCookie('style_variation', '');
        if ($styleVariation && !preg_match('/./su', $styleVariation))
        {
            $styleVariation = '';
        }

        $languageId = (int) $request->getCookie('language_id', 0);
        if (!$languageId)
        {
            $languageId = (int) $options->defaultLanguageId;
        }

        $consentedCookieGroups = @json_decode($request->getCookie('consent', '[]'), true);
        if (!is_array($consentedCookieGroups))
        {
            $consentedCookieGroups = [];
        }
        sort($consentedCookieGroups);
        $cookieConsentId = md5(implode(',', $consentedCookieGroups));

        require_once \XF::getRootDirectory() . '/src/page_cache_query_normalizer.php';
        $fullUri = $request->getFullRequestUri();
        if (function_exists('wf_page_cache_normalize_uri'))
        {
            $fullUri = wf_page_cache_normalize_uri($fullUri);
        }

        return static::KEY_PREFIX . hash('sha256', implode("\n", [
            $fullUri,
            $styleId,
            $styleVariation,
            $languageId,
            $cookieConsentId,
        ]));
    }

    protected static function responseTtl(Response $response): int
    {
        $headers = [
            static::headerValue($response, 'X-WF-Capsule'),
            static::headerValue($response, 'Cache-Control'),
            static::headerValue($response, 'Cloudflare-CDN-Cache-Control'),
        ];

        foreach ($headers as $header)
        {
            if (preg_match('/(?:^|[,;\s])s-maxage=(\d+)/i', $header, $m)
                || preg_match('/(?:^|[,;\s])max-age=(\d+)/i', $header, $m)
            )
            {
                return max(15, min(static::MAX_TTL, (int) $m[1]));
            }
        }

        return static::DEFAULT_TTL;
    }

    protected static function responseTags(Response $response, string $routePath): array
    {
        $raw = static::headerValue($response, 'X-WF-Capsule-Tags');
        if ($raw === '')
        {
            $raw = static::headerValue($response, 'X-LiteSpeed-Tag');
        }

        $tags = [];
        foreach (explode(',', $raw) as $tag)
        {
            $tag = static::sanitizeTag($tag);
            if ($tag !== '')
            {
                $tags[$tag] = true;
            }
        }

        if (preg_match('#^threads/[^/]+\.(\d+)(?:/|$)#', $routePath, $m))
        {
            $tags['T' . (int) $m[1]] = true;
        }
        elseif (preg_match('#^forums/[^/]+\.(\d+)(?:/|$)#', $routePath, $m))
        {
            $tags['F' . (int) $m[1]] = true;
        }
        elseif ($routePath === '')
        {
            $tags['H'] = true;
        }
        elseif (strpos($routePath, 'whats-new') === 0)
        {
            $tags['WN'] = true;
        }

        if (!$tags)
        {
            $tags['public'] = true;
        }

        return array_keys($tags);
    }

    protected static function routeAllowed(string $routePath, string $requestUri): bool
    {
        $routePathLower = strtolower(rawurldecode(trim($routePath, '/')));
        if (strpos($routePathLower, '.php') !== false
            || strpos($routePathLower, '.rss') !== false
            || preg_match('#(?:^|/)(?:add|edit|delete|approve|unapprove|report|watch|unwatch|mark-read|unread|latest|reply|preview|react|vote|inline-mod|moderation|spam-cleaner)(?:/|$)#', $routePathLower)
        )
        {
            return false;
        }

        $slashPos = strpos($routePathLower, '/');
        $prefix = $slashPos === false ? $routePathLower : substr($routePathLower, 0, $slashPos);
        $allowedPrefixes = [
            '' => true,
            'threads' => true,
            'forums' => true,
            'media' => true,
            'resources' => true,
            'members' => true,
            'tags' => true,
            'whats-new' => true,
            'help' => true,
            'pages' => true,
            'featured' => true,
            'recent-activity' => true,
        ];
        if (!isset($allowedPrefixes[$prefix]))
        {
            return false;
        }

        $query = parse_url($requestUri, PHP_URL_QUERY);
        if (!is_string($query) || $query === '')
        {
            return true;
        }

        $blocked = [
            '_xftoken' => true,
            '_xfwithdata' => true,
            '_xfresponsetype' => true,
            '_xfrequesturi' => true,
            'login' => true,
            'logout' => true,
            'delete' => true,
            'edit' => true,
            'approve' => true,
            'unapprove' => true,
            'watch' => true,
            'unwatch' => true,
            'mark_read' => true,
            'tooltip' => true,
            'menu' => true,
            'preview' => true,
            'react' => true,
            'inline_mod' => true,
        ];
        foreach (explode('&', $query) as $part)
        {
            $eqPos = strpos($part, '=');
            $name = strtolower(urldecode($eqPos === false ? $part : substr($part, 0, $eqPos)));
            $name = preg_replace('/\[\]$/', '', $name);
            $name = preg_replace('/\[([^\]]*)\]/', '_$1', $name);
            $name = trim($name, '_');
            if (isset($blocked[$name]) || strpos($name, '_xf') === 0)
            {
                return false;
            }
        }

        return true;
    }

    protected static function responseSetsNonCsrfCookie(Response $response): bool
    {
        try
        {
            return (bool) $response->getCookiesExcept(['csrf'], true);
        }
        catch (\Throwable $e)
        {
            return true;
        }
    }

    protected static function headerValue(Response $response, string $name): string
    {
        $value = $response->header($name);
        if (is_array($value))
        {
            return implode(', ', array_map('strval', $value));
        }

        return is_string($value) ? $value : '';
    }

    protected static function extractCsrfToken(string $body): string
    {
        if (preg_match('/\bcsrf:\s*"([^"]+)"/', $body, $m))
        {
            return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }
        if (preg_match('/name="_xfToken"\s+value="([^"]+)"/', $body, $m))
        {
            return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }

        return '';
    }

    protected static function findElementRangeByClass(string $html, string $className): ?array
    {
        $pattern = '#<div\b[^>]*\bclass\s*=\s*([\'"])[^\'"]*\b' . preg_quote($className, '#') . '\b[^\'"]*\1[^>]*>#i';
        if (!preg_match($pattern, $html, $match, PREG_OFFSET_CAPTURE))
        {
            return null;
        }

        $start = $match[0][1];
        $offset = $start + strlen($match[0][0]);
        $depth = 1;
        $tagPattern = '#</?div\b[^>]*>#i';

        while (preg_match($tagPattern, $html, $tagMatch, PREG_OFFSET_CAPTURE, $offset))
        {
            $tag = $tagMatch[0][0];
            $tagPos = $tagMatch[0][1];
            if (isset($tag[1]) && $tag[1] === '/')
            {
                $depth--;
                if ($depth === 0)
                {
                    return [$start, $tagPos + strlen($tag)];
                }
            }
            else if (substr($tag, -2) !== '/>')
            {
                $depth++;
            }

            $offset = $tagPos + strlen($tag);
        }

        return null;
    }

    protected static function sanitizeTag(string $tag): string
    {
        $tag = trim($tag);
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $tag) ? $tag : '';
    }
}
