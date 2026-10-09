<?php
/**
 * CodeAlpha bootstrap: autoloading, error handling, configuration, session.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// ---------------------------------------------------------------------------
// PSR-4 style autoloader for the CodeAlpha namespace (no Composer required).
// ---------------------------------------------------------------------------
spl_autoload_register(static function (string $class): void {
    $prefix = 'CodeAlpha\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = dirname(__DIR__) . '/src/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------
$config = require dirname(__DIR__) . '/config/config.php';

$GLOBALS['__codealpha_config'] = $config;

/** Global config accessor. */
function config(string $key = null, $default = null)
{
    $config = $GLOBALS['__codealpha_config'];
    if ($key === null) {
        return $config;
    }
    $segments = explode('.', $key);
    $value = $config;
    foreach ($segments as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }
    return $value;
}

date_default_timezone_set(config('app.timezone', 'UTC'));

// ---------------------------------------------------------------------------
// Error / exception handling — never leak internals to the browser.
// ---------------------------------------------------------------------------
set_exception_handler(static function (Throwable $e): void {
    $debug = (bool) config('app.debug', false);
    error_log('[CodeAlpha] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    $payload = ['error' => 'server_error', 'message' => 'An unexpected error occurred.'];
    if ($debug) {
        $payload['debug'] = ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()];
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
});

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// ---------------------------------------------------------------------------
// Session hardening
// ---------------------------------------------------------------------------
$sessionPath = (string) config('session.path');
if (!is_dir($sessionPath)) {
    @mkdir($sessionPath, 0770, true);
}
if (is_dir($sessionPath) && is_writable($sessionPath)) {
    session_save_path($sessionPath);
}
session_name((string) config('session.name', 'codealpha_session'));
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => (bool) config('session.secure', false),
    'httponly' => (bool) config('session.httponly', true),
    'samesite' => (string) config('session.samesite', 'Lax'),
]);

// ---------------------------------------------------------------------------
// Security headers (baseline; the router may add a CSP nonce for inline views)
// ---------------------------------------------------------------------------
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; connect-src 'self' ws: wss:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
}
