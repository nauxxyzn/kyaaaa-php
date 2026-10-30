<?php

declare(strict_types=1);

namespace Core\Conf\Kyaaaa;

use InvalidArgumentException;

/**
 * Request level security helpers: trusted host / base URL, HTTPS detection,
 * safe redirects and default response headers.
 */
final class Security
{
    private const HOST_PATTERN = '/^(?:[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?|\[[0-9A-Fa-f:.]+\])(?::\d{1,5})?$/D';

    private static ?object $app = null;

    /**
     * Read an optional setting from \Core\Conf\App. Older App.php files that do
     * not know the setting simply fall back to $default.
     */
    public static function appConfig(string $method, mixed $default = null): mixed
    {
        self::$app ??= new \Core\Conf\App();

        return method_exists(self::$app, $method) ? self::$app->{$method}() : $default;
    }

    public static function isSecure(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }

        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }

        // X-Forwarded-Proto can be forged by any client, so only trust it when
        // the application explicitly says it runs behind a trusted proxy.
        if ((bool) self::appConfig('trust_proxy', false)) {
            $proto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));

            return $proto === 'https';
        }

        return false;
    }

    /** @return list<string> */
    public static function allowedHosts(): array
    {
        $hosts = [];
        foreach ((array) self::appConfig('allowed_hosts', []) as $host) {
            $hosts[] = strtolower(trim((string) $host));
        }

        return array_values(array_filter($hosts, static fn(string $h): bool => $h !== ''));
    }

    /** @return list<string> */
    public static function redirectHosts(): array
    {
        $hosts = [];
        foreach ((array) self::appConfig('redirect_hosts', []) as $host) {
            $hosts[] = strtolower(trim((string) $host));
        }

        return array_values(array_filter($hosts, static fn(string $h): bool => $h !== ''));
    }

    /**
     * Throws a 400 when the Host header is malformed or not in allowed_hosts().
     * Prevents Host header poisoning (password reset links, cache poisoning...).
     */
    public static function assertHost(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host !== '' && (preg_match(self::HOST_PATTERN, $host) !== 1 || !self::hostAllowed($host))) {
            throw new HttpException(400, 'Invalid host header');
        }
    }

    private static function hostAllowed(string $hostWithPort): bool
    {
        $allowed = self::allowedHosts();
        if ($allowed === []) {
            return true;
        }

        $name = strtolower((string) preg_replace('/:\d{1,5}$/', '', $hostWithPort));

        return in_array($name, $allowed, true);
    }

    /** Validated "host[:port]" of the current request. */
    public static function host(): string
    {
        $candidate = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if (preg_match(self::HOST_PATTERN, $candidate) === 1 && self::hostAllowed($candidate)) {
            return strtolower($candidate);
        }

        $name = (string) ($_SERVER['SERVER_NAME'] ?? '');
        if ($name !== '' && preg_match(self::HOST_PATTERN, $name) === 1) {
            $port = (int) ($_SERVER['SERVER_PORT'] ?? 0);
            $standard = $port === 0 || $port === 80 || $port === 443;

            return strtolower($name) . ($standard ? '' : ':' . $port);
        }

        return 'localhost';
    }

    public static function hostName(): string
    {
        return (string) preg_replace('/:\d{1,5}$/', '', self::host());
    }

    /** Base URL with a trailing slash. Uses App::base_url() when configured. */
    public static function baseUrl(): string
    {
        $configured = trim((string) self::appConfig('base_url', ''));
        if ($configured !== '') {
            return rtrim($configured, '/') . '/';
        }

        $scheme = self::isSecure() ? 'https' : 'http';
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
        if ($dir === '.') {
            $dir = '';
        }
        $dir = (string) preg_replace('#[^A-Za-z0-9/_.%~-]#', '', $dir);
        if ($dir !== '' && $dir[0] !== '/') {
            $dir = '/' . $dir;
        }

        return $scheme . '://' . self::host() . $dir . '/';
    }

    /** @return list<string> */
    public static function trustedHostNames(): array
    {
        $names = [self::hostName()];
        foreach (self::allowedHosts() as $host) {
            $names[] = $host;
        }
        foreach (self::redirectHosts() as $host) {
            $names[] = $host;
        }

        $base = parse_url((string) self::appConfig('base_url', ''), PHP_URL_HOST);
        if (is_string($base) && $base !== '') {
            $names[] = strtolower($base);
        }

        return array_values(array_unique($names));
    }

    /**
     * Validates a redirect target. Relative paths are resolved against the base
     * URL; absolute URLs are only accepted for trusted hosts. This stops open
     * redirects such as ?next=https://evil.example.
     *
     * @throws InvalidArgumentException
     */
    public static function safeRedirectTarget(string $url): string
    {
        $url = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', '', $url));
        if ($url === '' || str_contains($url, '\\')) {
            throw new InvalidArgumentException('Invalid redirect target.');
        }

        $parts = parse_url($url);
        if ($parts === false) {
            throw new InvalidArgumentException('Invalid redirect target.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '') {
            // javascript:, data:, mailto: ... are never valid targets
            if ($scheme !== '' || str_starts_with($url, '//')) {
                throw new InvalidArgumentException('Invalid redirect target.');
            }

            return self::baseUrl() . ltrim($url, '/');
        }

        if ($scheme !== '' && $scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidArgumentException('Invalid redirect target.');
        }

        if (!in_array($host, self::trustedHostNames(), true)) {
            throw new InvalidArgumentException('Redirect target host is not allowed.');
        }

        return $scheme === '' ? (self::isSecure() ? 'https:' : 'http:') . $url : $url;
    }

    public static function isSafeRedirect(string $url): bool
    {
        try {
            self::safeRedirectTarget($url);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Same-origin check for state changing requests (defence in depth next to
     * the CSRF token). A missing Origin header is accepted.
     */
    public static function originAllowed(): bool
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if (!is_string($origin) || $origin === '') {
            return true;
        }

        $host = parse_url($origin, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false; // "null" origin (sandboxed iframe, file://, ...)
        }

        return in_array(strtolower($host), self::trustedHostNames(), true);
    }

    /** Default hardening headers. Override or disable them via App::security_headers(). */
    public static function sendHeaders(): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        header_remove('X-Powered-By');

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ];

        if (self::isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }

        foreach ((array) self::appConfig('security_headers', []) as $name => $value) {
            $headers[(string) $name] = $value;
        }

        foreach ($headers as $name => $value) {
            if ($value === false || $value === null) {
                continue; // explicitly disabled
            }
            $value = (string) $value;
            if (preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1 || preg_match('/[\r\n]/', $value) === 1) {
                continue;
            }
            header($name . ': ' . $value);
        }
    }
}
