<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Session authentication. The session token lives in an HttpOnly, SameSite cookie;
 * only its SHA-256 hash is stored. State-changing requests must echo the per-session
 * CSRF token in the X-CSRF-Token header.
 */
final class Auth
{
    public const COOKIE = 'ca_session';
    private const LOCK_AFTER = 5;
    private const LOCK_MINUTES = 15;

    public static function hashPassword(string $password): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_hash($password, $algo);
    }

    /** Looks up a member by email, phone or username. */
    public static function findByIdentifier(string $identifier): ?array
    {
        $identifier = trim($identifier);
        if (str_contains($identifier, '@')) {
            return Database::one('SELECT * FROM users WHERE email = ? AND deleted_at IS NULL', [strtolower($identifier)]);
        }
        if (preg_match('/^\+?[0-9 \-()]{8,20}$/', $identifier)) {
            $phone = '+' . ltrim(preg_replace('/\D/', '', $identifier) ?? '', '+');
            return Database::one('SELECT * FROM users WHERE phone = ? AND deleted_at IS NULL', [$phone]);
        }
        return Database::one('SELECT * FROM users WHERE username = ? AND deleted_at IS NULL', [strtolower($identifier)]);
    }

    /** Verifies credentials, records failures and creates a session. */
    public static function login(string $identifier, string $password, Request $request): array
    {
        RateLimiter::hit('login:ip:' . $request->ip, 40, 900);
        RateLimiter::hit('login:id:' . hash('sha256', strtolower($identifier)), 12, 900);

        $user = self::findByIdentifier($identifier);
        $genericError = new ApiException(401, 'INVALID_CREDENTIALS', 'The details you entered are not correct.');
        if ($user === null || empty($user['password_hash'])) {
            password_verify($password, '$argon2id$v=19$m=65536,t=4,p=1$c2FsdHNhbHRzYWx0$AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');
            throw $genericError;
        }
        if ($user['locked_until'] !== null && strtotime($user['locked_until'] . ' UTC') > time()) {
            throw new ApiException(423, 'ACCOUNT_LOCKED', 'Too many failed attempts. Please try again later.');
        }
        if (!password_verify($password, $user['password_hash'])) {
            Database::exec(
                'UPDATE users SET failed_logins = failed_logins + 1,
                 locked_until = IF(failed_logins + 1 >= ?, NOW() + INTERVAL ? MINUTE, locked_until) WHERE id = ?',
                [self::LOCK_AFTER, self::LOCK_MINUTES, $user['id']]
            );
            throw $genericError;
        }
        if ($user['status'] === 'unverified') {
            throw new ApiException(403, 'ACCOUNT_UNVERIFIED', 'Please verify your email or phone number to activate your account.');
        }
        if ($user['status'] !== 'active') {
            throw new ApiException(403, 'ACCOUNT_SUSPENDED', 'This account cannot sign in. Contact support for help.');
        }
        Database::exec('UPDATE users SET failed_logins = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?', [$user['id']]);
        if (password_needs_rehash($user['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
            Database::exec('UPDATE users SET password_hash = ? WHERE id = ?', [self::hashPassword($password), $user['id']]);
        }
        return self::startSession((int) $user['id'], $request);
    }

    /** Creates a session row, sets the cookie and returns the public session payload. */
    public static function startSession(int $userId, Request $request): array
    {
        $token = Crypto::randomToken(32);
        $csrf = bin2hex(random_bytes(32));
        $ttl = Env::int('SESSION_TTL_HOURS', 168);
        Database::exec(
            'INSERT INTO sessions (user_id, token_hash, csrf_token, user_agent, ip_address, expires_at)
             VALUES (?, ?, ?, ?, ?, NOW() + INTERVAL ? HOUR)',
            [$userId, hash('sha256', $token), $csrf, substr((string) $request->header('user-agent'), 0, 255), $request->ip, $ttl]
        );
        self::setCookie($token, $ttl * 3600);
        Audit::log($userId, 'auth.login', 'user', $userId);
        return ['user_id' => $userId, 'csrf_token' => $csrf];
    }

    public static function setCookie(string $token, int $maxAge): void
    {
        setcookie(self::COOKIE, $token, [
            'expires' => time() + $maxAge,
            'path' => '/',
            'secure' => self::secureCookies(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function clearCookie(): void
    {
        setcookie(self::COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => self::secureCookies(), 'httponly' => true, 'samesite' => 'Lax']);
    }

    private static function secureCookies(): bool
    {
        return Env::get('APP_ENV', 'production') !== 'local';
    }

    /** Returns the active user for this request, or null. Cached per request. */
    public static function currentUser(Request $request): ?array
    {
        if ($request->user !== null) {
            return $request->user;
        }
        $token = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($token) || !preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            return null;
        }
        $row = Database::one(
            "SELECT u.id, u.username, u.email, u.phone, u.gender, u.account_type, u.status, u.date_of_birth,
                    u.must_change_password, s.id AS session_id, s.csrf_token
             FROM sessions s JOIN users u ON u.id = s.user_id
             WHERE s.token_hash = ? AND s.revoked_at IS NULL AND s.expires_at > NOW()
               AND u.deleted_at IS NULL AND u.status = 'active'",
            [hash('sha256', $token)]
        );
        return $row ?: null;
    }

    public static function requireUser(Request $request): array
    {
        $user = self::currentUser($request);
        if ($user === null) {
            throw new ApiException(401, 'UNAUTHENTICATED', 'Please sign in to continue.');
        }
        $request->user = $user;
        return $user;
    }

    public static function verifyCsrf(Request $request): void
    {
        $sent = (string) ($request->header('x-csrf-token') ?? '');
        $expected = (string) ($request->user['csrf_token'] ?? '');
        if ($expected === '' || $sent === '' || !hash_equals($expected, $sent)) {
            throw new ApiException(419, 'CSRF_FAILED', 'Your session token is missing or expired. Refresh the page and try again.');
        }
    }

    public static function logout(Request $request): void
    {
        if (!empty($request->user['session_id'])) {
            Database::exec('UPDATE sessions SET revoked_at = NOW() WHERE id = ?', [$request->user['session_id']]);
        }
        self::clearCookie();
    }

    /** Revokes every session for a user (password reset, suspension, deletion). */
    public static function revokeAll(int $userId): void
    {
        Database::exec('UPDATE sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [$userId]);
    }
}
