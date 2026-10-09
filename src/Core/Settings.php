<?php
/**
 * Settings — key/value system settings stored as JSON in the settings table.
 *
 * Protected settings (payment-related, security-related) may only be changed
 * by staff holding the settings.manage permission — enforced by the
 * controller, and every change is written to the audit log.
 */

declare(strict_types=1);

namespace CodeAlpha\Core;

final class Settings
{
    private static ?array $cache = null;

    private static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        self::$cache = [];
        foreach (Database::all('SELECT setting_key, value FROM settings') as $row) {
            $value = $row['value'];
            $decoded = json_decode((string) $value, true);
            self::$cache[$row['setting_key']] = $decoded ?? $value;
        }
        return self::$cache;
    }

    public static function get(string $key, $default = null)
    {
        $all = self::all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    public static function set(string $key, $value, ?int $actorId = null, bool $protected = false): void
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
        $existing = Database::one('SELECT id FROM settings WHERE setting_key = :k', ['k' => $key]);
        if ($existing) {
            Database::update('settings', [
                'value'        => $encoded,
                'is_protected' => $protected ? 1 : 0,
                'updated_by'   => $actorId,
                'updated_at'   => date('Y-m-d H:i:s'),
            ], 'setting_key = :k', ['k' => $key]);
        } else {
            Database::insert('settings', [
                'setting_key'  => $key,
                'value'        => $encoded,
                'is_protected' => $protected ? 1 : 0,
                'updated_by'   => $actorId,
                'updated_at'   => date('Y-m-d H:i:s'),
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
        }
        self::$cache = null;
    }

    /** All settings as key => value (decoded). */
    public static function allDecoded(): array
    {
        return self::all();
    }

    /** Rows including metadata (for the admin settings UI). */
    public static function rows(): array
    {
        return Database::all('SELECT * FROM settings ORDER BY setting_key ASC');
    }

    public static function delete(string $key): void
    {
        Database::delete('settings', 'setting_key = :k', ['k' => $key]);
        self::$cache = null;
    }
}
