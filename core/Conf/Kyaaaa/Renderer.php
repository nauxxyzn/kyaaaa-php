<?php

declare(strict_types=1);

namespace Core\Conf\Kyaaaa;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

class Renderer
{
    /**
     * @param array<string, mixed> $data variables made available to the view
     */
    public function __construct(protected string $view, protected array $data = [])
    {
        // Fail early on invalid names (path traversal, odd characters).
        self::resolve($view);
    }

    public function getView(): string
    {
        return $this->view;
    }

    /** @return array<string, mixed> */
    public function getData(): array
    {
        return $this->data;
    }

    public static function viewsPath(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'Views';
    }

    /**
     * Turn a view name ("home", "admin/dashboard") into a file path. Only
     * [A-Za-z0-9_-/] is accepted and the result must stay inside core/Views,
     * so a user controlled view name can never include arbitrary files.
     */
    public static function resolve(string $view): string
    {
        if (
            $view === ''
            || preg_match('~^[A-Za-z0-9_/-]+$~D', $view) !== 1
            || str_starts_with($view, '/')
            || str_contains($view, '//')
        ) {
            throw new InvalidArgumentException('Invalid view name.');
        }

        $base = realpath(self::viewsPath());
        $file = realpath($base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $view) . '.kyaaaa.php');

        if ($base === false || $file === false || !str_starts_with($file, $base . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('View not found.');
        }

        return $file;
    }

    public function render(): string
    {
        $started = microtime(true);
        $content = self::capture(self::resolve($this->view), $this->data);
        DebugBar::view($this->view, 'view', (microtime(true) - $started) * 1000, $this->data);

        $layout = $this->findLayout();
        if ($layout === null) {
            return $content;
        }

        $started = microtime(true);
        $html = self::capture($layout, ['content' => $content] + $this->data);
        DebugBar::view('layout', 'layout', (microtime(true) - $started) * 1000, $this->data);

        return $html;
    }

    public function send(): void
    {
        echo $this->render();
    }

    private function findLayout(): ?string
    {
        $dir = dirname($this->view);
        $candidates = $dir === '.' ? ['layout'] : [$dir . '/layout', 'layout'];

        foreach ($candidates as $candidate) {
            try {
                return self::resolve($candidate);
            } catch (RuntimeException) {
                continue;
            }
        }

        return null;
    }

    /**
     * Include a template in an isolated scope. EXTR_SKIP means view data can
     * never overwrite the internal variables.
     *
     * @param array<string, mixed> $__data
     */
    private static function capture(string $__file, array $__data): string
    {
        unset($__data['this'], $__data['__file'], $__data['__data']);
        extract($__data, EXTR_SKIP);

        ob_start();
        try {
            include $__file;
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }
}
