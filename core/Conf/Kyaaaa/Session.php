<?php

declare(strict_types=1);

namespace Core\Conf\Kyaaaa;

use RuntimeException;

class Session
{
    /**
     * Secure defaults. Anything passed to config() overrides these, except the
     * options that are forced below.
     *
     * @see https://www.php.net/manual/en/session.configuration.php
     */
    private const DEFAULTS = [
        'name' => 'kyaaaa_session',
        'cookie_lifetime' => 86400, // seconds
        'cookie_domain' => '',
        'cookie_path' => '/',
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cache_expire' => 180,
        'cache_limiter' => 'nocache',
    ];

    /**
     * Start the session with secure defaults (HttpOnly, SameSite, Secure on
     * HTTPS, strict mode against session fixation).
     *
     * @param array<string, mixed> $options
     */
    public static function config(array $options = []): void
    {
        if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $options = array_merge(self::DEFAULTS, $options);

        if (($options['cookie_samesite'] ?? '') === '') {
            $options['cookie_samesite'] = 'Lax';
        }
        if (!array_key_exists('cookie_secure', $options)) {
            $options['cookie_secure'] = Security::isSecure();
        }

        // Always forced: never accept unknown ids or ids passed in the URL.
        $options['use_strict_mode'] = true;
        $options['use_only_cookies'] = true;

        if (!session_start($options)) {
            throw new RuntimeException('Unable to start the session.');
        }
    }

    /**
     * Set one value, or several at once with an array.
     *
     * @param string|int|array<string|int, mixed> $key
     */
    public function set(string|int|array $key, mixed $value = null): void
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $_SESSION[$k] = $v;
            }

            return;
        }

        $_SESSION[$key] = $value;
    }

    /**
     * Set flash data (read it once with getFlash()).
     *
     * @param string|int|array<string|int, mixed> $key
     */
    public function flash(string|int|array $key, mixed $value = null): void
    {
        $this->set($key, $value);
    }

    public function get(string|int $key, mixed $default = null): mixed
    {
        return $this->has($key) ? $_SESSION[$key] : $default;
    }

    /** Get a value and remove it from the session. */
    public function getFlash(string|int $key): mixed
    {
        $value = $this->get($key);
        $this->remove($key);

        return $value;
    }

    public function has(string|int $key): bool
    {
        return array_key_exists($key, $_SESSION ?? []);
    }

    public function remove(string|int $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Empty the session, delete the cookie and destroy the session data. */
    public function clear(): void
    {
        $_SESSION = [];

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        if (ini_get('session.use_cookies') && !headers_sent()) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] !== '' ? $params['samesite'] : 'Lax',
            ]);
        }

        session_destroy();
    }

    /** Get the current session id, or set one (only before the session starts). */
    public function id(?string $id = null): string|false
    {
        return $id === null ? session_id() : session_id($id);
    }

    /**
     * Issue a new session id. Call this right after login / logout / privilege
     * changes to prevent session fixation. The CSRF token is rotated as well.
     */
    public function regenerate(bool $deleteOldSession = true): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        unset($_SESSION['csrf']);

        return session_regenerate_id($deleteOldSession);
    }
}
