<?php

namespace MedCore\Core;

/**
 * HTTP request abstraction built from PHP superglobals.
 */
final class Request
{
    public string $method;
    public string $path;
    public array $query;
    public array $post;
    public array $server;
    public array $cookies;
    private ?array $json = null;

    public function __construct()
    {
        $this->method  = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri           = $_SERVER['REQUEST_URI'] ?? '/';
        $path          = parse_url($uri, PHP_URL_PATH) ?: '/';
        $this->path    = rtrim($path, '/') === '' ? '/' : rtrim($path, '/');
        $this->query   = $_GET;
        $this->post    = $_POST;
        $this->server  = $_SERVER;
        $this->cookies = $_COOKIE;
    }

    public static function capture(): self
    {
        return new self();
    }

    public function method(): string
    {
        // Support method spoofing from HTML forms: <input name="_method" value="PUT">
        if ($this->method === 'POST' && isset($this->post['_method'])) {
            $spoof = strtoupper((string) $this->post['_method']);
            if (in_array($spoof, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $spoof;
            }
        }
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /** Input from query string, form body or JSON body. JSON wins. */
    public function input(string $key, $default = null)
    {
        $json = $this->json();
        if (is_array($json) && array_key_exists($key, $json)) {
            return $json[$key];
        }
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function all(): array
    {
        $json = $this->json();
        return array_merge($this->query, $this->post, is_array($json) ? $json : []);
    }

    public function json(): ?array
    {
        if ($this->json !== null) {
            return $this->json;
        }
        $this->json = [];
        $contentType = (string) ($this->server['CONTENT_TYPE'] ?? '');
        if (str_contains($contentType, 'application/json')) {
            // The php-wasm preview bridge passes raw bodies through a global
            // because php://input is unavailable on that SAPI.
            $raw = $GLOBALS['__RAW_BODY'] ?? file_get_contents('php://input');
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                $this->json = is_array($decoded) ? $decoded : [];
            }
        }
        return $this->json;
    }

    public function header(string $name, $default = null)
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if ($name === 'Content-Type') {
            $key = 'CONTENT_TYPE';
        }
        return $this->server[$key] ?? $default;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function isAjax(): bool
    {
        return strtolower((string) $this->header('X-Requested-With', '')) === 'xmlhttprequest';
    }

    public function wantsJson(): bool
    {
        $accept = (string) $this->header('Accept', '');
        return $this->isAjax() || str_contains($accept, 'application/json') || str_starts_with($this->path, '/api/');
    }

    public function isSecure(): bool
    {
        $https = $this->server['HTTPS'] ?? '';
        $proto = $this->server['HTTP_X_FORWARDED_PROTO'] ?? '';
        return ($https && $https !== 'off') || $proto === 'https';
    }
}
