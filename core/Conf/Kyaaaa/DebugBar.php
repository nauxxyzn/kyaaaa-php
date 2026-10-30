<?php

declare(strict_types=1);

namespace Core\Conf\Kyaaaa;

/**
 * Development-only collector behind the floating debug toolbar.
 *
 * It is switched on by Bootstrap when APP_ENV=development. In production
 * start() is never called, so every hook below returns immediately and nothing
 * is collected, buffered or injected.
 */
final class DebugBar
{
    private static bool $enabled = false;
    private static float $start = 0.0;

    /** @var list<array{sql: string, bindings: array<int|string, mixed>, ms: float, rows: ?int, error: ?string}> */
    private static array $queries = [];

    /** @var list<array{name: string, kind: string, ms: float, vars: array<string, string>}> */
    private static array $views = [];

    /** @var list<array{label: string, dump: string, ms: float}> */
    private static array $logs = [];

    /** @var list<array{label: string, at: float}> */
    private static array $marks = [];

    /** @var array{method: string, path: string, handler: string, params: array<string, string>, middleware: int}|null */
    private static ?array $route = null;

    /** @var list<array{method: string, path: string, handler: string}> */
    private static array $routes = [];

    /** @var array<string, string> */
    private static array $assets = [];

    /**
     * @param bool $capture buffer the response so the toolbar can be injected
     *                      (never done on the command line)
     */
    public static function start(bool $capture = true): void
    {
        if (self::$enabled || ($capture && PHP_SAPI === 'cli')) {
            return;
        }

        self::$enabled = true;
        self::$start = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        self::mark('Framework started');

        if ($capture) {
            ob_start([self::class, 'filter']);
        }
    }

    public static function enabled(): bool
    {
        return self::$enabled;
    }

