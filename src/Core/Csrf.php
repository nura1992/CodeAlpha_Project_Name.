<?php
/**
 * Csrf — synchronizer-token CSRF protection.
 *
 * A per-session token is embedded in forms (hidden input) and exposed to JS
 * via a meta tag. State-changing requests must present it either as the
 * `_csrf` field or the `X-CSRF-Token` header. Verified for cookie/session
 * authenticated requests; pure bearer-token API clients are exempt.
 */

declare(strict_types=1);

namespace CodeAlpha\Core;

final class Csrf
{
    private const SESSION_KEY = '__csrf_token';

    public static function token(): string
    {
        Auth::startSession();
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION[self::SESSION_KEY];
    }

    public static function verify(Request $request): bool
    {
        if (!$request->isStateChanging()) {
            return true;
        }
        $expected = self::token();
        $given = (string) ($request->input('_csrf') ?? $request->header('X-CSRF-Token') ?? '');
        return $given !== '' && hash_equals($expected, $given);
    }

    /** Throwing variant used by middleware. */
    public static function requireValid(Request $request): void
    {
        if (!self::verify($request)) {
            AuditLog::record(Auth::id(), 'csrf.rejected', null, null, [
                'path'   => $request->path(),
                'method' => $request->method(),
            ], $request);
            Response::error('Invalid or missing CSRF token.', 419, ['code' => 'csrf_mismatch']);
        }
    }

    /** Hidden input for forms. */
    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }
}
