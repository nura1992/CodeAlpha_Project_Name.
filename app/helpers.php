<?php

use MedCore\Core\Config;
use MedCore\Core\Csrf;
use MedCore\Core\Session;
use MedCore\Core\View;

/*
 * Global view + utility helpers available inside templates.
 */

/** Escape a string for safe HTML output (XSS defence). */
function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Config shortcut. */
function config(string $key, $default = null)
{
    return Config::get($key, $default);
}

/** URL helper (root-relative; works behind the preview proxy and vhosts). */
function url(string $path = ''): string
{
    return '/' . ltrim($path, '/');
}

/** Asset URL with a cache-busting version query. */
function asset(string $path): string
{
    $full = PUBLIC_PATH . '/assets/' . ltrim($path, '/');
    $v = is_file($full) ? (string) filemtime($full) : '1';
    return '/assets/' . ltrim($path, '/') . '?v=' . $v;
}

/** CSRF hidden input for forms. */
function csrf_field(): string
{
    return Csrf::field();
}

/** Old form input after a validation redirect. */
function old(string $key, $default = '')
{
    return Session::old($key, $default);
}

/** Validation errors from the previous request. */
function errors(): array
{
    static $errors = null;
    if ($errors === null) {
        $errors = Session::pullErrors();
    }
    return $errors;
}

function error_for(string $key): ?string
{
    $all = errors();
    return $all[$key][0] ?? null;
}

function has_error(string $key): string
{
    return error_for($key) !== null ? ' is-invalid' : '';
}

/** Render a partial view. */
function partial(string $template, array $data = []): string
{
    return View::partial($template, $data);
}

/** Pagination link builder preserving the current query string. */
function page_url(int $page): string
{
    $qs = $_GET;
    $qs['page'] = $page;
    return '?' . http_build_query($qs);
}

/** Format helpers. */
function fdate(?string $ts, string $format = 'j M Y'): string
{
    if (!$ts) {
        return '—';
    }
    $t = strtotime($ts);
    return $t ? date($format, $t) : '—';
}

function fdatetime(?string $ts): string
{
    return fdate($ts, 'D j M Y, g:ia');
}

/** Badge class for common status values. */
function status_badge(string $status): string
{
    return 'badge badge-' . match ($status) {
        'active', 'approved', 'confirmed', 'completed', 'success' => 'success',
        'pending', 'booked', 'info' => 'info',
        'suspended', 'rescheduled', 'warning' => 'warning',
        'inactive', 'deactivated', 'cancelled', 'declined', 'no_show', 'danger' => 'danger',
        default => 'muted',
    };
}
