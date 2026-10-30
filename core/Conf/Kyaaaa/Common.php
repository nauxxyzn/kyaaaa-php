<?php

declare(strict_types=1);

use Core\Conf\Kyaaaa\Bootstrap;
use Core\Conf\Kyaaaa\CSRF;
use Core\Conf\Kyaaaa\DebugBar;
use Core\Conf\Kyaaaa\Env;
use Core\Conf\Kyaaaa\HttpException;
use Core\Conf\Kyaaaa\Renderer;
use Core\Conf\Kyaaaa\Request;
use Core\Conf\Kyaaaa\Security;
use Core\Conf\Kyaaaa\Session;

/*
 * Global helper functions. This file only defines functions, it has no side
 * effects (the bootstrap lives in Core\Conf\Kyaaaa\Bootstrap).
 */

if (!function_exists('env')) {
    /** Read a value from the environment / .env file (always a raw string). */
    function env(string $key, ?string $default = null): ?string
    {
        return Env::get($key, $default);
    }
}

if (!function_exists('dd')) {
    /** Dump variables and stop. Only works in development. */
    function dd(mixed ...$vars): never
    {
        if (!Bootstrap::isDebug()) {
            error_log('[kyaaaa] dd() was called outside development mode');
            throw new HttpException(500, 'Debug helper used outside development mode');
        }

        while (ob_get_level() > 0 && @ob_end_clean()) {
            // discard partial output
        }

        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
        }

        echo '<html><head><title>Kyaaaa~ Ge-debug!</title><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>'
            . '<body style="background:#202124;font-size:15px"><div style="padding:10px;background:#b12776;margin-bottom:10px;color:#fff;'
            . 'max-width:1920px;font-family:Courier New;border-radius:8px;font-size:17px;letter-spacing:1.8px">Kyaaaa~ Ge-debug!</div>'
            . '<div style="margin-left:25px">';

        foreach ($vars as $var) {
            ob_start();
            var_dump($var);
            $dump = (string) ob_get_clean();
            echo '<pre style="color:#fff;font-size:15px;white-space:pre-wrap">'
                . htmlspecialchars($dump, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
        }

        echo '</div></body></html>';
        exit(1);
    }
}

if (!function_exists('dbg')) {
    /**
     * Send a value to the "Log" tab of the development toolbar and hand it back,
     * so it can be dropped into an expression: $user = dbg($model->find($id), 'user');
     * Does nothing outside development. Sensitive keys (password, token, ...) are masked.
     */
    function dbg(mixed $value, string $label = ''): mixed
    {
        DebugBar::log($value, $label);

        return $value;
    }
}

if (!function_exists('d')) {
    function d(mixed ...$vars): never
    {
        dd(...$vars);
    }
}

if (!function_exists('esc')) {
    /**
     * Escape output for HTML.
     *
     * $entities = true  : htmlspecialchars (default, use for text and "quoted" attributes)
     * $entities = false : strict mode, every character except letters, digits,
     *                     space , . _ - becomes a numeric entity
     *
     * @return string|array<mixed>
     */
    function esc(mixed $str, bool $entities = true): string|array
    {
        if (is_array($str)) {
            return array_map(static fn(mixed $item): string|array => esc($item, $entities), $str);
        }

        $str = (is_scalar($str) || $str instanceof Stringable) ? (string) $str : '';

        if ($entities) {
            return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        }

        $encoded = preg_replace_callback(
            '/[^A-Za-z0-9 ,._-]/u',
            static fn(array $m): string => '&#' . mb_ord($m[0], 'UTF-8') . ';',
            $str
        );

        return $encoded ?? '';
    }
}

if (!function_exists('view')) {
    /** Render core/Views/<view>.kyaaaa.php inside the layout and print it. */
    function view(string $view, array $data = []): void
    {
        (new Renderer($view, $data))->send();
    }
}

if (!function_exists('viewPath')) {
    /** Absolute path of a view file (validated, cannot leave core/Views). */
    function viewPath(string $view, array $data = []): string
    {
        return Renderer::resolve($view);
    }
}

if (!function_exists('url')) {
    /** Absolute URL of the application, optionally followed by a path. */
    function url(string $segments = ''): string
    {
        return Security::baseUrl() . ltrim($segments, '/');
    }
}

