<?php

declare(strict_types=1);

namespace Core\Conf\Kyaaaa;

class Request
{
    public const METHOD_POST = 'POST';
    public const METHOD_GET = 'GET';
    public const METHOD_PUT = 'PUT';
    public const METHOD_DELETE = 'DELETE';

    /** Files with these extensions are never stored, whatever the client says. */
    private const BLOCKED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'phtml', 'phar', 'phps', 'pgif', 'inc',
        'shtml', 'shtm', 'html', 'htm', 'xhtml', 'htaccess', 'htpasswd', 'ini', 'cgi', 'pl', 'py', 'rb',
        'sh', 'bash', 'exe', 'dll', 'bat', 'cmd', 'com', 'jsp', 'jspx', 'asp', 'aspx', 'cfm', 'asa', 'cer', 'war',
    ];

    /**
     * Get POST data by key name (everything when $key is null).
     */
    public function post(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null || $key === '') {
            return $_POST;
        }

        return $_POST[$key] ?? $default;
    }

    /**
     * Get an uploaded file ($_FILES entry) by key name.
     */
    public function file(?string $key = null): mixed
    {
        if ($key === null || $key === '') {
            return $_FILES;
        }

        return $_FILES[$key] ?? null;
    }

    /**
     * Sanitized file name of an upload (the client supplied name is not trusted).
     */
    public function getFileName(mixed $file): string
    {
        return self::sanitizeFilename((string) (is_array($file) ? ($file['name'] ?? '') : ''));
    }

    /**
     * Real MIME type detected from the file content (not the client supplied one).
     */
    public function getFileType(mixed $file): string
    {
        $path = is_array($file) ? (string) ($file['tmp_name'] ?? '') : '';
        if ($path === '' || !is_file($path)) {
            return '';
        }

        $type = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        return is_string($type) ? $type : '';
    }

    /**
     * File size in megabytes, formatted with two decimals ("0.50").
     */
    public function getFileSize(mixed $file): string
    {
        $size = is_array($file) ? (int) ($file['size'] ?? 0) : 0;

        return number_format($size / 1048576, 2, '.', '');
    }

    /**
     * Reduce a file name to [A-Za-z0-9._-] and strip any directory part.
     */
    public static function sanitizeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
        $name = (string) preg_replace('/\.{2,}/', '.', $name);
        $name = trim($name, '._');

        return $name === '' ? 'file' : $name;
    }

    /**
     * True when any extension segment is executable / dangerous
     * (so "shell.php.jpg" is rejected as well).
     */
    public static function hasBlockedExtension(string $filename): bool
    {
        $parts = explode('.', strtolower($filename));
        array_shift($parts);

        foreach ($parts as $part) {
            if (in_array($part, self::BLOCKED_EXTENSIONS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Move an uploaded file to a directory inside the public directory.
     *
     * - $to is relative to the public directory ("uploads/images"), ".." is refused
     * - $filename is sanitized, executable extensions are refused
     * - an existing file is only replaced when $replace is true
     * - the file must really come from an HTTP upload
     */
    public function move(mixed $from, string $to, string $filename, bool $replace = false): bool
    {
        if (!is_array($from) || (int) ($from['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return false;
        }

        $tmp = (string) ($from['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return false;
        }

        $filename = self::sanitizeFilename($filename);
        if (self::hasBlockedExtension($filename)) {
            return false;
        }

        $root = defined('PUBLIC_PATH') ? realpath(PUBLIC_PATH) : false;
        if ($root === false) {
            return false;
        }

        $relative = trim(str_replace('\\', '/', $to), '/');
        if ($relative !== '' && preg_match('~(^|/)\.\.?(/|$)~', $relative) === 1) {
            return false;
        }

        $dir = $root . ($relative === '' ? '' : DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        $realDir = realpath($dir);
        if ($realDir === false || ($realDir !== $root && !str_starts_with($realDir, $root . DIRECTORY_SEPARATOR))) {
            return false;
        }

        $target = $realDir . DIRECTORY_SEPARATOR . $filename;

        if (file_exists($target) || is_link($target)) {
            if (!$replace || is_link($target) || !is_file($target)) {
                return false;
            }
            unlink($target);
        }

        if (!move_uploaded_file($tmp, $target)) {
            return false;
        }

        chmod($target, 0644);

        return true;
    }

    /**
     * Check an upload against a list of allowed MIME types. The type is read
     * from the file content. Accepts ['image/png' => 'png', ...] or a plain list
     * of MIME types.
     *
     * @param array<int|string, string> $allowedTypes
     */
    public function validate(mixed $from, array $allowedTypes): bool
    {
        if (!is_array($from) || (int) ($from['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return false;
        }

        $tmp = (string) ($from['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return false;
        }

        $allowed = [];
        foreach ($allowedTypes as $key => $value) {
            $allowed[] = is_string($key) ? $key : (string) $value;
        }

        $type = $this->getFileType($from);

        return $type !== '' && in_array($type, $allowed, true);
    }

    /**
     * Get GET data by key name.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    /**
     * Get GET/POST data by key name (cookies are deliberately not included).
     */
    public function request(string $key, mixed $default = null): mixed
    {
        return array_merge($_GET, $_POST)[$key] ?? $default;
    }

    /**
     * Value of a request header, e.g. header('Content-Type').
     */
    public function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (strtoupper($name) === 'CONTENT-TYPE') {
            $key = 'CONTENT_TYPE';
        }

        $value = $_SERVER[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * Raw request body (webhooks), limited to $maxBytes.
     */
    public function body(int $maxBytes = 1048576): string
    {
        $body = file_get_contents('php://input', false, null, 0, $maxBytes);

        return $body === false ? '' : $body;
    }

    /**
     * Decoded JSON body, or null when the body is empty / not valid JSON.
     *
     * @return array<mixed>|null
     */
    public function json(int $maxBytes = 1048576): ?array
    {
        $body = $this->body($maxBytes);
        if ($body === '' || !json_validate($body)) {
            return null;
        }

        $data = json_decode($body, true, 32);

        return is_array($data) ? $data : null;
    }

    public function isAjax(): bool
    {
        return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }

    public function isPost(): bool
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === self::METHOD_POST;
    }

    public function isGet(): bool
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === self::METHOD_GET;
    }

    public function getMethod(): string
    {
        return strtolower((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
    }

    /**
     * Request URI. (Older versions returned the attacker controlled Referer for
     * AJAX requests, that is no longer the case.)
     */
    public function getUri(?string $default = null): ?string
    {
        return $_SERVER['REQUEST_URI'] ?? $default;
    }

    /**
     * Segment of the request path ("/a/b" -> [0 => '', 1 => 'a', 2 => 'b']).
     */
    public function getSegment(?int $segment = null): ?string
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $segments = explode('/', is_string($path) ? $path : '/');

        return $segment === null ? null : ($segments[$segment] ?? null);
    }
}
