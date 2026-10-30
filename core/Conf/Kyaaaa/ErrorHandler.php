<?php

declare(strict_types=1);

namespace Core\Conf\Kyaaaa;

use ErrorException;
use Throwable;

/**
 * Small, dependency free error / exception handler.
 *
 * - development: readable page with message, code excerpt and stack trace
 * - production : generic page, details only go to the PHP error log
 *
 * It never dumps $_SERVER, $_COOKIE, $_SESSION or environment data.
 */
final class ErrorHandler
{
    private static bool $registered = false;
    private static bool $handling = false;
    private static bool $handled = false;
    private static bool $debug = false;

    public static function register(bool $debug): void
    {
        self::$debug = $debug;

        if (self::$registered) {
            return;
        }
        self::$registered = true;

        set_exception_handler([self::class, 'handle']);

        // In production PHP keeps its normal behaviour (log and continue), only
        // development turns warnings/notices into exceptions.
        if ($debug) {
            set_error_handler([self::class, 'handleError']);
        }

        register_shutdown_function([self::class, 'handleShutdown']);
    }

    public static function handleError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        if ((error_reporting() & $severity) === 0) {
            return false;
        }

        // Deprecations are logged by PHP, they must not break the page.
        if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
            return false;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    public static function handleShutdown(): void
    {
        if (self::$handled) {
            return;
        }

        $error = error_get_last();
        if ($error === null) {
            return;
        }

        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array($error['type'], $fatal, true)) {
            return;
        }

        self::handle(new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']));
    }

    public static function handle(Throwable $e): void
    {
        if (self::$handling) {
            error_log('[kyaaaa] Error while handling an error: ' . self::oneLine($e->getMessage()));

            return;
        }

        self::$handling = true;
        self::$handled = true;

        try {
            $status = $e instanceof HttpException ? $e->getStatusCode() : 500;

            if ($status >= 500) {
                error_log(sprintf(
                    '[kyaaaa] %s: %s in %s:%d',
                    $e::class,
                    self::oneLine($e->getMessage()),
                    $e->getFile(),
                    $e->getLine()
                ));
            }

            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, sprintf("[kyaaaa] %s: %s\n", $e::class, $e->getMessage()));

                return;
            }

            while (ob_get_level() > 0 && @ob_end_clean()) {
                // discard partial output
            }

            if (!headers_sent()) {
                http_response_code($status);
                if ($e instanceof HttpException) {
                    foreach ($e->getHeaders() as $name => $value) {
                        if (preg_match('/^[A-Za-z0-9-]+$/', (string) $name) === 1 && preg_match('/[\r\n]/', (string) $value) !== 1) {
                            header($name . ': ' . $value);
                        }
                    }
                }
            }

            if (self::wantsJson()) {
                self::renderJson($e, $status);
            } else {
                self::renderHtml($e, $status);
            }
        } catch (Throwable $inner) {
            error_log('[kyaaaa] Error handler failed: ' . self::oneLine($inner->getMessage()));
            echo 'Internal Server Error';
        } finally {
            self::$handling = false;
        }
    }

    public static function statusText(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            408 => 'Request Timeout',
            413 => 'Payload Too Large',
            419 => 'Page Expired',
            422 => 'Unprocessable Content',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            default => $status >= 500 ? 'Server Error' : 'Error',
        };
    }

    private static function wantsJson(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $xhr = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        $wantsHtml = str_contains($accept, 'text/html');

        if ($wantsHtml) {
            return false;
        }

        return $xhr || str_contains($accept, 'application/json');
    }

    private static function publicMessage(Throwable $e, int $status): string
    {
        if (self::$debug) {
            return $e->getMessage() !== '' ? $e->getMessage() : self::statusText($status);
        }

        if ($e instanceof HttpException && $status < 500 && $e->getMessage() !== '') {
            return $e->getMessage();
        }

        return $status >= 500 ? 'Kyaaaa~ Something Went Wrong!' : self::statusText($status);
    }

    private static function renderJson(Throwable $e, int $status): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
        }

        $error = [
            'status' => $status,
            'message' => self::publicMessage($e, $status),
        ];

        if (self::$debug) {
            $error['exception'] = $e::class;
            $error['file'] = self::shortPath($e->getFile());
            $error['line'] = $e->getLine();
            $error['trace'] = [];

            foreach (array_slice($e->getTrace(), 0, 15) as $frame) {
                if (isset($frame['file'])) {
                    $error['trace'][] = self::shortPath($frame['file']) . ':' . ($frame['line'] ?? '?');
                }
            }
        }

        echo json_encode(
            ['error' => $error],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
    }

    private static function renderHtml(Throwable $e, int $status): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store');
        }

        if (self::$debug) {
            try {
                echo ErrorPage::render($e, $status);

                return;
            } catch (Throwable $inner) {
                // The rich page failed: fall back to the plain one below.
                error_log('[kyaaaa] Error page failed: ' . self::oneLine($inner->getMessage()));
            }
        }

        echo self::page($status, self::statusText($status), self::publicMessage($e, $status));
    }

    private static function page(int $status, string $title, string $message): string
    {
        $css = 'body{margin:0;padding:24px;background:#202124;color:#abaebb;font:15px/1.5 "Courier New",monospace}'
            . '.box{max-width:768px;margin:40px auto 16px;padding:10px;background:#b12776;color:#fff;'
            . 'border-radius:8px;font-size:17px;letter-spacing:1.8px;text-align:center}'
            . '.msg{max-width:768px;margin:0 auto 24px;text-align:center;word-break:break-word}';

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Kyaaaa~ ' . self::e((string) $status) . '</title><style>' . $css . '</style></head><body>'
            . '<div class="box">' . self::e($status . ': ' . $title) . '</div>'
            . '<p class="msg">' . self::e($message) . '</p></body></html>';
    }

    private static function shortPath(string $path): string
    {
        if (defined('ROOT_PATH') && str_starts_with($path, ROOT_PATH)) {
            return '…' . substr($path, strlen(ROOT_PATH));
        }

        return $path;
    }

    private static function oneLine(string $text): string
    {
        return str_replace(["\r", "\n"], ' ', $text);
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
