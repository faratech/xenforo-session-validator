<?php

namespace WindowsForum\SessionValidator\Service;

class MetaCapi
{
    private static ?string $token    = null;
    private static ?string $pixelId  = null;
    private static bool    $queued   = false;
    private static array   $events   = [];

    public static function queuePageView(\XF\Http\Request $request, \XF\Entity\User $visitor, string $url): void
    {
        $token   = self::token();
        $pixelId = self::pixelId();
        if (!$token || !$pixelId) {
            return;
        }

        // Skip prefetch / prerender — no real user present
        $purpose = strtolower(
            $request->getServer('HTTP_SEC_PURPOSE', '') ?:
            $request->getServer('HTTP_X_PURPOSE', '')
        );
        if ($purpose && strpos($purpose, 'prefetch') !== false) {
            return;
        }

        self::$events[] = [
            'event_name'       => 'PageView',
            'event_time'       => \XF::$time,
            'event_source_url' => $url,
            'action_source'    => 'website',
            'user_data'        => self::userData($request, $visitor),
        ];

        if (!self::$queued) {
            self::$queued = true;
            register_shutdown_function([self::class, 'flush']);
        }
    }

    public static function queueCompleteRegistration(\XF\Http\Request $request, \XF\Entity\User $user, string $url): void
    {
        $token   = self::token();
        $pixelId = self::pixelId();
        if (!$token || !$pixelId) {
            return;
        }

        $userData = self::userData($request, $user);
        // Always have hashed email and user ID for registrations
        if ($user->email) {
            $userData['em'] = hash('sha256', strtolower(trim($user->email)));
        }
        $userData['external_id'] = hash('sha256', (string) $user->user_id);

        self::$events[] = [
            'event_name'       => 'CompleteRegistration',
            'event_time'       => \XF::$time,
            'event_source_url' => $url,
            'action_source'    => 'website',
            'user_data'        => $userData,
        ];

        if (!self::$queued) {
            self::$queued = true;
            register_shutdown_function([self::class, 'flush']);
        }
    }

    public static function flush(): void
    {
        if (empty(self::$events)) {
            return;
        }

        // Flush FastCGI response to client before the network call
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        $payload = json_encode(['data' => self::$events], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $url     = 'https://graph.facebook.com/v19.0/' . self::pixelId()
                 . '/events?access_token=' . rawurlencode(self::token());

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        curl_exec($ch);
        curl_close($ch);

        self::$events = [];
    }

    private static function userData(\XF\Http\Request $request, \XF\Entity\User $visitor): array
    {
        $d = [
            'client_ip_address' => $request->getIp(),
            'client_user_agent' => substr($request->getServer('HTTP_USER_AGENT', ''), 0, 512),
        ];

        $fbp = $request->getCookie('_fbp');
        $fbc = $request->getCookie('_fbc');
        if ($fbp) {
            $d['fbp'] = $fbp;
        }
        if ($fbc) {
            $d['fbc'] = $fbc;
        }

        if ($visitor->user_id) {
            if ($visitor->email) {
                $d['em'] = hash('sha256', strtolower(trim($visitor->email)));
            }
            $d['external_id'] = hash('sha256', (string) $visitor->user_id);
        }

        return $d;
    }

    private static function token(): string
    {
        if (self::$token === null) {
            self::$token = self::env('META_CAPI_TOKEN') ?? '';
        }
        return self::$token;
    }

    private static function pixelId(): string
    {
        if (self::$pixelId === null) {
            self::$pixelId = self::env('META_PIXEL_ID') ?? '868064532441503';
        }
        return self::$pixelId;
    }

    private static function env(string $key): ?string
    {
        $v = getenv($key);
        if ($v !== false && $v !== '') {
            return $v;
        }

        static $parsed = null;
        if ($parsed === null) {
            $parsed = self::parseDotEnv('/web/.env');
        }

        return (isset($parsed[$key]) && $parsed[$key] !== '') ? (string) $parsed[$key] : null;
    }

    /**
     * Robust .env reader. A .env file is NOT an INI file, so parse_ini_file() is
     * the wrong tool: PHP's INI parser treats '#' lines as keys (only ';' is a
     * comment) and throws E_WARNING on the reserved chars {}()|&~!"^ appearing in
     * unquoted values. A single such char anywhere in /web/.env used to emit one
     * warning per request (~40k/day) until masked by getenv(). This line parser
     * never warns regardless of value content.
     */
    private static function parseDotEnv(string $file): array
    {
        $out = [];
        if (!is_readable($file)) {
            return $out;
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return $out;
        }
        foreach ($lines as $line) {
            $line = ltrim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }
            if (strncmp($line, 'export ', 7) === 0) {
                $line = ltrim(substr($line, 7));
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $k = trim(substr($line, 0, $eq));
            if ($k === '') {
                continue;
            }
            $v   = trim(substr($line, $eq + 1));
            $len = strlen($v);
            if ($len >= 2
                && (($v[0] === '"' && $v[$len - 1] === '"') || ($v[0] === "'" && $v[$len - 1] === "'"))) {
                $v = substr($v, 1, -1);
            }
            $out[$k] = $v;
        }
        return $out;
    }
}
