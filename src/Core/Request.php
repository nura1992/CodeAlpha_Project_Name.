<?php
/**
 * Request — immutable-ish wrapper around the PHP superglobals.
 */

declare(strict_types=1);

namespace CodeAlpha\Core;

final class Request
{
    private array $query;
    private array $body;
    private array $server;
    private array $cookies;
    private array $files;
    private ?array $jsonCache = null;
    private string $rawBody;

    public function __construct()
    {
        $this->query   = $_GET;
        $this->server  = $_SERVER;
        $this->cookies = $_COOKIE;
        $this->files   = $_FILES;
        $this->rawBody = file_get_contents('php://input') ?: '';
        $contentType = (string) ($this->server['CONTENT_TYPE'] ?? '');
        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($this->rawBody, true);
            $this->body = is_array($decoded) ? $decoded : [];
        } else {
            $this->body = $_POST;
        }
    }

    public static function capture(): self
    {
        return new self();
    }

    public function method(): string
    {
        $method = strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
        // Support _method override for HTML forms (PUT/PATCH/DELETE via POST)
        if ($method === 'POST') {
            $override = $this->body['_method'] ?? $this->query['_method'] ?? $this->header('X-HTTP-Method-Override');
            if (is_string($override) && $override !== '') {
                $override = strtoupper($override);
                if (in_array($override, ['PUT','PATCH','DELETE'], true)) return $override;
            }
        }
        return $method;
    }

    public function path(): string
    {
        $uri = (string) ($this->server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        // Normalise and prevent path traversal in the route path.
        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public function query(string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->query;
        }
        return $this->query[$key] ?? $default;
    }

    public function input(string $key = null, $default = null)
    {
        if ($key === null) {
            return array_merge($this->body, $this->query);
        }
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->input();
    }

    public function only(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $value = $this->input($key);
            if ($value !== null) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    public function has(string $key): bool
    {
        return $this->input($key) !== null;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key, $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->input($key);
        if ($value === null) {
            return $default;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function array(string $key): array
    {
        $value = $this->input($key);
        return is_array($value) ? $value : [];
    }

    public function header(string $name, string $default = ''): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if ($name === 'Content-Type') {
            $key = 'CONTENT_TYPE';
        } elseif ($name === 'Content-Length') {
            $key = 'CONTENT_LENGTH';
        }
        return (string) ($this->server[$key] ?? $default);
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('Authorization');
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    public function cookie(string $name, string $default = ''): string
    {
        return (string) ($this->cookies[$name] ?? $default);
    }

    public function ip(): string
    {
        $ip = (string) ($this->server['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($ip !== '') {
            $parts = explode(',', $ip);
            $ip = trim($parts[0]);
        } else {
            $ip = (string) ($this->server['REMOTE_ADDR'] ?? '');
        }
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function isApi(): bool
    {
        return str_starts_with($this->path(), '/api/');
    }

    public function wantsJson(): bool
    {
        if ($this->isApi()) {
            return true;
        }
        $accept = $this->header('Accept');
        return str_contains($accept, 'application/json');
    }

    public function isMethod(string $method): bool
    {
        return $this->method() === strtoupper($method);
    }

    /** Is the request a state-changing method? */
    public function isStateChanging(): bool
    {
        return in_array($this->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }
        return $file;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function server(string $key, $default = null)
    {
        return $this->server[$key] ?? $default;
    }

    public function fullUrl(): string
    {
        $https = (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off')
            || (int) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 443;
        $scheme = $https ? 'https' : 'http';
        $host = (string) ($this->server['HTTP_HOST'] ?? config('app.url', 'localhost'));
        return $scheme . '://' . $host . ($this->server['REQUEST_URI'] ?? '/');
    }
}
