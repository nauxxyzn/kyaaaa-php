<?php

declare(strict_types=1);

namespace Core\Conf\Kyaaaa;

use DateTimeInterface;

/**
 * HTML rendering and value formatting for the development tools (debug toolbar
 * and error page). Everything that is printed goes through e(), and values whose
 * key looks sensitive (password, token, cookie, ...) are replaced by MASK.
 */
final class DebugView
{
    public const MASK = '••••••';

    private const SENSITIVE = '/pass|pwd|secret|token|api[_-]?key|private|auth|cookie|csrf|sess|credential|signature|bearer/i';

    /** Queries slower than this (milliseconds) are flagged. */
    private const SLOW_QUERY_MS = 50.0;

    // ------------------------------------------------------------------
    // Formatting helpers
    // ------------------------------------------------------------------

    public static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function isSensitive(string $key): bool
    {
        return preg_match(self::SENSITIVE, $key) === 1;
    }

    public static function ms(float $ms): string
    {
        if ($ms >= 1000) {
            return number_format($ms / 1000, 2) . ' s';
        }

        return number_format($ms, $ms < 10 ? 2 : 1) . ' ms';
    }

    public static function bytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024;
        $i = 0;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return number_format($value, $value < 10 ? 2 : 1) . ' ' . $units[$i];
    }

    /** "128M" -> bytes, "-1" / "" -> -1 (unlimited). */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }

    public static function cut(string $text, int $length): string
    {
        return mb_strlen($text, 'UTF-8') > $length ? mb_substr($text, 0, $length, 'UTF-8') . '…' : $text;
    }

    /**
     * True for a client on a public address. The dev tools warn about it, because
     * it usually means APP_ENV=development was left on for a public site.
     */
    public static function isPublicClient(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /** app | framework | vendor | php */
    public static function classify(string $path): string
    {
        $root = defined('ROOT_PATH') ? str_replace('\\', '/', (string) ROOT_PATH) : '';
        $path = str_replace('\\', '/', $path);

        if ($root !== '' && str_starts_with($path, $root . '/vendor/')) {
            return 'vendor';
        }
        if ($root !== '' && str_starts_with($path, $root . '/core/Conf/Kyaaaa/')) {
            return 'framework';
        }
        if ($root !== '' && str_starts_with($path, $root . '/')) {
            return 'app';
        }

        return 'php';
    }

    public static function shorten(string $path): string
    {
        $root = defined('ROOT_PATH') ? str_replace('\\', '/', (string) ROOT_PATH) : '';
        $normalized = str_replace('\\', '/', $path);

        if ($root !== '' && str_starts_with($normalized, $root . '/')) {
            return substr($normalized, strlen($root) + 1);
        }

        return $path;
    }

    // ------------------------------------------------------------------
    // Masking and dumping
    // ------------------------------------------------------------------

    /**
     * @param array<int|string, mixed> $data
     * @return array<int|string, mixed>
     */
    public static function maskArray(array $data, int $depth = 0): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (self::isSensitive((string) $key)) {
                $out[$key] = self::MASK;
                continue;
            }

            $out[$key] = (is_array($value) && $depth < 5) ? self::maskArray($value, $depth + 1) : $value;
        }

        return $out;
    }

    /** One-line summary of a value. */
    public static function preview(mixed $value, string $key = ''): string
    {
        if ($key !== '' && self::isSensitive($key)) {
            return self::MASK;
        }

        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => '"' . self::cut(str_replace(["\r", "\n", "\t"], ' ', $value), 80) . '"',
            is_array($value) => 'array(' . count($value) . ')',
            is_object($value) => $value::class,
            default => get_debug_type($value),
        };
    }

    /** Multi-line, depth limited, plain-text dump (escape it before printing). */
    public static function dump(mixed $value, int $depth = 0, string $key = ''): string
    {
        if ($key !== '' && self::isSensitive($key)) {
            return self::MASK;
        }
        if ($depth > 4) {
            return '…';
        }

        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            return self::quote($value);
        }
        if (is_array($value)) {
            return self::dumpArray($value, $depth);
        }
        if ($value instanceof DateTimeInterface) {
            return $value::class . '(' . $value->format('Y-m-d H:i:s') . ')';
        }
        if (is_object($value)) {
            return self::dumpObject($value, $depth);
        }
        if (is_resource($value)) {
            return 'resource(' . get_resource_type($value) . ')';
        }

        return get_debug_type($value);
    }

    private static function quote(string $value): string
    {
        $short = self::cut($value, 300);

        return '"' . str_replace(['\\', '"', "\r", "\n", "\t"], ['\\\\', '\\"', '\\r', '\\n', '\\t'], $short) . '"';
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private static function dumpArray(array $value, int $depth): string
    {
        if ($value === []) {
            return '[]';
        }

        $pad = str_repeat('  ', $depth + 1);
        $lines = [];
        $count = 0;

        foreach ($value as $key => $item) {
            if (++$count > 50) {
                $lines[] = $pad . '… ' . (count($value) - 50) . ' more';
                break;
            }

            $label = is_int($key) ? (string) $key : self::quote((string) $key);
            $lines[] = $pad . $label . ' => ' . self::dump($item, $depth + 1, (string) $key);
        }

        return "[\n" . implode(",\n", $lines) . "\n" . str_repeat('  ', $depth) . ']';
    }

    private static function dumpObject(object $value, int $depth): string
    {
        $props = get_object_vars($value); // public properties only
        if ($props === []) {
            return $value::class . ' {}';
        }

        $pad = str_repeat('  ', $depth + 1);
        $lines = [];
        $count = 0;

        foreach ($props as $name => $item) {
            if (++$count > 30) {
                $lines[] = $pad . '… more';
                break;
            }
            $lines[] = $pad . $name . ': ' . self::dump($item, $depth + 1, (string) $name);
        }

        return $value::class . " {\n" . implode(",\n", $lines) . "\n" . str_repeat('  ', $depth) . '}';
    }

    /**
     * Key / value table. Strings are shown as they are, other values are dumped.
     *
     * @param array<int|string, mixed> $rows
     */
    public static function kv(array $rows): string
    {
        if ($rows === []) {
            return '<p class="empty">Nothing here.</p>';
        }

        $html = '<table class="kv"><tbody>';
        foreach ($rows as $key => $value) {
            $name = (string) $key;

            if (self::isSensitive($name)) {
                $cell = self::MASK;
            } elseif (is_string($value)) {
                $cell = self::cut($value, 600);
            } else {
                $cell = self::dump($value, 0, $name);
            }

            $html .= '<tr><th>' . self::e($name) . '</th><td><pre>' . self::e($cell) . '</pre></td></tr>';
        }

        return $html . '</tbody></table>';
    }

    // ------------------------------------------------------------------
    // Toolbar chips
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $d data from DebugBar::collect()
     * @return list<array{panel: string, title: string, label: string, value: string, tone: string}>
     */
    public static function chips(array $d): array
    {
        $status = (int) $d['status'];
        $statusTone = $status >= 500 ? 'err' : ($status >= 400 ? 'warn' : 'ok');
        $request = $d['request'];
        $path = (string) parse_url((string) $request['uri'], PHP_URL_PATH);

        $queries = $d['queries'];
        $slow = false;
        foreach ($queries as $q) {
            if ($q['ms'] >= self::SLOW_QUERY_MS || $q['error'] !== null) {
                $slow = true;
            }
        }
        $duplicates = count($queries) !== count(array_unique(array_column($queries, 'sql')));

        $route = $d['route'];
        $handler = $route === null ? 'none' : self::cut(self::shortHandler((string) $route['handler']), 28);

        $sessionCount = $d['session']['active'] ? count($d['session']['data']) : 0;

        return [
            ['panel' => 'request', 'title' => 'Request', 'label' => self::cut($request['method'] . ' ' . $path, 30), 'value' => (string) $status, 'tone' => $statusTone],
            ['panel' => 'route', 'title' => 'Route', 'label' => 'Route', 'value' => $handler, 'tone' => $route === null ? 'warn' : 'ok'],
            ['panel' => 'time', 'title' => 'Timeline', 'label' => 'Time', 'value' => self::ms((float) $d['time_ms']), 'tone' => $d['time_ms'] > 1000 ? 'warn' : 'plain'],
            ['panel' => 'env', 'title' => 'Memory and PHP', 'label' => 'Memory', 'value' => self::bytes((int) $d['peak']), 'tone' => 'plain'],
            ['panel' => 'queries', 'title' => 'Database queries', 'label' => 'SQL', 'value' => count($queries) . ' · ' . self::ms((float) $d['db_ms']), 'tone' => ($slow || $duplicates) ? 'warn' : 'plain'],
            ['panel' => 'views', 'title' => 'Views', 'label' => 'Views', 'value' => (string) count($d['views']), 'tone' => 'plain'],
            ['panel' => 'files', 'title' => 'Included files', 'label' => 'Files', 'value' => (string) count($d['files']), 'tone' => 'plain'],
            ['panel' => 'session', 'title' => 'Session', 'label' => 'Session', 'value' => (string) $sessionCount, 'tone' => 'plain'],
            ['panel' => 'log', 'title' => 'Log (dbg)', 'label' => 'Log', 'value' => (string) count($d['logs']), 'tone' => count($d['logs']) > 0 ? 'pink' : 'plain'],
        ];
    }

    private static function shortHandler(string $handler): string
    {
        // "Core\Controllers\HomeCtrl::index()" -> "HomeCtrl::index"
        $handler = rtrim($handler, '()');
        $pos = strrpos($handler, '\\');

        return $pos === false ? $handler : substr($handler, $pos + 1);
    }

    // ------------------------------------------------------------------
    // Panels
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $d data from DebugBar::collect()
     * @return array<string, string> panel id => HTML
     */
    public static function panels(array $d): array
    {
        return [
            'request' => self::panelRequest($d),
            'route' => self::panelRoute($d),
            'time' => self::panelTime($d),
            'queries' => self::panelQueries($d),
            'views' => self::panelViews($d),
            'files' => self::panelFiles($d),
            'session' => self::panelSession($d),
            'log' => self::panelLog($d),
            'env' => self::panelEnv($d),
        ];
    }

    /** @param array<string, mixed> $d */
    private static function panelRequest(array $d): string
    {
        $r = $d['request'];
        $status = (int) $d['status'];

        return '<h3>Overview</h3>' . self::kv([
                'Method' => (string) $r['method'],
                'URL' => (string) $r['uri'],
                'Status' => $status . ' ' . ErrorHandler::statusText($status),
                'Client' => (string) $r['ip'],
            ])
            . '<h3>GET parameters</h3>' . self::kv($r['get'])
            . '<h3>POST parameters</h3>' . self::kv($r['post'])
            . '<h3>Uploaded files</h3>' . self::kv($r['files'])
            . '<h3>Request headers</h3>' . self::kv($r['headers'])
            . '<h3>Cookies</h3>' . self::kv($r['cookies'])
            . '<h3>Response headers</h3>' . self::kv($d['response_headers']);
    }

    /** @param array<string, mixed> $d */
    private static function panelRoute(array $d): string
    {
        $route = $d['route'];

        if ($route === null) {
            $html = '<h3>Matched route</h3><p class="empty">No route matched this request.</p>';
        } else {
            $html = '<h3>Matched route</h3>' . self::kv([
                    'Route' => $route['method'] . ' ' . $route['path'],
                    'Handler' => (string) $route['handler'],
                    'Middleware' => (string) $route['middleware'],
                ])
                . '<h3>Route parameters</h3>' . self::kv($route['params']);
        }

        $html .= '<h3>Registered routes</h3>';
        if ($d['routes'] === []) {
            return $html . '<p class="empty">No routes registered yet.</p>';
        }

        $html .= '<table class="tbl"><thead><tr><th>Method</th><th>Path</th><th>Handler</th></tr></thead><tbody>';
        foreach ($d['routes'] as $r) {
            $hit = $route !== null && $route['method'] === $r['method'] && $route['path'] === $r['path'];
            $html .= '<tr' . ($hit ? ' class="is-hit"' : '') . '><td><span class="pill dim">' . self::e((string) $r['method']) . '</span></td>'
                . '<td><code>' . self::e((string) $r['path']) . '</code></td>'
                . '<td><code>' . self::e((string) $r['handler']) . '</code></td></tr>';
        }

        return $html . '</tbody></table>';
    }

    /** @param array<string, mixed> $d */
    private static function panelTime(array $d): string
    {
        $total = max(0.001, (float) $d['time_ms']);
        $db = (float) $d['db_ms'];
        $views = (float) $d['view_ms'];
        $other = max(0.0, $total - $db - $views);

        $width = static fn(float $part): string => number_format(min(100.0, $part / $total * 100), 2, '.', '');

        $html = '<h3>Where the time went</h3>'
            . '<div class="bar" role="img" aria-label="Time split between database, views and other work">'
            . '<i class="seg-db" style="width:' . $width($db) . '%"></i>'
            . '<i class="seg-view" style="width:' . $width($views) . '%"></i>'
            . '<i class="seg-app" style="width:' . $width($other) . '%"></i></div>'
            . '<div class="legend">'
            . '<span><i class="dot seg-db"></i>Database <b>' . self::e(self::ms($db)) . '</b></span>'
            . '<span><i class="dot seg-view"></i>Views <b>' . self::e(self::ms($views)) . '</b></span>'
            . '<span><i class="dot seg-app"></i>Everything else <b>' . self::e(self::ms($other)) . '</b></span>'
            . '<span>Total <b>' . self::e(self::ms($total)) . '</b></span></div>';

        $html .= '<h3>Timeline</h3>';
        if ($d['marks'] === []) {
            return $html . '<p class="empty">No marks recorded.</p>';
        }

        $html .= '<table class="tbl"><thead><tr><th>Event</th><th class="num">At</th></tr></thead><tbody>';
        foreach ($d['marks'] as $mark) {
            $html .= '<tr><td>' . self::e((string) $mark['label']) . '</td><td class="num">' . self::e(self::ms((float) $mark['ms'])) . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    /** @param array<string, mixed> $d */
    private static function panelQueries(array $d): string
    {
        $queries = $d['queries'];
        if ($queries === []) {
            return '<p class="empty">No database queries ran during this request.</p>';
        }

        $counts = array_count_values(array_column($queries, 'sql'));
        $repeated = count(array_filter($counts, static fn(int $n): bool => $n > 1));

        $html = '<p><b>' . count($queries) . '</b> queries in <b>' . self::e(self::ms((float) $d['db_ms'])) . '</b>.';
        if ($repeated > 0) {
            $html .= ' <span class="pill warn">' . $repeated . ' repeated</span> Running the same query many times in one request is often a sign of an N+1 problem.';
        }
        $html .= '</p>';

        foreach ($queries as $i => $q) {
            $runs = $counts[$q['sql']];

            $html .= '<div class="query"><div class="query-head">'
                . '<span class="pill dim">#' . ($i + 1) . '</span>'
                . '<span class="pill ' . ($q['ms'] >= self::SLOW_QUERY_MS ? 'warn' : 'ok') . '">' . self::e(self::ms((float) $q['ms'])) . '</span>';

            if ($q['rows'] !== null) {
                $html .= '<span class="pill dim">' . (int) $q['rows'] . ' rows</span>';
            }
            if ($runs > 1) {
                $html .= '<span class="pill warn">runs ' . $runs . '×</span>';
            }
            if ($q['error'] !== null) {
                $html .= '<span class="pill err">failed</span>';
            }

            $html .= '<button type="button" class="copy" data-copy>Copy SQL</button></div><div class="query-body">'
                . '<pre class="sql">' . self::e((string) $q['sql']) . '</pre>';

            if ($q['error'] !== null) {
                $html .= '<p class="err-text">' . self::e((string) $q['error']) . '</p>';
            }

            if ($q['bindings'] !== []) {
                $html .= '<details><summary>' . count($q['bindings']) . ' bound values</summary>' . self::kv($q['bindings']) . '</details>';
            }

            $html .= '</div></div>';
        }

        return $html;
    }

    /** @param array<string, mixed> $d */
    private static function panelViews(array $d): string
    {
        if ($d['views'] === []) {
            return '<p class="empty">No views were rendered.</p>';
        }

        $html = '<table class="tbl"><thead><tr><th>#</th><th>Name</th><th>Kind</th><th class="num">Time</th><th>Variables</th></tr></thead><tbody>';
        foreach ($d['views'] as $i => $v) {
            $vars = [];
            foreach ($v['vars'] as $name => $preview) {
                $vars[] = '<code>' . self::e((string) $name) . '</code> <span class="muted">' . self::e((string) $preview) . '</span>';
            }

            $html .= '<tr><td>' . ($i + 1) . '</td><td><code>' . self::e((string) $v['name']) . '</code></td>'
                . '<td><span class="pill dim">' . self::e((string) $v['kind']) . '</span></td>'
                . '<td class="num">' . self::e(self::ms((float) $v['ms'])) . '</td>'
                . '<td>' . ($vars === [] ? '<span class="muted">none</span>' : implode('<br>', $vars)) . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    /** @param array<string, mixed> $d */
    private static function panelFiles(array $d): string
    {
        $groups = ['app' => [], 'framework' => [], 'vendor' => [], 'php' => []];
        $titles = ['app' => 'Your application', 'framework' => 'Kyaaaa framework', 'vendor' => 'Composer packages', 'php' => 'PHP and other'];

        foreach ($d['files'] as $file) {
            $groups[$file['kind']][] = $file;
        }

        $html = '<p><b>' . count($d['files']) . '</b> files were loaded for this request.</p>';
        foreach ($groups as $kind => $files) {
            if ($files === []) {
                continue;
            }

            $size = array_sum(array_column($files, 'size'));
            $html .= '<details' . ($kind === 'app' ? ' open' : '') . '><summary>' . self::e($titles[$kind])
                . ' <span class="pill dim">' . count($files) . '</span> <span class="muted">' . self::e(self::bytes((int) $size)) . '</span></summary>'
                . '<table class="tbl"><tbody>';

            foreach ($files as $file) {
                $html .= '<tr><td><code>' . self::e((string) $file['path']) . '</code></td><td class="num">' . self::e(self::bytes((int) $file['size'])) . '</td></tr>';
            }

            $html .= '</tbody></table></details>';
        }

        return $html;
    }

    /** @param array<string, mixed> $d */
    private static function panelSession(array $d): string
    {
        $s = $d['session'];
        if (!$s['active']) {
            return '<p class="empty">The session is not active.</p>';
        }

        return '<h3>Session</h3>' . self::kv(['Name' => (string) $s['name'], 'Session id' => (string) $s['id']])
            . '<h3>Data</h3>' . self::kv($s['data']);
    }

    /** @param array<string, mixed> $d */
    private static function panelLog(array $d): string
    {
        if ($d['logs'] === []) {
            return '<p class="empty">Nothing logged yet. Call <code>dbg($value, \'label\')</code> anywhere in your code and the value shows up here.</p>';
        }

        $html = '';
        foreach ($d['logs'] as $log) {
            $html .= '<div class="query"><div class="query-head">'
                . '<span class="pill pink">' . self::e($log['label'] !== '' ? (string) $log['label'] : 'dbg') . '</span>'
                . '<span class="pill dim">+' . self::e(self::ms((float) $log['ms'])) . '</span></div>'
                . '<div class="query-body"><pre>' . self::e((string) $log['dump']) . '</pre></div></div>';
        }

        return $html;
    }

    /** @param array<string, mixed> $d */
    private static function panelEnv(array $d): string
    {
        $limit = self::iniBytes((string) $d['memory_limit']);
        $peak = (int) $d['peak'];
        $used = $limit > 0 ? number_format(min(100.0, $peak / $limit * 100), 2, '.', '') : '0';

        $html = '<h3>Memory</h3><div class="bar" role="img" aria-label="Peak memory compared with the PHP memory limit">'
            . '<i class="seg-app" style="width:' . $used . '%"></i></div>'
            . '<div class="legend"><span>Now <b>' . self::e(self::bytes((int) $d['memory'])) . '</b></span>'
            . '<span>Peak <b>' . self::e(self::bytes($peak)) . '</b></span>'
            . '<span>Limit <b>' . self::e($limit < 0 ? 'unlimited' : self::bytes($limit)) . '</b></span></div>'
            . '<h3>PHP</h3>' . self::kv($d['env']);

        return $html;
    }
}
