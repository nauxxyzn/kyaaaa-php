<?php

declare(strict_types=1);

namespace Core\Conf\Kyaaaa;

use ErrorException;
use ParseError;
use Throwable;

/**
 * The development error page: an inspector with the stack on the left, the
 * source of the selected frame on the right and request / query / session
 * context underneath. Only used when APP_ENV=development.
 */
final class ErrorPage
{
    private const MAX_FRAMES = 40;

    public static function render(Throwable $e, int $status): string
    {
        $isHttp = $e instanceof HttpException && $status < 500;
        $data = DebugBar::collect();
        $panels = DebugView::panels($data);
        $frames = $isHttp ? [] : self::frames($e);

        $selected = 0;
        foreach ($frames as $i => $frame) {
            if ($frame['kind'] === 'app') {
                $selected = $i;
                break;
            }
        }

        $summary = $isHttp ? $status . ' ' . ErrorHandler::statusText($status) : $e::class . ': ' . DebugView::cut($e->getMessage(), 90);

        return '<!DOCTYPE html><html lang="en" class="ep"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
            . '<title>' . DebugView::e($summary) . '</title>'
            . '<style>' . DebugBar::asset('panels.css') . DebugBar::asset('error.css') . '</style></head>'
            . '<body class="ep-body hide-vendor">'
            . self::head($e, $status, $data, $isHttp)
            . ($isHttp ? '' : self::stack($frames, $selected))
            . self::tabs($panels, $data, $isHttp)
            . '<footer class="ep-foot">Kyaaaa-PHP development mode. Visitors never see this page in production, they only get a generic error. '
            . 'Press <kbd>Alt</kbd> + <kbd>K</kbd> to collapse the toolbar.</footer>'
            . '<script>' . DebugBar::asset('error.js') . '</script>'
            . DebugBar::toolbar($data)
            . '</body></html>';
    }

    // ------------------------------------------------------------------
    // Header
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     */
    private static function head(Throwable $e, int $status, array $data, bool $isHttp): string
    {
        $request = $data['request'];
        $tone = $status >= 500 ? 'err' : 'warn';

        $html = '<header class="ep-head"><div class="ep-meta">'
            . '<span class="pill pink">development</span>'
            . '<span class="pill ' . $tone . '">' . $status . '</span>'
            . (DebugView::isPublicClient((string) $request['ip'])
                ? '<span class="pill warn" title="APP_ENV=development exposes file paths, queries and request data. Never leave it on for a public site.">public client</span>'
                : '')
            . '<span>PHP ' . DebugView::e(PHP_VERSION) . '</span>'
            . '<span><code>' . DebugView::e(DebugView::cut($request['method'] . ' ' . $request['uri'], 90)) . '</code></span></div>';

        if ($isHttp) {
            $html .= '<h1 class="ep-title"><span class="code">' . $status . '</span> ' . DebugView::e(ErrorHandler::statusText($status)) . '</h1>';
        } else {
            $class = $e::class;
            $pos = strrpos($class, '\\');
            $namespace = $pos === false ? '' : substr($class, 0, $pos + 1);
            $short = $pos === false ? $class : substr($class, $pos + 1);

            $html .= '<h1 class="ep-title">' . ($namespace !== '' ? '<small>' . DebugView::e($namespace) . '</small>' : '')
                . DebugView::e($short);

            if ($e instanceof ErrorException) {
                $html .= ' <span class="pill warn">' . DebugView::e(self::severity($e->getSeverity())) . '</span>';
            }

            $html .= '</h1>';
        }

        $message = $e->getMessage();
        if ($message === '' && !$isHttp) {
            $message = ErrorHandler::statusText($status);
        }
        // "404 Not Found" followed by the message "Not Found" would just repeat itself.
        if ($message !== '' && !($isHttp && $message === ErrorHandler::statusText($status))) {
            $html .= '<p class="ep-msg">' . DebugView::e($message) . '</p>';
        }

        if (!$isHttp) {
            $html .= '<div class="ep-where"><code>' . DebugView::e(DebugView::shorten($e->getFile())) . ':' . $e->getLine() . '</code>'
                . '<button type="button" class="btn" data-copy-text="' . DebugView::e(self::plainText($e, $request)) . '">Copy error</button></div>';
        }

        $hint = self::hint($e, $status);
        if ($hint !== null) {
            $html .= '<div class="ep-hint"><b>Hint</b> ' . DebugView::e($hint) . '</div>';
        }

        $previous = $e->getPrevious();
        if ($previous !== null) {
            $html .= '<div class="ep-prev"><b>Caused by</b><ul>';
            for ($i = 0; $previous !== null && $i < 5; $i++, $previous = $previous->getPrevious()) {
                $html .= '<li><code>' . DebugView::e($previous::class) . '</code> ' . DebugView::e(DebugView::cut($previous->getMessage(), 200)) . '</li>';
            }
            $html .= '</ul></div>';
        }

        return $html . '</header>';
    }

