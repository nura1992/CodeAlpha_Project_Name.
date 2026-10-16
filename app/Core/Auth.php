<?php

namespace MedCore\Core;

use MedCore\Repositories\UserRepository;
use MedCore\Services\AuditLogger;

/**
 * Authentication service.
 *
 * - password_hash()/password_verify() credential checks
 * - session ID regeneration on login (session-fixation defence)
 * - DB-backed rate limiting (lockout after repeated failures)
 */
final class Auth
{
    private static ?array $user = null;
    private static bool $resolved = false;

    /** Attempt to sign in. Returns an error message on failure, null on success. */
    public static function attempt(string $email, string $password, Request $request): ?string
    {
        $email = strtolower(trim($email));
        $maxAttempts = (int) Config::get('app.auth.max_login_attempts', 5);
        $lockoutMinutes = (int) Config::get('app.auth.lockout_minutes', 15);

        if (self::tooManyAttempts($email, $request->ip(), $maxAttempts, $lockoutMinutes)) {
            AuditLogger::log(null, 'auth.login_locked', 'user', null, ['email' => $email], $request);
            return "Too many failed sign-in attempts. Try again in {$lockoutMinutes} minutes.";
        }

        $user = UserRepository::findByEmail($email);

        $valid = $user !== null
            && $user['status'] === 'active'
            && password_verify($password, (string) $user['password_hash']);

        UserRepository::recordLoginAttempt($email, $request->ip(), $valid);

        if (!$valid) {
            AuditLogger::log(null, 'auth.login_failed', 'user', null, ['email' => $email], $request);
            return 'Invalid email or password.';
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            UserRepository::updatePasswordHash((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
        }

        self::login($user, $request);
        return null;
    }

    public static function login(array $user, Request $request): void
    {
        Session::regenerate(); // prevent session fixation
        Csrf::rotate();
        Session::set('user_id', (int) $user['id']);
        Session::set('_started_at', time());
        Session::set('_last_activity', time());
        self::$user = null;
        self::$resolved = false;

        UserRepository::touchLastLogin((int) $user['id']);
        AuditLogger::log((int) $user['id'], 'auth.login', 'user', (int) $user['id'], null, $request);
    }

    public static function logout(Request $request): void
    {
        $id = self::id();
        if ($id !== null) {
            AuditLogger::log($id, 'auth.logout', 'user', $id, null, $request);
        }
        Session::destroy();
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        $user = self::user();
        return $user === null ? null : (int) $user['id'];
    }

    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;
        $id = Session::get('user_id');
        if ($id === null) {
            self::$user = null;
            return null;
        }
        $user = UserRepository::find((int) $id);
        if ($user === null || $user['status'] !== 'active') {
            Session::forget('user_id');
            self::$user = null;
            return null;
        }
        self::$user = $user;
        return self::$user;
    }

    public static function hasRole(string ...$slugs): bool
    {
        $user = self::user();
        if ($user === null) {
            return false;
        }
        $roles = Authorization::rolesOf((int) $user['id']);
        return array_intersect($slugs, $roles) !== [];
    }

    private static function tooManyAttempts(string $email, string $ip, int $max, int $minutes): bool
    {
        $row = Database::first(
            'SELECT COUNT(*) AS fails FROM login_attempts
             WHERE success = 0 AND attempted_at >= ' . Database::minutesAgo($minutes) . '
               AND (email = ? OR ip = ?)',
            [$email, $ip]
        );
        return (int) ($row['fails'] ?? 0) >= $max;
    }
}
