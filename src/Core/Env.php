<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Reads config/.env (kept OUTSIDE the public web root in production).
 * Process environment variables take precedence over the file.
 */
final class Env
{
    private static array $values = [];
    private static bool $loaded = false;

    public static function load(string $file): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        $file = getenv('ENV_FILE') ?: $file;
        if (!is_file($file) || !is_readable($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $value = trim($value);
            // Strip trailing inline comments for unquoted values.
            if ($value !== '' && $value[0] !== '"' && $value[0] !== "'") {
                $value = trim(preg_replace('/\s+#.*$/', '', $value) ?? $value);
            } else {
                $value = trim($value, "\"'");
            }
            self::$values[trim($key)] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $env = getenv($key);
        if ($env !== false && $env !== '') {
            return $env;
        }
        return self::$values[$key] ?? $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        if ($v === null) {
            return $default;
        }
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default): int
    {
        $v = self::get($key);
        return $v === null || !is_numeric($v) ? $default : (int) $v;
    }

    public static function appKey(): string
    {
        $raw = self::get('APP_KEY', '');
        if (strpos($raw, 'base64:') === 0) {
            $raw = base64_decode(substr($raw, 7), true) ?: '';
        }
        if (strlen($raw) < 32) {
            throw new \RuntimeException('APP_KEY is missing or too short. Generate one with: php -r "echo base64_encode(random_bytes(32));"');
        }
        return substr($raw, 0, 32);
    }

    public static function appUrl(): string
    {
        return rtrim(self::get('APP_URL', 'http://localhost:8000'), '/');
    }
}
