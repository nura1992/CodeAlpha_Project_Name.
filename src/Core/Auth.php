<?php
/**
 * Auth — session-based authentication for members and staff.
 *
 * Passwords use bcrypt via password_hash(). Account lockout is enforced after
 * repeated failed logins. The current user is resolved once per request and
 * cached in a static property.
 */

declare(strict_types=1);

namespace CodeAlpha\Core;

final class Auth
{
    private static ?array $userCache = null;
    private static bool $resolved = false;

    private const LOCKOUT_THRESHOLD = 5;
    private const LOCKOUT_MINUTES = 15;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        // Absolute lifetime cap.
        $lifetime = (int) config('session.lifetime', 120) * 60;
        if (isset($_SESSION['__created']) && (time() - (int) $_SESSION['__created']) > $lifetime) {
            self::logout();
            session_start();
        }
        if (!isset($_SESSION['__created'])) {
            $_SESSION['__created'] = time();
        }
        // Idle timeout.
        if (isset($_SESSION['__last_activity']) && (time() - (int) $_SESSION['__last_activity']) > $lifetime) {
            self::logout();
            session_start();
            $_SESSION['__created'] = time();
        }
        $_SESSION['__last_activity'] = time();
    }

    public static function login(array $user): void
    {
        self::startSession();
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['__created'] = time();
        $_SESSION['__last_activity'] = time();
        self::$userCache = $user;
        self::$resolved = true;
    }

    public static function logout(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        self::$userCache = null;
        self::$resolved = false;
    }

    public static function check(): bool
    {
        return self::id() !== null;
    }

    public static function id(): ?int
    {
        self::startSession();
        $id = $_SESSION['user_id'] ?? null;
        return $id === null ? null : (int) $id;
    }

    /** Current user row (with profile joined) or null. */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$userCache;
        }
        $id = self::id();
        if ($id === null) {
            self::$resolved = true;
            self::$userCache = null;
            return null;
        }
        $user = Database::one(
            'SELECT u.*, p.display_name, p.verification_status, p.discoverable, p.hidden,
                    p.profile_score, p.last_active_at, p.privacy, p.notification_settings
               FROM users u
               LEFT JOIN profiles p ON p.user_id = u.id
              WHERE u.id = :id AND u.deleted_at IS NULL
              LIMIT 1',
            ['id' => $id]
        );
        if (!$user) {
            // Session references a deleted/missing account — force logout.
            self::logout();
            self::$resolved = true;
            self::$userCache = null;
            return null;
        }
        if (($user['status'] ?? '') === 'suspended') {
            self::$resolved = true;
            self::$userCache = ['__suspended' => true] + $user;
            return self::$userCache;
        }
        self::$userCache = $user;
        self::$resolved = true;
        return $user;
    }

    public static function isSuspended(): bool
    {
        $user = self::user();
        return $user !== null && !empty($user['__suspended']);
    }

    public static function isStaff(): bool
    {
        $user = self::user();
        return $user !== null && (int) ($user['is_staff'] ?? 0) === 1;
    }

    /**
     * Attempt a password login. Returns the user row on success, null on
     * failure. Enforces lockout and records audit-relevant metadata.
     */
    public static function attempt(string $identifier, string $password, Request $request): ?array
    {
        $identifier = trim($identifier);
        $user = Database::one(
            'SELECT * FROM users
              WHERE (email = :id1 OR phone = :id2) AND deleted_at IS NULL
              LIMIT 1',
            ['id1' => $identifier, 'id2' => $identifier]
        );

        if (!$user) {
            return null;
        }

        if (!empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time()) {
            return null; // Locked — do not reveal details.
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            $failed = (int) $user['failed_logins'] + 1;
            $lockedUntil = null;
            if ($failed >= self::LOCKOUT_THRESHOLD) {
                $lockedUntil = date('Y-m-d H:i:s', time() + self::LOCKOUT_MINUTES * 60);
                $failed = 0;
            }
            Database::update('users', [
                'failed_logins' => $failed,
                'locked_until'  => $lockedUntil,
                'updated_at'    => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $user['id']]);
            AuditLog::record(null, 'auth.login_failed', 'user', (string) $user['id'], [
                'identifier' => $identifier,
            ], $request);
            return null;
        }

        // Success — clear counters, stamp login.
        Database::update('users', [
            'failed_logins' => 0,
            'locked_until'  => null,
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => substr($request->ip(), 0, 45),
            'updated_at'    => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $user['id']]);

        return $user;
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function passwordNeedsRehash(array $user): bool
    {
        return password_needs_rehash((string) $user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12]);
    }
}
