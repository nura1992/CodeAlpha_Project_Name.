<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    public string $method = 'GET';
    public string $path = '/';
    public array $query = [];
    public array $input = [];
    public array $files = [];
    public array $headers = [];
    public string $ip = '0.0.0.0';
    public string $rawBody = '';
    public ?array $user = null;
    public array $params = [];

    public static function capture(): self
    {
        $r = new self();
        $r->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $r->path = rtrim((string) $uri, '/') ?: '/';
        $r->query = $_GET;
        $r->files = $_FILES;
        $r->rawBody = (string) file_get_contents('php://input');

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $decoded = json_decode($r->rawBody, true);
            if ($r->rawBody !== '' && !is_array($decoded)) {
                throw new ApiException(400, 'INVALID_JSON', 'The request body is not valid JSON.');
            }
            $r->input = is_array($decoded) ? $decoded : [];
        } else {
            $r->input = $_POST;
        }

        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $r->headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        $r->ip = self::clientIp();
        return $r;
    }

    private static function clientIp(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        // Only trust proxy headers when explicitly configured behind a known reverse proxy.
        if (Env::bool('TRUST_PROXY')) {
            $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            $first = trim(explode(',', $fwd)[0] ?? '');
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        return $remote;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->input) ? $this->input[$key] : $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->query) ? $this->query[$key] : $default;
    }

    public function param(string $key): string
    {
        return (string) ($this->params[$key] ?? '');
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function userId(): int
    {
        return (int) ($this->user['id'] ?? 0);
    }
}
