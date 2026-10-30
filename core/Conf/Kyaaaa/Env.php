<?php

declare(strict_types=1);

namespace Core\Conf\Kyaaaa;

/**
 * Tiny .env reader so secrets (database password, SMTP password, ...) can live
 * outside of the source code.
 *
 * Values are always returned as raw strings (no "true"/"null" magic), so a
 * password such as "true" is never silently converted to something else.
 * Real environment variables take precedence over the .env file.
 */
final class Env
{
    private static bool $loaded = false;

    /** @var array<string, string> */
    private static array $values = [];

    public static function load(?string $file = null): void
    {
        $file ??= dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '.env';
        self::$loaded = true;

        if (!is_file($file) || !is_readable($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                continue;
            }

            $value = trim(substr($line, $pos + 1));
            $length = strlen($value);

            if ($length >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[$length - 1] === $value[0]) {
                $value = substr($value, 1, -1);
            } else {
                $comment = strpos($value, ' #');
                if ($comment !== false) {
                    $value = rtrim(substr($value, 0, $comment));
                }
            }

            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (!self::$loaded) {
            self::load();
        }

        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        return self::$values[$key] ?? $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Comma separated list, e.g. APP_ALLOWED_HOSTS=example.com,www.example.com
     *
     * @return list<string>
     */
    public static function csv(string $key): array
    {
        $value = self::get($key);
        if ($value === null || trim($value) === '') {
            return [];
        }

        $items = [];
        foreach (explode(',', $value) as $item) {
            $item = trim($item);
            if ($item !== '') {
                $items[] = $item;
            }
        }

        return $items;
    }
}
