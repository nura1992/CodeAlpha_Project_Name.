<?php
/**
 * Router — method + pattern matching with named parameters and middleware.
 *
 * Patterns use {name} placeholders which match a single path segment.
 * Handlers are 'Class@method' strings (resolved relative to the Controllers
 * namespace) or plain callables. Middleware are class names, optionally with
 * a constructor argument as 'Class:argument'.
 *
 * Groups share a path prefix and a middleware stack:
 *
 *   $router->group('/api', [AuthMiddleware::class], function (RouteGroup $api) {
 *       $api->get('/users/{id}', 'UserController@show');
 *   });
 */

declare(strict_types=1);

namespace CodeAlpha\Core;

final class Router
{
    /** @var array<int, array{method:string, regex:string, handler:mixed, middleware:array, name:string}> */
    private array $routes = [];

    public function add(string $method, string $pattern, $handler, array $middleware = [], string $name = ''): void
    {
        $this->routes[] = [
            'method'     => strtoupper($method),
            'regex'      => $this->compile($pattern),
            'handler'    => $handler,
            'middleware' => $middleware,
            'name'       => $name !== '' ? $name : strtoupper($method) . ' ' . $pattern,
        ];
    }

    public function get(string $pattern, $handler, array $middleware = [], string $name = ''): void
    {
        $this->add('GET', $pattern, $handler, $middleware, $name);
    }

    public function post(string $pattern, $handler, array $middleware = [], string $name = ''): void
    {
        $this->add('POST', $pattern, $handler, $middleware, $name);
    }

    public function put(string $pattern, $handler, array $middleware = [], string $name = ''): void
    {
        $this->add('PUT', $pattern, $handler, $middleware, $name);
    }

    public function patch(string $pattern, $handler, array $middleware = [], string $name = ''): void
    {
        $this->add('PATCH', $pattern, $handler, $middleware, $name);
    }

    public function delete(string $pattern, $handler, array $middleware = [], string $name = ''): void
    {
        $this->add('DELETE', $pattern, $handler, $middleware, $name);
    }

    public function any(string $pattern, $handler, array $middleware = [], string $name = ''): void
    {
        $this->add('*', $pattern, $handler, $middleware, $name);
    }

    public function group(string $prefix, array $middleware, callable $callback): void
    {
        $group = new RouteGroup($prefix, $middleware);
        $callback($group);
        foreach ($group->collect() as $route) {
            $this->routes[] = $route;
        }
    }

    private function compile(string $pattern): string
    {
        $pattern = '/' . trim($pattern, '/');
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?<$1>[^/]+)', $pattern);
        return '#^' . $regex . '$#u';
    }

    /**
     * Dispatch the request. Handlers terminate via Response helpers.
     */
    public function dispatch(Request $request): void
    {
        $path = $request->path();
        $method = $request->method();
        $methodMismatch = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            if ($route['method'] !== '*' && $route['method'] !== $method) {
                $methodMismatch = true;
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = rawurldecode($value);
                }
            }

            $handler = $this->resolveHandler($route['handler'], $params);
            $next = static fn () => $handler();
            foreach (array_reverse($route['middleware']) as $middleware) {
                $runner = $this->resolveMiddleware($middleware);
                $next = static fn () => $runner($next);
            }
            $next();
            return;
        }

        if ($methodMismatch) {
            Response::error('Method not allowed', 405);
        }
        Response::notFound('The requested resource was not found.');
    }

    private function resolveHandler($handler, array $params): callable
    {
        if ($handler instanceof \Closure) {
            return static fn () => $handler($params);
        }
        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);
            $fqcn = str_starts_with($class, 'CodeAlpha\\') ? $class : 'CodeAlpha\\Http\\Controllers\\' . $class;
            return static function () use ($fqcn, $method, $params) {
                $controller = new $fqcn();
                return $controller->{$method}($params);
            };
        }
        throw new \RuntimeException('Invalid route handler: ' . print_r($handler, true));
    }

    private function resolveMiddleware($middleware): callable
    {
        if (is_string($middleware) && str_contains($middleware, ':')) {
            [$class, $argument] = explode(':', $middleware, 2);
            $fqcn = str_starts_with($class, 'CodeAlpha\\') ? $class : 'CodeAlpha\\Http\\Middleware\\' . $class;
            return static fn (callable $next) => (new $fqcn($argument))->handle($next);
        }
        if (is_string($middleware)) {
            $fqcn = str_starts_with($middleware, 'CodeAlpha\\') ? $middleware : 'CodeAlpha\\Http\\Middleware\\' . $middleware;
            return static fn (callable $next) => (new $fqcn())->handle($next);
        }
        if ($middleware instanceof \Closure) {
            return static fn (callable $next) => $middleware($next);
        }
        throw new \RuntimeException('Invalid middleware');
    }
}