    private static function hint(Throwable $e, int $status): ?string
    {
        $message = $e->getMessage();

        return match (true) {
            $e instanceof HttpException && $status === 404 => 'No route matches this URL. Compare it with the registered routes in the Routes tab below, and check the HTTP method.',
            $e instanceof HttpException && $status === 405 => 'This URL exists, but not for this HTTP method. The Routes tab shows what is registered.',
            $e instanceof HttpException && $status === 419 => 'The CSRF token is missing or expired. Put csrf()->field() inside the form, or send the X-CSRF-Token header for AJAX calls.',
            $e instanceof ParseError => 'PHP could not parse this file. The real mistake is usually on the highlighted line or the line above it, often a missing ; or bracket.',
            str_contains($message, 'Database connection failed') => 'Check the DB_* values in .env and make sure the database server is running.',
            str_contains($message, 'View not found') => 'Views live in core/Views and end in .kyaaaa.php, so view("home") loads core/Views/home.kyaaaa.php.',
            str_contains($message, 'Controller class') => 'Check the namespace of the controller and run composer dump-autoload.',
            str_contains($message, 'Invalid column expression') => 'select() only takes plain columns, aliases and simple aggregates. Use selectRaw() for anything more complex (never with user input).',
            str_contains($message, 'CSRF protection needs an active session') => 'The session did not start. Check App::session() and that nothing printed output before it.',
            default => null,
        };
    }

    private static function severity(int $severity): string
    {
        return match ($severity) {
            E_WARNING, E_USER_WARNING => 'Warning',
            E_NOTICE, E_USER_NOTICE => 'Notice',
            E_DEPRECATED, E_USER_DEPRECATED => 'Deprecated',
            E_PARSE => 'Parse error',
            E_RECOVERABLE_ERROR => 'Recoverable error',
            E_ERROR, E_USER_ERROR, E_CORE_ERROR, E_COMPILE_ERROR => 'Fatal error',
            default => 'Error',
        };
    }

