<?php
/**
 * Global helper functions available to views, controllers and services.
 */

declare(strict_types=1);

use CodeAlpha\Core\Auth;
use CodeAlpha\Core\Csrf;
use CodeAlpha\Core\FeatureFlags;
use CodeAlpha\Core\Settings;

if (!function_exists('e')) {
    /** HTML-escape a value for output. */
    function e($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('asset')) {
    /** URL for a public asset. */
    function asset(string $path): string
    {
        return rtrim((string) config('app.url', ''), '/') . '/assets/' . ltrim($path, '/');
    }
}

if (!function_exists('url')) {
    /** Application URL for a path. */
    function url(string $path = '/'): string
    {
        return rtrim((string) config('app.url', ''), '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return Csrf::field();
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return Csrf::token();
    }
}

if (!function_exists('feature_enabled')) {
    function feature_enabled(string $key): bool
    {
        return FeatureFlags::isEnabled($key);
    }
}

if (!function_exists('setting')) {
    function setting(string $key, $default = null)
    {
        return Settings::get($key, $default);
    }
}

if (!function_exists('current_user')) {
    function current_user(): ?array
    {
        return Auth::user();
    }
}

if (!function_exists('is_staff_user')) {
    function is_staff_user(): bool
    {
        return Auth::isStaff();
    }
}

if (!function_exists('flash')) {
    /** One-shot flash message: flash('key', 'message') to set; flash('key') to get. */
    function flash(string $key, ?string $value = null): ?string
    {
        Auth::startSession();
        if ($value !== null) {
            $_SESSION['__flash'][$key] = $value;
            return null;
        }
        $message = $_SESSION['__flash'][$key] ?? null;
        unset($_SESSION['__flash'][$key]);
        return $message;
    }
}

if (!function_exists('old')) {
    /** Old form input repopulation. */
    function old(string $key, $default = ''): string
    {
        Auth::startSession();
        $value = $_SESSION['__old'][$key] ?? $default;
        return e(is_scalar($value) ? (string) $value : $default);
    }
}

if (!function_exists('stash_old_input')) {
    function stash_old_input(array $input): void
    {
        Auth::startSession();
        $_SESSION['__old'] = $input;
    }
}

if (!function_exists('clear_old_input')) {
    function clear_old_input(): void
    {
        Auth::startSession();
        unset($_SESSION['__old']);
    }
}

if (!function_exists('age_from_birthdate')) {
    /** Compute age in years from a Y-m-d birthdate. */
    function age_from_birthdate(?string $birthdate): ?int
    {
        if (!$birthdate) {
            return null;
        }
        $dob = \DateTime::createFromFormat('Y-m-d', $birthdate);
        if (!$dob) {
            return null;
        }
        return (int) $dob->diff(new \DateTime('today'))->y;
    }
}

if (!function_exists('json_decode_or')) {
    function json_decode_or(?string $json, $default = null)
    {
        if ($json === null || $json === '') {
            return $default;
        }
        $decoded = json_decode($json, true);
        return $decoded === null ? $default : $decoded;
    }
}

if (!function_exists('initials')) {
    function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $out = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $out .= mb_strtoupper(mb_substr($part, 0, 1));
        }
        return $out === '' ? '?' : $out;
    }
}

if (!function_exists('time_ago')) {
    function time_ago(?string $datetime): string
    {
        if (!$datetime) {
            return '';
        }
        $ts = strtotime($datetime);
        if ($ts === false) {
            return '';
        }
        $diff = time() - $ts;
        if ($diff < 60) {
            return 'just now';
        }
        if ($diff < 3600) {
            return intdiv($diff, 60) . 'm ago';
        }
        if ($diff < 86400) {
            return intdiv($diff, 3600) . 'h ago';
        }
        if ($diff < 604800) {
            return intdiv($diff, 86400) . 'd ago';
        }
        return date('j M Y', $ts);
    }
}

if (!function_exists('money')) {
    function money($amount, string $currency = 'NGN'): string
    {
        $symbols = ['NGN' => '₦', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'];
        $symbol = $symbols[$currency] ?? $currency . ' ';
        return $symbol . number_format((float) $amount, 2);
    }
}

if (!function_exists('can')) {
    /** RBAC check for the current user. */
    function can(string $permission): bool
    {
        return \CodeAlpha\Services\RbacService::can(Auth::id(), $permission);
    }
}

if (!function_exists('has_role')) {
    function has_role(string $slug): bool
    {
        return \CodeAlpha\Services\RbacService::hasRole(Auth::id(), $slug);
    }
}

if (!function_exists('uuid')) {
    function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('verified_badge')) {
    /** The verified badge means the platform completed its stated checks —
     *  it does not guarantee identity or intentions. */
    function verified_badge(bool $verified = true): string
    {
        if (!$verified) {
            return '';
        }
        return ' <span class="badge badge-verified" title="Profile verification completed by CodeAlpha staff. This confirms the platform completed its stated checks; it is not a guarantee of identity or intentions.">✓ Verified</span>';
    }
}
