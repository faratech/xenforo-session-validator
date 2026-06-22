<?php

namespace WindowsForum\SessionValidator\Service;

use WindowsForum\SharedRedis;
use XF\App;
use XF\Http\Response;

class CapsuleSnapshot
{
    public const VERSION = 'account-nav-v1';
    public const TTL = 60;
    public const STALE_TTL = 3600;
    public const MEMBER_COOKIE = 'wf_capsule_member';
    public const BYPASS_COOKIE = 'wf_capsule_bypass';
    public const KEY_PREFIX = 'wf_capsule:snap:';

    public static function publishFromApp(App $app, Response $response, bool $force = false): void
    {
        try
        {
            static::publish($app, $response, $force);
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'Capsule snapshot: ');
        }
    }

    public static function clearCookies(Response $response): void
    {
        $response->setCookie(static::MEMBER_COOKIE, false, 0, null, false, 'Lax');
        $response->setCookie(static::BYPASS_COOKIE, false, 0, null, true, 'Lax');
    }

    protected static function publish(App $app, Response $response, bool $force): void
    {
        $visitor = \XF::visitor();
        if (!$visitor || !$visitor->user_id)
        {
            return;
        }

        if (static::visitorRequiresBypass($visitor))
        {
            $response->setCookie(static::MEMBER_COOKIE, false, 0, null, false, 'Lax');
            $response->setCookie(static::BYPASS_COOKIE, '1', 3600, null, true, 'Lax');
            return;
        }

        $response->setCookie(static::MEMBER_COOKIE, '1', 3600, null, false, 'Lax');
        $response->setCookie(static::BYPASS_COOKIE, false, 0, null, true, 'Lax');

        if (!$force && $response->httpCode() !== 200)
        {
            return;
        }

        $redis = SharedRedis::raw();
        if (!$redis)
        {
            return;
        }

        $key = static::snapshotKey($app, $response);
        if ($key === '')
        {
            return;
        }

        if (!$force)
        {
            $throttleKey = $key . ':touch';
            if (!$redis->set($throttleKey, '1', ['NX', 'EX' => 3]))
            {
                return;
            }
        }

        $payload = [
            'status' => 'ok',
            'version' => static::VERSION,
            'generated_at' => \XF::$time,
            'expires_at' => \XF::$time + static::TTL,
            'stale_until' => \XF::$time + static::STALE_TTL,
            'visitor' => [
                'user_id' => (int) $visitor->user_id,
                'username' => (string) $visitor->username,
            ],
            'counts' => [
                'conversations_unread' => static::number((int) $visitor->conversations_unread),
                'alerts_unviewed' => static::number((int) $visitor->alerts_unviewed),
                'total_unread' => static::number((int) $visitor->conversations_unread + (int) $visitor->alerts_unviewed),
            ],
            'csrf' => (string) $app['csrf.token'],
            'live' => static::buildLiveConfig(),
            'html' => [
                'accountNav' => static::renderAccountNav($app, $visitor),
            ],
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($encoded !== false)
        {
            $redis->set($key, $encoded, ['EX' => static::STALE_TTL]);
        }
    }

    protected static function visitorRequiresBypass($visitor): bool
    {
        if (!empty($visitor->is_admin) || !empty($visitor->is_moderator) || !empty($visitor->is_staff))
        {
            return true;
        }

        return isset($visitor->user_state) && $visitor->user_state !== 'valid';
    }

    protected static function snapshotKey(App $app, Response $response): string
    {
        $parts = [
            static::VERSION,
            static::cookieValue($app, $response, 'user'),
            static::cookieValue($app, $response, 'session'),
            static::cookieValue($app, $response, 'style_id'),
            static::cookieValue($app, $response, 'style_variation'),
            static::cookieValue($app, $response, 'language_id'),
        ];

        if ($parts[1] === '' && $parts[2] === '')
        {
            return '';
        }

        return static::KEY_PREFIX . hash('sha256', implode("\n", $parts));
    }

    protected static function cookieValue(App $app, Response $response, string $name): string
    {
        $pending = $response->getCookie($name, true);
        if ($pending)
        {
            return (string) ($pending[1] ?? '');
        }

        return (string) $app->request()->getCookie($name, '');
    }

    protected static function number(int $value): string
    {
        return \XF::language()->numberFormat($value);
    }

    protected static function buildLiveConfig(): ?array
    {
        try
        {
            $class = '\\WindowsForum\\LiveThread\\TemplateCallback';
            if (class_exists($class) && method_exists($class, 'buildCapsuleHydrationConfig'))
            {
                $config = $class::buildCapsuleHydrationConfig();
                return is_array($config) ? $config : null;
            }
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'Capsule live config: ');
        }

        return null;
    }

    protected static function renderAccountNav(App $app, $visitor): string
    {
        $router = $app->router('public');
        $templater = $app->templater();
        $avatar = $templater->func('avatar', [$visitor, 'xxs', false, ['href' => '', 'title' => '']], false);
        $username = static::escape((string) $visitor->username);
        $conversations = static::number((int) $visitor->conversations_unread);
        $alerts = static::number((int) $visitor->alerts_unviewed);
        $convClass = (int) $visitor->conversations_unread ? ' badgeContainer--highlighted' : '';
        $alertClass = (int) $visitor->alerts_unviewed ? ' badgeContainer--highlighted' : '';
        $canStartConversation = method_exists($visitor, 'canStartConversation') && $visitor->canStartConversation();

        $link = function (string $route) use ($router): string
        {
            return static::escape($router->buildLink($route));
        };

        $sendDirectMessage = $canStartConversation
            ? '<li><a href="' . $link('direct-messages/add') . '">' . static::phrase('send_direct_message') . '</a></li>'
            : '';

        return
            '<div class="p-navgroup p-account p-navgroup--member" data-wf-capsule-account-nav="1">' .
                '<a href="' . $link('account') . '" class="p-navgroup-link p-navgroup-link--iconic p-navgroup-link--user" data-xf-click="menu" data-xf-key="' . static::phrase('shortcut.visitor_menu') . '" data-menu-pos-ref="&lt; .p-navgroup" title="' . $username . '" aria-expanded="false" aria-haspopup="true">' .
                    $avatar .
                    '<span class="p-navgroup-linkText">' . $username . '</span>' .
                '</a>' .
                '<div class="menu menu--structural menu--wide menu--account" data-menu="menu" aria-hidden="true" data-href="' . $link('account/visitor-menu') . '" data-load-target=".js-visitorMenuBody">' .
                    '<div class="menu-content js-visitorMenuBody"><div class="menu-row">' . static::phrase('loading...') . '</div></div>' .
                '</div>' .
                '<a href="' . $link('direct-messages') . '" class="p-navgroup-link p-navgroup-link--iconic p-navgroup-link--conversations js-badge--conversations badgeContainer' . $convClass . '" data-badge="' . $conversations . '" data-xf-click="menu" data-xf-key="' . static::phrase('shortcut.conversations_menu') . '" data-menu-pos-ref="&lt; .p-navgroup" title="' . static::phrase('direct_messages') . '" aria-label="' . static::phrase('direct_messages') . '" aria-expanded="false" aria-haspopup="true">' .
                    '<i aria-hidden="true"></i><span class="p-navgroup-linkText">' . static::phrase('nav_inbox') . '</span>' .
                '</a>' .
                '<div class="menu menu--structural menu--medium" data-menu="menu" aria-hidden="true" data-href="' . $link('direct-messages/popup') . '" data-nocache="true" data-load-target=".js-convMenuBody">' .
                    '<div class="menu-content">' .
                        '<h3 class="menu-header">' . static::phrase('direct_messages') . '</h3>' .
                        '<div class="js-convMenuBody"><div class="menu-row">' . static::phrase('loading...') . '</div></div>' .
                        '<div class="menu-footer menu-footer--split"><div class="menu-footer-main"><ul class="listInline listInline--bullet">' .
                            '<li><a href="' . $link('direct-messages') . '">' . static::phrase('show_all') . '</a></li>' .
                            $sendDirectMessage .
                        '</ul></div></div>' .
                    '</div>' .
                '</div>' .
                '<a href="' . $link('account/alerts') . '" class="p-navgroup-link p-navgroup-link--iconic p-navgroup-link--alerts js-badge--alerts badgeContainer' . $alertClass . '" data-badge="' . $alerts . '" data-xf-click="menu" data-xf-key="' . static::phrase('shortcut.alerts_menu') . '" data-menu-pos-ref="&lt; .p-navgroup" title="' . static::phrase('alerts') . '" aria-label="' . static::phrase('alerts') . '" aria-expanded="false" aria-haspopup="true">' .
                    '<i aria-hidden="true"></i><span class="p-navgroup-linkText">' . static::phrase('nav_alerts') . '</span>' .
                '</a>' .
                '<div class="menu menu--structural menu--medium" data-menu="menu" aria-hidden="true" data-href="' . $link('account/alerts-popup') . '" data-nocache="true" data-load-target=".js-alertsMenuBody">' .
                    '<div class="menu-content">' .
                        '<h3 class="menu-header">' . static::phrase('alerts') . '</h3>' .
                        '<div class="js-alertsMenuBody"><div class="menu-row">' . static::phrase('loading...') . '</div></div>' .
                        '<div class="menu-footer menu-footer--split"><div class="menu-footer-main"><ul class="listInline listInline--bullet">' .
                            '<li><a href="' . $link('account/alerts') . '">' . static::phrase('show_all') . '</a></li>' .
                            '<li><a href="' . $link('account/alerts/mark-read') . '" class="js-alertsMarkRead">' . static::phrase('mark_read') . '</a></li>' .
                            '<li><a href="' . $link('account/preferences') . '">' . static::phrase('preferences') . '</a></li>' .
                        '</ul></div></div>' .
                    '</div>' .
                '</div>' .
            '</div>';
    }

    protected static function phrase(string $name): string
    {
        return static::escape((string) \XF::phrase($name));
    }

    protected static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