    /**
     * Plain-text report for the "Copy error" button.
     *
     * @param array<string, mixed> $request
     */
    private static function plainText(Throwable $e, array $request): string
    {
        $text = $e::class . ': ' . $e->getMessage() . "\n  at " . DebugView::shorten($e->getFile()) . ':' . $e->getLine()
            . "\n  request: " . $request['method'] . ' ' . $request['uri'] . "\n  PHP " . PHP_VERSION;

        foreach (array_slice($e->getTrace(), 0, 12) as $i => $frame) {
            if (!isset($frame['file'])) {
                continue;
            }
            $text .= "\n  #" . ($i + 1) . ' ' . self::call($frame) . ' (' . DebugView::shorten($frame['file']) . ':' . ($frame['line'] ?? '?') . ')';
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $frame
     */
    private static function call(array $frame): string
    {
        return ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '') . '()';
    }

    // ------------------------------------------------------------------
    // Stack and source
    // ------------------------------------------------------------------

    /**
     * @return list<array{call: string, file: string, short: string, line: int, kind: string}>
     */
    private static function frames(Throwable $e): array
    {
        $frames = [[
            'call' => 'Thrown here',
            'file' => $e->getFile(),
            'short' => DebugView::shorten($e->getFile()),
            'line' => $e->getLine(),
            'kind' => DebugView::classify($e->getFile()),
        ]];

        foreach ($e->getTrace() as $frame) {
            if (!isset($frame['file']) || count($frames) >= self::MAX_FRAMES) {
                continue;
            }

            $frames[] = [
                'call' => self::call($frame),
                'file' => $frame['file'],
                'short' => DebugView::shorten($frame['file']),
                'line' => (int) ($frame['line'] ?? 0),
                'kind' => DebugView::classify($frame['file']),
            ];
        }

        return $frames;
    }

    /**
     * @param list<array{call: string, file: string, short: string, line: int, kind: string}> $frames
     */
    private static function stack(array $frames, int $selected): string
    {
        $html = '<main class="ep-main"><nav class="ep-stack" aria-label="Stack trace">'
            . '<label class="ep-toggle"><input type="checkbox" id="ep-hide" checked> Hide framework and vendor frames</label><ol>';

        foreach ($frames as $i => $frame) {
            $on = $i === $selected;
            $html .= '<li data-frame="' . $i . '" data-kind="' . DebugView::e($frame['kind']) . '"' . ($on ? ' class="is-selected"' : '') . '>'
                . '<button type="button" class="frame-btn"' . ($on ? ' aria-current="true"' : '') . '>'
                . '<span class="fn">' . DebugView::e($frame['call']) . '</span>'
                . '<span class="loc">' . DebugView::e($frame['short']) . ':' . $frame['line'] . '</span></button></li>';
        }

        $html .= '</ol></nav><section class="ep-code" aria-label="Source code">';

        foreach ($frames as $i => $frame) {
            $html .= '<div class="frame" data-frame="' . $i . '"' . ($i === $selected ? '' : ' hidden') . '>'
                . '<div class="frame-head"><code>' . DebugView::e($frame['short']) . ':' . $frame['line'] . '</code>'
                . '<span class="pill dim">' . DebugView::e($frame['kind']) . '</span>'
                . '<button type="button" class="btn small" data-copy-text="' . DebugView::e($frame['file'] . ':' . $frame['line']) . '">Copy path</button></div>'
                . self::source($frame['file'], $frame['line']) . '</div>';
        }

        return $html . '</section></main>';
    }

    private static function source(string $file, int $line, int $context = 8): string
    {
        if (!is_file($file) || !is_readable($file) || filesize($file) > 2097152) {
            return '<p class="empty">The source of this file is not available.</p>';
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false || $lines === []) {
            return '<p class="empty">The source of this file is not available.</p>';
        }

        $start = max(1, $line - $context);
        $end = min(count($lines), $line + $context);
        if ($start > $end) {
            return '<p class="empty">The source of this file is not available.</p>';
        }

        $html = '<div class="src">';
        for ($i = $start; $i <= $end; $i++) {
            $text = str_replace("\t", '    ', DebugView::cut($lines[$i - 1], 400));
            $html .= '<div class="ln' . ($i === $line ? ' hit' : '') . '"><i>' . $i . '</i><code>' . DebugView::e($text) . '</code></div>';
        }

        return $html . '</div>';
    }

    // ------------------------------------------------------------------
    // Context tabs
    // ------------------------------------------------------------------

    /**
     * @param array<string, string> $panels
     * @param array<string, mixed>  $data
     */
    private static function tabs(array $panels, array $data, bool $isHttp): string
    {
        $labels = [
            'route' => 'Routes',
            'request' => 'Request',
            'queries' => 'Queries (' . count($data['queries']) . ')',
            'session' => 'Session',
            'files' => 'Files (' . count($data['files']) . ')',
            'env' => 'Environment',
        ];

        $default = $isHttp ? 'route' : 'request';
        foreach ($data['queries'] as $query) {
            if ($query['error'] !== null) {
                $default = 'queries';
                break;
            }
        }

        $buttons = '';
        $bodies = '';
        foreach ($labels as $id => $label) {
            $on = $id === $default;
            $buttons .= '<button type="button" class="tab" role="tab" data-tab="' . $id . '" aria-selected="' . ($on ? 'true' : 'false') . '">'
                . DebugView::e($label) . '</button>';
            $bodies .= '<div class="tabpanel" role="tabpanel" data-tabpanel="' . $id . '"' . ($on ? '' : ' hidden') . '>' . $panels[$id] . '</div>';
        }

        return '<section class="ep-tabs"><div class="tablist" role="tablist">' . $buttons . '</div><div class="kd">' . $bodies . '</div></section>';
    }
}