if (!function_exists('getBrowser')) {
    /**
     * Rough browser detection. The user agent is attacker controlled: escape it
     * before printing it.
     *
     * @return string|array<string, string>
     */
    function getBrowser(bool $arr = false): string|array
    {
        $agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);

        $platform = 'unknown';
        if (preg_match('/windows|win32/i', $agent) === 1) {
            $platform = 'windows';
        } elseif (preg_match('/macintosh|mac os x/i', $agent) === 1) {
            $platform = 'mac';
        } elseif (preg_match('/linux|android/i', $agent) === 1) {
            $platform = 'linux';
        }

        $browsers = [
            ['/MSIE|Trident/i', 'Internet Explorer', 'IE', 'MSIE|rv:'],
            ['/OPR|Opera/i', 'Opera', 'Opera', 'OPR|Opera'],
            ['/Firefox/i', 'Mozilla Firefox', 'Firefox', 'Firefox'],
            ['/Chrome/i', 'Google Chrome', 'Chrome', 'Chrome'],
            ['/Safari/i', 'Apple Safari', 'Safari', 'Version|Safari'],
            ['/Netscape/i', 'Netscape', 'Netscape', 'Netscape'],
        ];

        $name = 'Unknown';
        $short = 'Unknown';
        $token = '';
        foreach ($browsers as [$test, $longName, $shortName, $versionToken]) {
            if (preg_match($test, $agent) === 1) {
                $name = $longName;
                $short = $shortName;
                $token = $versionToken;
                break;
            }
        }

        if (!$arr) {
            return $short;
        }

        $pattern = '~(?:' . ($token !== '' ? $token : 'Version') . ')[/ :]+([0-9][0-9.]*)~i';
        $version = preg_match($pattern, $agent, $m) === 1 ? $m[1] : '?';

        return [
            'userAgent' => $agent,
            'name' => $name,
            'ver' => $version,
            'platform' => $platform,
            'pattern' => $pattern,
        ];
    }
}

if (!function_exists('session')) {
    function session(): Session
    {
        return new Session();
    }
}

if (!function_exists('request')) {
    function request(): Request
    {
        return new Request();
    }
}

if (!function_exists('csrf')) {
    function csrf(bool $setExpired = true): CSRF
    {
        return new CSRF();
    }
}

if (!function_exists('abort')) {
    /** Stop with an HTTP error page, e.g. abort(404) or abort(403, 'No access'). */
    function abort(int $status = 404, string $message = ''): never
    {
        throw new HttpException($status, $message);
    }
}

if (!function_exists('redirectTo')) {
    /**
     * Redirect and stop. Relative paths ("/login", "dashboard") are resolved
     * against the application URL. Absolute URLs are only accepted for the
     * current host or hosts listed in App::redirect_hosts(), so user supplied
     * targets (?next=...) cannot be abused for open redirects.
     *
     * @throws InvalidArgumentException when the target is not allowed
     */
    function redirectTo(string $url, ?int $code = null): never
    {
        if (is_numeric($url)) {
            throw new InvalidArgumentException('URL cannot be numeric.');
        }
        if (headers_sent()) {
            throw new RuntimeException('Headers already sent.');
        }

        $target = Security::safeRedirectTarget($url);
        $code = in_array($code, [301, 302, 303, 307, 308], true) ? $code : 303;

        header('Location: ' . $target, true, $code);
        header('Content-Type: text/html; charset=UTF-8');

        $safe = esc($target);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Redirecting…</title></head><body>'
            . 'Redirecting to <a href="' . $safe . '">' . $safe . '</a></body></html>';
        exit;
    }
}

if (!function_exists('redirectExternal')) {
    /**
     * Redirect to an external http(s) URL (payment page, OAuth provider, ...).
     * Only use it with URLs that come from your own code or a trusted API,
     * NEVER with user input.
     */
    function redirectExternal(string $url, ?int $code = null): never
    {
        $url = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', '', $url));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (($scheme !== 'http' && $scheme !== 'https') || $host === '' || str_contains($url, '\\')) {
            throw new InvalidArgumentException('Invalid external URL.');
        }
        if (headers_sent()) {
            throw new RuntimeException('Headers already sent.');
        }

        $code = in_array($code, [301, 302, 303, 307, 308], true) ? $code : 302;
        header('Location: ' . $url, true, $code);
        exit;
    }
}