    public static function mark(string $label): void
    {
        if (self::$enabled) {
            self::$marks[] = ['label' => $label, 'at' => microtime(true)];
        }
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @param list<string>             $masked   placeholders whose value must not be shown
     */
    public static function query(string $sql, array $bindings, float $ms, ?int $rows, array $masked = [], ?string $error = null): void
    {
        if (!self::$enabled || count(self::$queries) >= 500) {
            return;
        }

        foreach ($masked as $name) {
            if (array_key_exists($name, $bindings)) {
                $bindings[$name] = DebugView::MASK;
            }
        }

        self::$queries[] = ['sql' => $sql, 'bindings' => $bindings, 'ms' => $ms, 'rows' => $rows, 'error' => $error];
    }

    public static function lastQueryRows(int $rows): void
    {
        if (!self::$enabled || self::$queries === []) {
            return;
        }

        $last = array_key_last(self::$queries);
        if (self::$queries[$last]['rows'] === null) {
            self::$queries[$last]['rows'] = $rows;
        }
    }

    /**
     * @param array<string, mixed> $data variables handed to the view
     */
    public static function view(string $name, string $kind, float $ms, array $data): void
    {
        if (!self::$enabled || count(self::$views) >= 200) {
            return;
        }

        $vars = [];
        foreach ($data as $key => $value) {
            if ($kind === 'layout' && $key === 'content') {
                continue;
            }
            $vars[(string) $key] = DebugView::preview($value, (string) $key);
            if (count($vars) >= 30) {
                break;
            }
        }

        self::$views[] = ['name' => $name, 'kind' => $kind, 'ms' => $ms, 'vars' => $vars];
    }

    /** Snapshot a value for the Log tab (see the dbg() helper). */
    public static function log(mixed $value, string $label = ''): void
    {
        if (!self::$enabled || count(self::$logs) >= 100) {
            return;
        }

        self::$logs[] = [
            'label' => $label,
            'dump' => DebugView::dump($value),
            'ms' => (microtime(true) - self::$start) * 1000,
        ];
    }

    /**
     * @param array{method: string, path: string, handler: string, params: array<string, string>, middleware: int} $info
     */
    public static function route(array $info): void
    {
        if (self::$enabled) {
            self::$route = $info;
        }
    }

    /**
     * @param list<array{method: string, path: string, handler: string}> $routes
     */
    public static function routes(array $routes): void
    {
        if (self::$enabled) {
            self::$routes = $routes;
        }
    }

    // ------------------------------------------------------------------
    // Collecting
    // ------------------------------------------------------------------

    /**
     * Everything the toolbar and the error page show, with sensitive values masked.
     *
     * @return array<string, mixed>
     */
    public static function collect(): array
    {
        $start = self::$start > 0 ? self::$start : (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));

        $dbMs = 0.0;
        foreach (self::$queries as $query) {
            $dbMs += $query['ms'];
        }

        $viewMs = 0.0;
        foreach (self::$views as $view) {
            $viewMs += $view['ms'];
        }

        $marks = [];
        foreach (self::$marks as $mark) {
            $marks[] = ['label' => $mark['label'], 'ms' => ($mark['at'] - $start) * 1000];
        }

        $status = http_response_code();

        return [
            'status' => is_int($status) ? $status : 200,
            'time_ms' => (microtime(true) - $start) * 1000,
            'db_ms' => $dbMs,
            'view_ms' => $viewMs,
            'memory' => memory_get_usage(),
            'peak' => memory_get_peak_usage(),
            'memory_limit' => (string) ini_get('memory_limit'),
            'request' => self::requestInfo(),
            'response_headers' => self::responseHeaders(),
            'route' => self::$route,
            'routes' => self::$routes,
            'queries' => self::$queries,
            'views' => self::$views,
            'logs' => self::$logs,
            'marks' => $marks,
            'files' => self::files(),
            'session' => self::sessionInfo(),
            'env' => self::envInfo(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function requestInfo(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $key))))] = $value;
            }
        }

        $files = [];
        foreach ($_FILES as $field => $file) {
            if (!is_array($file) || !isset($file['name'])) {
                continue;
            }

            $files[(string) $field] = is_array($file['name'])
                ? 'multiple files'
                : (string) $file['name'] . ' (' . DebugView::bytes((int) ($file['size'] ?? 0)) . ', ' . (string) ($file['type'] ?? '') . ')';
        }

        return [
            'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? 'CLI'),
            'uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'get' => DebugView::maskArray($_GET),
            'post' => DebugView::maskArray($_POST),
            'cookies' => array_fill_keys(array_map('strval', array_keys($_COOKIE)), DebugView::MASK),
            'headers' => DebugView::maskArray($headers),
            'files' => $files,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function responseHeaders(): array
    {
        $headers = [];
        foreach (headers_list() as $line) {
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $headers[trim(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
            }
        }

        /** @var array<string, string> $masked */
        $masked = DebugView::maskArray($headers);

        return $masked;
    }

    /**
     * @return list<array{path: string, kind: string, size: int}>
     */
    private static function files(): array
    {
        $files = [];
        foreach (get_included_files() as $file) {
            $files[] = [
                'path' => DebugView::shorten($file),
                'kind' => DebugView::classify($file),
                'size' => is_file($file) ? (int) filesize($file) : 0,
            ];
        }

        return $files;
    }

    /**
     * @return array{active: bool, name: string, id: string, data: array<int|string, mixed>}
     */
    private static function sessionInfo(): array
    {
        $active = session_status() === PHP_SESSION_ACTIVE;

        return [
            'active' => $active,
            'name' => $active ? session_name() : '',
            'id' => $active ? (string) session_id() : '',
            'data' => $active ? DebugView::maskArray($_SESSION ?? []) : [],
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function envInfo(): array
    {
        return [
            'PHP' => PHP_VERSION,
            'SAPI' => PHP_SAPI,
            'OS' => PHP_OS_FAMILY,
            'Environment' => (string) Security::appConfig('environment', 'development'),
            'Timezone' => date_default_timezone_get(),
            'Memory limit' => (string) ini_get('memory_limit'),
            'Max execution time' => (string) ini_get('max_execution_time') . ' s',
            'OPcache' => (function_exists('opcache_get_status') && (bool) ini_get('opcache.enable')) ? 'enabled' : 'disabled',
            'PDO drivers' => class_exists(\PDO::class) ? implode(', ', \PDO::getAvailableDrivers()) : 'none',
            'Extensions loaded' => (string) count(get_loaded_extensions()),
        ];
    }

    // ------------------------------------------------------------------
    // Injection
    // ------------------------------------------------------------------

    /**
     * Output buffer callback: adds the toolbar to full HTML pages, a
     * Server-Timing header to every response, and leaves everything else alone.
     */
    public static function filter(string $buffer, int $phase): string
    {
        if (($phase & PHP_OUTPUT_HANDLER_CLEAN) !== 0) {
            return $buffer; // the buffer is being discarded
        }

        try {
            self::sendTiming();

            if (!self::isHtmlResponse()) {
                return $buffer;
            }

            $pos = strripos($buffer, '</body>');
            if ($pos === false) {
                return $buffer;
            }

            return substr($buffer, 0, $pos) . self::toolbar() . substr($buffer, $pos);
        } catch (\Throwable $e) {
            error_log('[kyaaaa] Debug toolbar failed: ' . str_replace(["\r", "\n"], ' ', $e->getMessage()));

            return $buffer;
        }
    }

    private static function isHtmlResponse(): bool
    {
        $status = http_response_code();
        if (is_int($status) && (($status >= 300 && $status < 400) || $status === 204)) {
            return false;
        }

        foreach (headers_list() as $header) {
            if (stripos($header, 'content-type:') === 0) {
                return stripos($header, 'text/html') !== false;
            }
        }

        return true; // PHP's default_mimetype is text/html
    }

    private static function sendTiming(): void
    {
        if (headers_sent()) {
            return;
        }

        $total = (microtime(true) - (self::$start > 0 ? self::$start : microtime(true))) * 1000;
        $db = 0.0;
        foreach (self::$queries as $query) {
            $db += $query['ms'];
        }

        header(sprintf('Server-Timing: total;dur=%.1f, db;dur=%.1f;desc="%d queries"', $total, $db, count(self::$queries)));
    }

    /**
     * The toolbar markup: an inert <template> plus the script that builds a
     * Shadow DOM from it, so the page's own CSS can never affect the toolbar.
     *
     * @param array<string, mixed>|null $data result of collect(), collected now when null
     */
    public static function toolbar(?array $data = null): string
    {
        $d = $data ?? self::collect();
        $panels = DebugView::panels($d);

        $html = '<template id="kyaaaa-debug-tpl"><style>' . self::asset('panels.css') . self::asset('toolbar.css') . '</style>'
            . '<div class="wrap"><div class="dock">'
            . '<button type="button" class="mark" aria-expanded="true" aria-label="Collapse or expand the Kyaaaa debug toolbar" title="Kyaaaa debug (Alt+K)"><b>K~</b></button>'
            . (DebugView::isPublicClient((string) $d['request']['ip'])
                ? '<span class="notice" title="APP_ENV=development exposes file paths, queries and request data. Never leave it on for a public site.">Public client</span>'
                : '')
            . '<div class="chips">';

        foreach (DebugView::chips($d) as $chip) {
            $html .= '<button type="button" class="chip tone-' . DebugView::e($chip['tone']) . '" data-panel="' . DebugView::e($chip['panel'])
                . '" data-title="' . DebugView::e($chip['title']) . '" aria-expanded="false">'
                . '<span class="l">' . DebugView::e($chip['label']) . '</span><span class="v">' . DebugView::e($chip['value']) . '</span></button>';
        }

        $html .= '</div></div><div class="sheet" hidden><div class="sheet-head"><h2 class="sheet-title"></h2>'
            . '<button type="button" class="sheet-close" aria-label="Close panel">&times;</button></div><div class="sheet-body kd">';

        foreach ($panels as $id => $panel) {
            $html .= '<section class="panel" data-panel="' . DebugView::e($id) . '" hidden>' . $panel . '</section>';
        }

        return $html . '</div></div></div></template><script>' . self::asset('toolbar.js') . '</script>';
    }

    /** Contents of one of the files in Debug/ (CSS and JS for the dev tools). */
    public static function asset(string $name): string
    {
        if (!isset(self::$assets[$name])) {
            $content = is_file(__DIR__ . '/Debug/' . $name) ? file_get_contents(__DIR__ . '/Debug/' . $name) : false;
            self::$assets[$name] = $content === false ? '' : $content;
        }

        return self::$assets[$name];
    }
}
