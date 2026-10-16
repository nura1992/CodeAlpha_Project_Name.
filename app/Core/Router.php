<?php

namespace MedCore\Core;

use MedCore\Middleware\GuestOnly;
use MedCore\Middleware\RequireLogin;
use MedCore\Middleware\RequirePermission;
use MedCore\Middleware\VerifyCsrf;

/**
 * Small front-controller router with route parameters and middleware.
 *
 * $router->get('/patients/{id}', [PatientController::class, 'show'])
 *        ->middleware('auth', 'perm:patients.view');
 */
final class Router
{
    private array $routes = [];
    private array $middlewareAliases = [];

    public function __construct()
    {
        $this->middlewareAliases = [
            'auth'  => fn() => RequireLogin::handle(),
            'guest' => fn() => GuestOnly::handle(),
            'csrf'  => fn() => VerifyCsrf::handle(),
        ];
    }

    public function get(string $uri, array $action): Route
    {
        return $this->add('GET', $uri, $action);
    }

    public function post(string $uri, array $action): Route
    {
        return $this->add('POST', $uri, $action);
    }

    private function add(string $method, string $uri, array $action): Route
    {
        $route = new Route($method, $uri, $action);
        $this->routes[] = $route;
        return $route;
    }

    public function dispatch(Request $request): void
    {
        $method = $request->method();
        $path = $request->path();

        foreach ($this->routes as $route) {
            if ($route->method !== $method) {
                continue;
            }
            $params = $route->matches($path);
            if ($params === false) {
                continue;
            }

            // Run middleware first.
            foreach ($route->middleware as $mw) {
                $this->runMiddleware($mw, $request);
            }

            [$class, $action] = $route->action;
            $controller = new $class();
            $controller->{$action}($request, ...array_values($params));
            return;
        }

        if ($request->wantsJson()) {
            Response::json(['error' => 'Not found'], 404);
            return;
        }
        Response::abort(404);
    }

    private function runMiddleware(string $mw, Request $request): void
    {
        if (str_starts_with($mw, 'perm:')) {
            RequirePermission::handle(substr($mw, 5), $request);
            return;
        }
        $handler = $this->middlewareAliases[$mw] ?? null;
        if ($handler === null) {
            throw new \RuntimeException("Unknown middleware: {$mw}");
        }
        $handler($request);
    }
}
