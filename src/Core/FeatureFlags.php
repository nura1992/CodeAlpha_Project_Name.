<?php
/**
 * FeatureFlags — runtime access to the module toggles.
 *
 * Resolution order:
 *   1. Emergency lockdown setting disables every non-critical feature.
 *   2. The persisted feature_flags row (set by the Super Admin).
 *   3. The registry default.
 *   4. Dependencies: a feature is disabled if any dependency is disabled.
 *
 * Results are cached per request. Disabling a module never deletes its data.
 */

declare(strict_types=1);

namespace CodeAlpha\Core;

final class FeatureFlags
{
    /** @var array<string, bool>|null */
    private static ?array $cache = null;

    private static function resolveAll(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $registry = FeatureRegistry::all();
        $persisted = [];
        try {
            foreach (Database::all('SELECT flag_key, enabled FROM feature_flags') as $row) {
                $persisted[$row['flag_key']] = (int) $row['enabled'] === 1;
            }
        } catch (\Throwable $e) {
            // Table may not exist yet during installation.
            $persisted = [];
        }

        $lockdown = false;
        try {
            $lockdown = (bool) Settings::get('emergency_lockdown', false);
        } catch (\Throwable $e) {
            $lockdown = false;
        }

        $resolved = [];
        foreach ($registry as $key => $feature) {
            $enabled = $persisted[$key] ?? (bool) $feature['default'];
            if ($lockdown && empty($feature['critical'])) {
                $enabled = false;
            }
            $resolved[$key] = $enabled;
        }
        // Enforce dependencies (iterate to a fixed point for chains).
        for ($i = 0; $i < 3; $i++) {
            foreach ($registry as $key => $feature) {
                foreach ($feature['depends_on'] as $dep) {
                    if (isset($resolved[$dep]) && !$resolved[$dep]) {
                        $resolved[$key] = false;
                    }
                }
            }
        }

        self::$cache = $resolved;
        return $resolved;
    }

    public static function isEnabled(string $key): bool
    {
        $all = self::resolveAll();
        return $all[$key] ?? false;
    }

    public static function allStates(): array
    {
        return self::resolveAll();
    }

    /** For the frontend: key => bool map of every feature. */
    public static function clientMap(): array
    {
        return self::allStates();
    }

    public static function label(string $key): string
    {
        return FeatureRegistry::get($key)['label'] ?? $key;
    }

    /** Persist a toggle (Super Admin action). */
    public static function set(string $key, bool $enabled, ?int $actorId): void
    {
        if (!FeatureRegistry::get($key)) {
            throw new \InvalidArgumentException('Unknown feature flag: ' . $key);
        }
        $existing = Database::one('SELECT id FROM feature_flags WHERE flag_key = :k', ['k' => $key]);
        if ($existing) {
            Database::update('feature_flags', [
                'enabled'    => $enabled ? 1 : 0,
                'updated_by' => $actorId,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $existing['id']]);
        } else {
            Database::insert('feature_flags', [
                'flag_key'   => $key,
                'enabled'    => $enabled ? 1 : 0,
                'updated_by' => $actorId,
                'updated_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
        self::$cache = null;
    }

    /** Ensure every registry feature has a persisted row. */
    public static function seedDefaults(): void
    {
        foreach (FeatureRegistry::all() as $key => $feature) {
            $existing = Database::one('SELECT id FROM feature_flags WHERE flag_key = :k', ['k' => $key]);
            if (!$existing) {
                Database::insert('feature_flags', [
                    'flag_key'   => $key,
                    'enabled'    => !empty($feature['default']) ? 1 : 0,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }
}
