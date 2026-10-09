<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Route table with declarative guards. Every guard is enforced HERE on the server:
 *   auth    => a valid, active session is required
 *   perm    => the signed-in staff member must hold this RBAC permission
 *   feature => the module must be enabled in feature_flags (and its parents)
 * CSRF is enforced for every state-changing request that carries a session.
 */
final class Router
{
    /** @var array<int, array{0:string,1:string,2:array,3:array}> */
    private array $routes = [];

    public function add(string $method, string $pattern, array $handler, array $options = []): void
    {
        $this->routes[] = [$method, $pattern, $handler, $options];
    }

    public function dispatch(Request $request): void
    {
        $allowedMethodsForPath = [];
        foreach ($this->routes as [$method, $pattern, $handler, $options]) {
            $regex = '#^' . preg_replace('#\{([a-z_]+)\}#i', '(?P<$1>[^/]+)', $pattern) . '$#';
            if (!preg_match($regex, $request->path, $matches)) {
                continue;
            }
            $allowedMethodsForPath[] = $method;
            if ($method !== $request->method && !($method === 'GET' && $request->method === 'HEAD')) {
                continue;
            }
            $request->params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

            try {
                $this->guard($request, $options);
                [$class, $action] = $handler;
                $controller = new $class();
                $result = $controller->{$action}($request);
                if (is_array($result)) {
                    Response::json($result);
                }
            } catch (ApiException $e) {
                Response::error($e);
            }
            return;
        }

        if ($allowedMethodsForPath !== []) {
            Response::json(['error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.']], 405);
            return;
        }
        Response::json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Endpoint not found.']], 404);
    }

    private function guard(Request $request, array $options): void
    {
        $needsAuth = !empty($options['auth']) || !empty($options['perm']);
        if ($needsAuth) {
            $request->user = Auth::requireUser($request);
            if (!in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
                Auth::verifyCsrf($request);
            }
        }
        if (!empty($options['staff']) || !empty($options['perm'])) {
            if (($request->user['account_type'] ?? '') !== 'staff') {
                throw new ApiException(403, 'STAFF_ONLY', 'This area is restricted to staff accounts.');
            }
        }
        if (!empty($options['perm'])) {
            Rbac::require((int) $request->user['id'], (string) $options['perm']);
        }
        if (!empty($options['feature'])) {
            Features::require((string) $options['feature']);
        }
    }
}
