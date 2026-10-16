<?php

namespace MedCore\Core;

/**
 * CSRF token generation and verification (synchronizer token pattern).
 *
 * Every state-changing web request must include the token either as the
 * `_token` POST field or the `X-CSRF-TOKEN` header (used by fetch()).
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        $token = Session::get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::set(self::SESSION_KEY, $token);
        }
        return $token;
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . self::token() . '">';
    }

    public static function verify(Request $request): bool
    {
        $sent = $request->input('_token');
        if (!is_string($sent) || $sent === '') {
            $sent = $request->header('X-CSRF-TOKEN', '');
        }
        $expected = Session::get(self::SESSION_KEY);
        return is_string($expected) && is_string($sent) && $sent !== ''
            && hash_equals($expected, $sent);
    }

    public static function rotate(): void
    {
        Session::set(self::SESSION_KEY, bin2hex(random_bytes(32)));
    }
}
