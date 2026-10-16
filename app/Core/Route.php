<?php

namespace MedCore\Core;

/**
 * A single route definition. {placeholders} capture URI segments.
 */
final class Route
{
    public string $method;
    public string $uri;
    public array $action;
    public array $middleware = [];

    public function __construct(string $method, string $uri, array $action)
    {
        $this->method = $method;
        $this->uri = $uri === '/' ? '/' : rtrim($uri, '/');
        $this->action = $action;
    }

    public function middleware(string ...$middleware): self
    {
        $this->middleware = array_merge($this->middleware, $middleware);
        return $this;
    }

    /** Returns captured params if the URI matches, false otherwise. */
    public function matches(string $path): array|false
    {
        $pattern = preg_replace('#\{([a-zA-Z_]+)\}#', '([^/]+)', $this->uri);
        if (!preg_match('#^' . $pattern . '$#', $path, $matches)) {
            return false;
        }
        preg_match_all('#\{([a-zA-Z_]+)\}#', $this->uri, $names);
        $params = [];
        foreach ($names[1] as $i => $name) {
            $params[$name] = $matches[$i + 1] ?? null;
        }
        return $params;
    }
}
