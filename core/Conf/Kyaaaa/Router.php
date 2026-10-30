<?php

declare(strict_types=1);

namespace Core\Conf\Kyaaaa;

use InvalidArgumentException;

class Router
{
    /** @var array<string, list<Route>> */
    private array $routes = [];

    /** @var array<string, Route> */
    private array $nameRoute = [];

    private string $baseRoute = '';
    private string $path;
    private string $method;

    public function __construct()
    {
        require_once __DIR__ . '/Common.php';
        Bootstrap::boot();

        $this->path = self::requestPath();
        $this->method = self::requestMethod();
    }

    public function get(string $path, mixed $callable, ?string $name = null): Route
    {
        return $this->add($path, $callable, 'GET', $name);
    }

    public function post(string $path, mixed $callable, ?string $name = null): Route
    {
        return $this->add($path, $callable, 'POST', $name);
    }

    public function put(string $path, mixed $callable, ?string $name = null): Route
    {
        return $this->add($path, $callable, 'PUT', $name);
    }

    public function patch(string $path, mixed $callable, ?string $name = null): Route
    {
        return $this->add($path, $callable, 'PATCH', $name);
    }

    public function delete(string $path, mixed $callable, ?string $name = null): Route
    {
        return $this->add($path, $callable, 'DELETE', $name);
    }

    public function group(string $base_route, callable $callable): void
    {
        $current = $this->baseRoute;
        $this->baseRoute .= $base_route;

        try {
            $callable($this);
        } finally {
            $this->baseRoute = $current;
        }
    }

    /**
     * Path of a named route, e.g. url('item', ['id' => 5]).
     *
     * @param array<string, scalar> $params
     */
    public function url(string $name, array $params = []): string
    {
        if (!isset($this->nameRoute[$name])) {
            throw new InvalidArgumentException("No route named '{$name}'.");
        }

        return $this->nameRoute[$name]->getUrl($params);
    }

    /**
     * Dispatch the request. Unknown paths give a 404, known paths with another
     * method a 405, and exceptions go to the ErrorHandler (nothing is echoed
     * from here).
     */
    public function run(): mixed
    {
        if (DebugBar::enabled()) {
            DebugBar::routes($this->describeRoutes());
        }

        foreach ($this->routes[$this->method] ?? [] as $route) {
            if ($route->match($this->path)) {
                if (DebugBar::enabled()) {
                    DebugBar::route($route->describe($this->method));
                    DebugBar::mark('Route matched');
                }

                return $route->call();
            }
        }

        $allowed = [];
        foreach ($this->routes as $method => $routes) {
            foreach ($routes as $route) {
                if ($route->test($this->path)) {
                    $allowed[] = $method;
                    break;
                }
            }
        }

        if ($allowed !== []) {
            if (in_array('GET', $allowed, true)) {
                $allowed[] = 'HEAD';
            }

            throw new HttpException(405, 'Method Not Allowed', ['Allow' => implode(', ', array_unique($allowed))]);
        }

        throw new HttpException(404, 'Not Found');
    }

    /**
     * @return list<array{method: string, path: string, handler: string}>
     */
    private function describeRoutes(): array
    {
        $list = [];
        foreach ($this->routes as $method => $routes) {
            foreach ($routes as $route) {
                $info = $route->describe($method);
                $list[] = ['method' => $method, 'path' => $info['path'], 'handler' => $info['handler']];
            }
        }

        return $list;
    }

    private function add(string $path, mixed $callable, string $method, ?string $name = null): Route
    {
        $route = new Route($this->baseRoute . '/' . trim($path, '/'), $callable);
        $this->routes[$method][] = $route;

        if ($name === null && is_string($callable)) {
            $name = $callable;
        }
        if ($name !== null) {
            $this->nameRoute[$name] = $route;
        }

        return $route;
    }

    /**
     * Request path without query string, duplicate slashes and the directory
     * the application is installed in.
     */
    private static function requestPath(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

        foreach (['?', '#'] as $delimiter) {
            $pos = strpos($uri, $delimiter);
            if ($pos !== false) {
                $uri = substr($uri, 0, $pos);
            }
        }

        $path = (string) preg_replace('~/{2,}~', '/', $uri);

        $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
        if ($base !== '' && $base !== '.' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $path = substr($path, strlen($base));
        }

        return $path === '' ? '/' : $path;
    }

    /**
     * HTML forms can only send GET/POST: a POST body field "_method" may carry
     * PUT, PATCH or DELETE. Query strings and headers are never used for this.
     */
    private static function requestMethod(): string
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        if ($method === 'POST' && isset($_POST['_method']) && is_string($_POST['_method'])) {
            $override = strtoupper($_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }

        return $method === 'HEAD' ? 'GET' : $method;
    }
}
