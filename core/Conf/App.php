<?php

declare(strict_types=1);

namespace Core\Conf;

use Core\Conf\Kyaaaa\Env;
use Core\Conf\Kyaaaa\Session;

class App
{
    /**
     * Environment: 'development' shows detailed errors, anything else is treated
     * as production (generic error page, details only in the PHP error log).
     * Defaults to production, set APP_ENV=development in .env on your machine.
     *
     * @return string 'development' or 'production'
     */
    public function environment(): string
    {
        return strtolower((string) Env::get('APP_ENV', 'production'));
    }

    /**
     * Timezone config.
     *
     * @see https://www.php.net/manual/en/timezones.php
     */
    public function app_timezone(): string
    {
        return (string) Env::get('APP_TIMEZONE', 'Asia/Jakarta');
    }

    /**
     * Public URL of the application, e.g. https://example.com (no trailing slash
     * needed). Strongly recommended in production: url() then never depends on
     * the Host header sent by the visitor.
     */
    public function base_url(): string
    {
        return (string) Env::get('APP_URL', '');
    }

    /**
     * Host names this application answers to. Requests for any other Host header
     * get a 400. Empty list = no restriction.
     *
     * @return list<string>
     */
    public function allowed_hosts(): array
    {
        return Env::csv('APP_ALLOWED_HOSTS');
    }

    /**
     * Extra hosts redirectTo() may send visitors to (the current host is always
     * allowed). Use redirectExternal() for trusted one-off URLs instead.
     *
     * @return list<string>
     */
    public function redirect_hosts(): array
    {
        return Env::csv('APP_REDIRECT_HOSTS');
    }

    /**
     * Set TRUST_PROXY=true only when the app runs behind a reverse proxy / CDN
     * that sets X-Forwarded-Proto (it is used to detect HTTPS).
     */
    public function trust_proxy(): bool
    {
        return Env::bool('TRUST_PROXY', false);
    }

    /**
     * Response headers sent on every request. Return e.g.
     * ['Content-Security-Policy' => "default-src 'self'", 'X-Frame-Options' => false]
     * to add a header or disable a default one (false).
     *
     * @return array<string, string|false>
     */
    public function security_headers(): array
    {
        return [];
    }

    /**
     * Session config. Secure defaults (HttpOnly, SameSite=Lax, Secure on HTTPS,
     * strict mode) are applied by Session::config(); override them here.
     *
     * @see https://www.php.net/manual/en/session.configuration.php
     */
    public function session(): void
    {
        Session::config([
            'name' => 'kyaaaa_session',
            'cookie_lifetime' => 86400, // seconds
            'cookie_path' => '/',
        ]);
    }
}
