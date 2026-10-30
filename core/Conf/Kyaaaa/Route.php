<?php

declare(strict_types=1);

namespace Core\Conf\Kyaaaa;

use LogicException;

class Route
{
    private string $path;

    /** @var mixed controller callable, [Class::class, 'method'] or "Class#method" */
    private mixed $callable;

    /** @var list<mixed> */
    private array $middleware = [];

    /** @var array<string, string> custom regex per parameter (see with()) */
    private array $constraints = [];

    /** @var list<string> */
    private array $paramNames = [];

    private ?string $regex = null;

    /** @var list<string> */
    private array $matches = [];

    public function __construct(string $path, mixed $callable)
    {
        $this->path = trim($path, '/');
        $this->callable = $callable;
    }

    /**
     * Side-effect free check: does this route match the URL path?
     */
    public function test(?string $url): bool
    {
        return preg_match($this->compile(), trim((string) $url, '/')) === 1;
    }

    /**
     * Match the URL path and remember the route parameters.
     */
    public function match(?string $url): bool
    {
        if (preg_match($this->compile(), trim((string) $url, '/'), $found) !== 1) {
            return false;
        }

        array_shift($found);
        $this->matches = array_values($found);

        // Route parameters are also exposed through $_GET (and win over a
        // query-string parameter with the same name).
        foreach ($this->paramNames as $i => $name) {
            if (isset($this->matches[$i])) {
                $_GET[$name] = $this->matches[$i];
            }
        }

        return true;
    }

    /**
     * Run the middleware (in order), then the route handler. A middleware stops
     * the request by throwing (e.g. HttpException) or by returning false.
     */
    public function call(): mixed
    {
        foreach ($this->middleware as $middleware) {
            if ($this->invoke($middleware) === false) {
                throw new HttpException(403, 'Forbidden');
            }
        }

        return $this->invoke($this->callable);
    }

    /**
     * Restrict a parameter with a regular expression, e.g. ->with('id', '\d+').
     */
    public function with(string $param, string $regex): static
    {
        // Inner capture groups would shift the parameter positions.
        $this->constraints[$param] = (string) preg_replace('/(?<!\\\\)\((?!\?)/', '(?:', $regex);
        $this->regex = null;

        return $this;
    }

    /** @return list<string> */
    public function getmatch(): array
    {
        return $this->matches;
    }

    /**
     * Build the path for this route, e.g. getUrl(['id' => 5]) -> "items/5".
     *
     * @param array<string, scalar> $params
     */
    public function getUrl(array $params = []): string
    {
        return (string) preg_replace_callback(
            '/:(\w+)/',
            static fn(array $m): string => isset($params[$m[1]]) ? rawurlencode((string) $params[$m[1]]) : $m[0],
            $this->path
        );
    }

    public function middleware(mixed $middleware): static
    {
        $this->middleware[] = $middleware;

        return $this;
    }

    /**
     * Require a valid CSRF token for this route (POST field or X-CSRF-Token header).
     */
    public function csrf(): static
    {
        return $this->middleware(static function (): void {
            if (!\csrf()->isValidRequest()) {
                throw new HttpException(419, 'Page Expired');
            }
        });
    }

    /**
     * Summary of the route for the development tools.
     *
     * @return array{method: string, path: string, handler: string, params: array<string, string>, middleware: int}
     */
    public function describe(string $method = ''): array
    {
        $params = [];
        foreach ($this->paramNames as $i => $name) {
            if (isset($this->matches[$i])) {
                $params[$name] = $this->matches[$i];
            }
        }

        return [
            'method' => $method,
            'path' => '/' . $this->path,
            'handler' => $this->handlerLabel(),
            'params' => $params,
            'middleware' => count($this->middleware),
        ];
    }

    private function handlerLabel(): string
    {
        $handler = $this->callable;

        if (is_string($handler)) {
            return $handler;
        }
        if (is_array($handler) && count($handler) === 2) {
            $class = is_object($handler[0]) ? $handler[0]::class : (string) $handler[0];

            return $class . '::' . (string) $handler[1] . '()';
        }
        if ($handler instanceof \Closure) {
            return 'Closure';
        }

        return get_debug_type($handler);
    }

    private function compile(): string
    {
        if ($this->regex !== null) {
            return $this->regex;
        }

        $this->paramNames = [];
        $pieces = preg_split('/(:\w+)/', $this->path, -1, PREG_SPLIT_DELIM_CAPTURE);
        $regex = '';

        foreach ($pieces === false ? [] : $pieces as $piece) {
            if ($piece !== '' && $piece[0] === ':') {
                $name = substr($piece, 1);
                $this->paramNames[] = $name;
                $regex .= '(' . ($this->constraints[$name] ?? '[^/]+') . ')';
            } else {
                $regex .= preg_quote($piece, '#');
            }
        }

        return $this->regex = '#^' . $regex . '$#iD';
    }

    private function invoke(mixed $callable): mixed
    {
        if (is_string($callable) && str_contains($callable, '#')) {
            $callable = explode('#', $callable, 2);
        }

        if (is_array($callable)) {
            if (count($callable) !== 2) {
                throw new LogicException('A route handler must be [Class::class, "method"].');
            }

            [$class, $method] = array_values($callable);

            if (is_string($class)) {
                if (!class_exists($class)) {
                    throw new LogicException("Controller class {$class} does not exist.");
                }
                $instance = new $class();
            } elseif (is_object($class)) {
                $instance = $class;
            } else {
                throw new LogicException('A route handler must be [Class::class, "method"].');
            }

            if (!is_string($method) || !is_callable([$instance, $method])) {
                throw new LogicException('Controller method is missing or not callable.');
            }

            return $instance->{$method}(...$this->matches);
        }

        if (is_callable($callable)) {
            return $callable(...$this->matches);
        }

        throw new LogicException('Route handler is not callable.');
    }
}
