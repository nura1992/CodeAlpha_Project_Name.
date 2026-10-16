<?php

namespace MedCore\Core;

/**
 * Secure session management.
 *
 * - HttpOnly + SameSite=Lax cookies (Secure when configured/HTTPS).
 * - Session ID regeneration after privilege change (login).
 * - Idle and absolute timeouts.
 */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure = (bool) Config::get('app.session.secure_cookie', false);
        if (!$secure && (new Request())->isSecure()) {
            $secure = true; // TLS terminates at a proxy; cookie still secure
        }

        session_name(Config::get('app.session.name', 'medcore_session'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        self::enforceTimeouts();
        self::set('_started_at', self::get('_started_at', time()));
    }

    private static function enforceTimeouts(): void
    {
        $idle = (int) Config::get('app.session.idle_timeout', 1800);
        $absolute = (int) Config::get('app.session.absolute_timeout', 43200);
        $now = time();

        $lastActivity = (int) self::get('_last_activity', $now);
        $startedAt = (int) self::get('_started_at', $now);

        if (($now - $lastActivity > $idle) || ($now - $startedAt > $absolute)) {
            if (self::has('user_id')) {
                // Start a fresh session so the explanation survives to the login page.
                self::destroy();
                session_start();
                self::set('_started_at', $now);
                self::set('_last_activity', $now);
                self::flash('warning', 'Your session expired. Please sign in again.');
                return;
            }
        }
        self::set('_last_activity', $now);
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public static function startFresh(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
        }
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool) $p['secure'], (bool) $p['httponly']);
        }
        session_destroy();
    }

    public static function get(string $key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, $_SESSION ?? []);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Store a one-time flash message. */
    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function pullFlash(): array
    {
        $messages = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $messages;
    }

    /** Old-input helpers for form repopulation after validation errors. */
    public static function flashInput(array $input): void
    {
        unset($input['password'], $input['password_confirmation'], $input['_token']);
        $_SESSION['_old'] = $input;
    }

    public static function old(string $key, $default = '')
    {
        return $_SESSION['_old'][$key] ?? $default;
    }

    public static function pullOld(): array
    {
        $old = $_SESSION['_old'] ?? [];
        unset($_SESSION['_old']);
        return $old;
    }

    public static function flashErrors(array $errors): void
    {
        $_SESSION['_errors'] = $errors;
    }

    public static function pullErrors(): array
    {
        $errors = $_SESSION['_errors'] ?? [];
        unset($_SESSION['_errors']);
        return $errors;
    }
}