/**
 * Collects routes that share a prefix and middleware stack.
 */
final class RouteGroup
{
    public string $prefix;
    public array $middleware;
    /** @var array<int, array{method:string, pattern:string, handler:mixed, middleware:array, name:string}> */
    private array $routes = [];

    public function __construct(string $prefix, array $middleware = [])
    {
        $this->prefix = '/' . trim($prefix, '/');
        $this->middleware = $middleware;
    }

    public function middleware(array $middleware): self
    {
        $this->middleware = array_merge($this->middleware, $middleware);
        return $this;
    }

    private function add(string $method, string $pattern, $handler, array $middleware = [], string $name = ''): void
    {
        $this->routes[] = [
            'method'     => strtoupper($method),
            'pattern'    => '/' . trim($pattern, '/'),
            'handler'    => $handler,
            'middleware' => $middleware,
            'name'       => $name,
        ];
    }

    public function get(string $p, $h, array $m = [], string $n = ''): void { $this->add('GET', $p, $h, $m, $n); }
    public function post(string $p, $h, array $m = [], string $n = ''): void { $this->add('POST', $p, $h, $m, $n); }
    public function put(string $p, $h, array $m = [], string $n = ''): void { $this->add('PUT', $p, $h, $m, $n); }
    public function patch(string $p, $h, array $m = [], string $n = ''): void { $this->add('PATCH', $p, $h, $m, $n); }
    public function delete(string $p, $h, array $m = [], string $n = ''): void { $this->add('DELETE', $p, $h, $m, $n); }

    public function group(string $prefix, array $middleware, callable $callback): void
    {
        $nested = new RouteGroup($this->prefix . '/' . trim($prefix, '/'), array_merge($this->middleware, $middleware));
        $callback($nested);
        foreach ($nested->collect() as $route) {
            $this->routes[] = [
                'method'     => $route['method'],
                'pattern'    => substr($route['pattern'], strlen($nested->prefix)),
                'handler'    => $route['handler'],
                'middleware' => $route['middleware'],
                'name'       => $route['name'],
            ];
        }
    }

    /**
     * Flatten collected routes into absolute router route definitions.
     *
     * @return array<int, array{method:string, regex:string, handler:mixed, middleware:array, name:string}>
     */
    public function collect(): array
    {
        $out = [];
        foreach ($this->routes as $route) {
            $pattern = $this->prefix . ($route['pattern'] === '/' ? '' : $route['pattern']);
            $regex = '#^' . preg_replace(
                '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
                '(?<$1>[^/]+)',
                '/' . trim($pattern, '/')
            ) . '$#u';
            $out[] = [
                'method'     => $route['method'],
                'regex'      => $regex,
                'handler'    => $route['handler'],
                'middleware' => array_merge($this->middleware, $route['middleware']),
                'name'       => $route['name'] !== '' ? $route['name'] : $route['method'] . ' ' . $pattern,
            ];
        }
        return $out;
    }
}
