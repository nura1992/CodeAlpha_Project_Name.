<?php
declare(strict_types=1);

namespace App\Core;

/** Protected system configuration stored in MySQL (system_settings). */
final class Settings
{
    /** Keys that staff may change, with validation rules. */
    public const ALLOWED = [
        'app.display_name'            => ['type' => 'string', 'min' => 2, 'max' => 80],
        'support.email'               => ['type' => 'email'],
        'auth.min_age'                => ['type' => 'int', 'min' => 18, 'max' => 99],
        'auth.otp_ttl_minutes'        => ['type' => 'int', 'min' => 5, 'max' => 30],
        'profiles.max_photos'         => ['type' => 'int', 'min' => 1, 'max' => 10],
        'calls.ring_seconds'          => ['type' => 'int', 'min' => 20, 'max' => 90],
        'calls.max_per_hour'          => ['type' => 'int', 'min' => 1, 'max' => 200],
        'verification.retention_days' => ['type' => 'int', 'min' => 1, 'max' => 90],
        'ads.default_frequency_cap'   => ['type' => 'int', 'min' => 1, 'max' => 20],
        'community.rules'             => ['type' => 'string', 'min' => 10, 'max' => 10000],
        'community.max_post_chars'    => ['type' => 'int', 'min' => 100, 'max' => 5000],
    ];

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Database::all('SELECT setting_key, setting_value FROM system_settings') as $row) {
                self::$cache[$row['setting_key']] = json_decode((string) $row['setting_value'], true);
            }
        }
        return self::$cache;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    public static function getInt(string $key, int $default): int
    {
        $v = self::get($key);
        return is_numeric($v) ? (int) $v : $default;
    }

    public static function set(string $key, mixed $value, int $actorId, ?string $reason = null): void
    {
        if (!isset(self::ALLOWED[$key])) {
            throw new ApiException(422, 'UNKNOWN_SETTING', 'This setting cannot be changed here.');
        }
        $rule = self::ALLOWED[$key];
        $clean = match ($rule['type']) {
            'int' => Validator::int($value, $key, $rule['min'], $rule['max']),
            'email' => Validator::email($value),
            default => Validator::str($value, $key, $rule['min'], $rule['max']),
        };
        $before = self::get($key);
        Database::exec(
            'INSERT INTO system_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)',
            [$key, json_encode($clean, JSON_UNESCAPED_UNICODE), $actorId]
        );
        self::$cache = null;
        Audit::log($actorId, 'settings.updated', 'setting', $key, $reason, ['value' => $before], ['value' => $clean]);
    }
}
