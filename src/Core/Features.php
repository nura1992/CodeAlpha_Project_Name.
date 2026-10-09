<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Central feature registry. Dependencies: a module is effectively enabled only when
 * its flag AND every parent flag is enabled. `locked` modules cannot be switched off
 * (compliance controls). The emergency lockdown switch disables every non-account module.
 */
final class Features
{
    public const LOCKDOWN = 'system.emergency_lockdown';

    /** key => label, group, parent, default, locked */
    public const REGISTRY = [
        'account.registration'      => ['Registration and OTP', 'Account and security', null, true, false],
        'account.age_eligibility'   => ['Age eligibility checks (18+)', 'Account and security', null, true, true],
        'account.verification'      => ['Profile verification', 'Account and security', null, true, false],
        'matchmaking.discovery'     => ['Profile discovery and matching', 'Matchmaking', null, true, false],
        'comm.messaging'            => ['Private messaging', 'Communication', 'matchmaking.discovery', true, false],
        'comm.audio_calls'          => ['Audio calls', 'Communication', 'comm.messaging', true, false],
        'comm.video_calls'          => ['Video calls', 'Communication', 'comm.messaging', true, false],
        'comm.push'                 => ['Push notifications', 'Communication', null, true, false],
        'social.feed'               => ['Community feed', 'Social community', null, true, false],
        'social.reactions'          => ['Comments and reactions', 'Social community', 'social.feed', true, false],
        'social.sharing'            => ['External sharing', 'Social community', 'social.feed', true, false],
        'business.plans'            => ['Subscription plans', 'Business and revenue', null, true, false],
        'business.paystack'         => ['Paystack', 'Business and revenue', 'business.plans', true, false],
        'business.flutterwave'      => ['Flutterwave', 'Business and revenue', 'business.plans', true, false],
        'business.campaigns'        => ['Campaigns', 'Business and revenue', null, true, false],
        'business.ads'              => ['Advertisements', 'Business and revenue', 'business.campaigns', true, false],
        'admin.support'             => ['Support tickets', 'Administration', null, true, false],
        'admin.moderation'          => ['Moderation reports', 'Administration', null, true, false],
        'admin.analytics'           => ['Analytics', 'Administration', null, true, false],
        self::LOCKDOWN              => ['Emergency lockdown (disables all non-account modules)', 'System', null, false, false],
    ];

    /** Modules that remain available during an emergency lockdown. */
    private const ESSENTIAL = ['account.registration', 'account.age_eligibility', 'account.verification'];

    private static ?array $flags = null;

    private static function load(): array
    {
        if (self::$flags === null) {
            self::$flags = [];
            // Register any new module with its default. Existing rows are untouched.
            foreach (self::REGISTRY as $key => $meta) {
                Database::exec('INSERT IGNORE INTO feature_flags (module_key, enabled) VALUES (?, ?)', [$key, $meta[3] ? 1 : 0]);
            }
            foreach (Database::all('SELECT module_key, enabled FROM feature_flags') as $row) {
                self::$flags[$row['module_key']] = (bool) $row['enabled'];
            }
        }
        return self::$flags;
    }

    public static function enabled(string $key): bool
    {
        $flags = self::load();
        if (!isset(self::REGISTRY[$key])) {
            return false;
        }
        if ($key !== self::LOCKDOWN && ($flags[self::LOCKDOWN] ?? false) && !in_array($key, self::ESSENTIAL, true)) {
            return false;
        }
        if (!($flags[$key] ?? self::REGISTRY[$key][3])) {
            return false;
        }
        $parent = self::REGISTRY[$key][2];
        return $parent === null || self::enabled($parent);
    }

    public static function require(string $key): void
    {
        if (!self::enabled($key)) {
            $label = self::REGISTRY[$key][0] ?? $key;
            throw new ApiException(503, 'FEATURE_DISABLED',
                "{$label} is temporarily unavailable. Your data has been kept safe; please check back later.",
                ['feature' => $key]);
        }
    }

    /** Full registry with effective state, for the admin console and the frontend. */
    public static function map(): array
    {
        $out = [];
        foreach (self::REGISTRY as $key => [$label, $group, $parent, $default, $locked]) {
            $out[$key] = [
                'key' => $key,
                'label' => $label,
                'group' => $group,
                'parent' => $parent,
                'locked' => $locked,
                'configured' => (bool) (self::load()[$key] ?? $default),
                'effective' => self::enabled($key),
            ];
        }
        return $out;
    }

    public static function set(string $key, bool $enabled, int $actorId, ?string $reason): void
    {
        if (!isset(self::REGISTRY[$key])) {
            throw new ApiException(404, 'NOT_FOUND', 'Unknown module.');
        }
        if (self::REGISTRY[$key][4]) {
            throw new ApiException(422, 'LOCKED_FEATURE', 'This control is locked for compliance and cannot be switched off.');
        }
        if ($enabled === false && $key !== self::LOCKDOWN && ($reason === null || trim($reason) === '')) {
            // Disabling a module requires a documented reason (kept in the audit trail).
            throw new ApiException(422, 'REASON_REQUIRED', 'A reason is required when disabling a module.');
        }
        $before = self::load()[$key] ?? null;
        Database::exec(
            'INSERT INTO feature_flags (module_key, enabled, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_by = VALUES(updated_by)',
            [$key, $enabled ? 1 : 0, $actorId]
        );
        self::$flags = null;
        Audit::log($actorId, 'feature.toggled', 'feature', $key, $reason, ['enabled' => $before], ['enabled' => $enabled]);
    }

    /** Re-enables every unlocked module (used by the "Enable all" control). */
    public static function enableAll(int $actorId): void
    {
        foreach (array_keys(self::REGISTRY) as $key) {
            if (self::REGISTRY[$key][4]) {
                continue;
            }
            Database::exec(
                'INSERT INTO feature_flags (module_key, enabled, updated_by) VALUES (?, 1, ?)
                 ON DUPLICATE KEY UPDATE enabled = 1, updated_by = VALUES(updated_by)',
                [$key, $actorId]
            );
        }
        self::$flags = null;
        Audit::log($actorId, 'feature.enable_all', 'feature', '*');
    }
}
