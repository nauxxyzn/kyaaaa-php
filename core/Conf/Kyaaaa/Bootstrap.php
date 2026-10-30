<?php

declare(strict_types=1);

namespace Core\Conf\Kyaaaa;

/**
 * One-time application bootstrap: paths, error handling, timezone, security
 * headers and session. Called automatically by the Router.
 */
final class Bootstrap
{
    private static bool $booted = false;
    private static bool $debug = false;

    public static function isDebug(): bool
    {
        return self::$debug;
    }

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        if (!defined('ROOT_PATH')) {
            define('ROOT_PATH', dirname(__DIR__, 3));
        }
        if (!defined('PUBLIC_PATH')) {
            $public = realpath(ROOT_PATH . DIRECTORY_SEPARATOR . 'public');
            define('PUBLIC_PATH', $public !== false ? $public : rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\'));
        }

        $app = new \Core\Conf\App();
        self::$debug = strtolower((string) $app->environment()) === 'development';

        // Errors are never printed by PHP itself: ErrorHandler renders them in
        // development and shows a generic page in production.
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        ini_set('log_errors', '1');
        ini_set('html_errors', '0');
        // Keep function arguments (passwords!) out of stack traces.
        ini_set('zend.exception_ignore_args', '1');

        ErrorHandler::register(self::$debug);

        if (self::$debug) {
            DebugBar::start(); // development toolbar, never started in production
        }

        date_default_timezone_set((string) $app->app_timezone());

        Security::sendHeaders();
        Security::assertHost();

        $app->session();
        if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
            Session::config();
        }

        DebugBar::mark('Bootstrap finished');
    }
}
