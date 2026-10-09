<?php
/**
 * CodeAlpha configuration.
 *
 * Every value can be overridden with an environment variable, which makes the
 * same codebase deployable to shared hosting, VPS and container environments.
 * Copy this file to config/config.local.php (git-ignored) to override values
 * without touching the tracked file.
 */

$env = static function (string $key, $default = null) {
    $value = getenv($key);
    if ($value === false || $value === '') {
        $value = $_ENV[$key] ?? null;
    }
    return ($value === null || $value === '') ? $default : $value;
};

$root = dirname(__DIR__);

$config = [
    'app' => [
        'name'        => $env('APP_NAME', 'CodeAlpha'),
        'env'         => $env('APP_ENV', 'production'),          // production|development
        'debug'       => filter_var($env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN),
        'url'         => rtrim($env('APP_URL', 'http://localhost'), '/'),
        // 32+ random bytes, base64 or hex encoded. Used for media signing and credential encryption.
        'key'         => $env('APP_KEY', 'base64:CHANGE_ME_TO_A_32_BYTE_RANDOM_KEY!!'),
        'timezone'    => $env('APP_TIMEZONE', 'UTC'),
    ],

    'db' => [
        // 'mysql' is the production driver. 'sqlite' is used for local
        // development and the bundled WASM dev server (no MySQL required).
        'driver'   => $env('DB_DRIVER', 'sqlite'),
        'host'     => $env('DB_HOST', '127.0.0.1'),
        'port'     => (int) $env('DB_PORT', 3306),
        'name'     => $env('DB_NAME', 'codealpha'),
        'user'     => $env('DB_USER', 'root'),
        'password' => $env('DB_PASSWORD', ''),
        'charset'  => 'utf8mb4',
        // Path used when driver = sqlite
        'sqlite_path' => $env('DB_SQLITE_PATH', $root . '/storage/database.sqlite'),
    ],

    'session' => [
        'lifetime'    => (int) $env('SESSION_LIFETIME', 120), // minutes
        'path'        => $root . '/storage/sessions',
        'name'        => 'codealpha_session',
        'secure'      => filter_var($env('SESSION_SECURE', 'false'), FILTER_VALIDATE_BOOLEAN),
        'httponly'    => true,
        'samesite'    => 'Lax',
    ],

    'storage' => [
        'root'              => $root . '/storage',
        'protected'         => $root . '/storage/protected',
        'protected_verifications' => $root . '/storage/protected/verifications',
        'protected_chat'    => $root . '/storage/protected/chat',
        'uploads'           => $root . '/public/uploads',
        'logs'              => $root . '/storage/logs',
    ],

    // Verification media security
    'verification' => [
        'retention_days'  => (int) $env('VERIFICATION_RETENTION_DAYS', 90),
        'max_size_mb'     => (int) $env('VERIFICATION_MAX_MB', 25),
        'signed_url_ttl'  => (int) $env('SIGNED_URL_TTL', 300), // seconds
    ],

    // Mail transport: log|smtp
    'mail' => [
        'transport' => $env('MAIL_TRANSPORT', 'log'),
        'from'      => $env('MAIL_FROM', 'no-reply@codealpha.local'),
        'from_name' => $env('MAIL_FROM_NAME', 'CodeAlpha'),
        'smtp'      => [
            'host'     => $env('SMTP_HOST', ''),
            'port'     => (int) $env('SMTP_PORT', 587),
            'username' => $env('SMTP_USER', ''),
            'password' => $env('SMTP_PASS', ''),
            'encryption' => $env('SMTP_ENCRYPTION', 'tls'),
        ],
    ],

    // SMS transport: log|http (generic HTTP API, e.g. Termii-compatible)
    'sms' => [
        'transport' => $env('SMS_TRANSPORT', 'log'),
        'http'      => [
            'url'      => $env('SMS_API_URL', ''),
            'token'    => $env('SMS_API_TOKEN', ''),
            'sender'   => $env('SMS_SENDER', 'CodeAlpha'),
        ],
    ],

    // Payments: when true, gateways run in a simulated mode so the full
    // checkout → verify → subscription flow can be exercised without network.
    // Defaults to true for development (no network required); set PAYMENT_MOCK=false in production.
    'payments' => [
        'mock'     => filter_var($env('PAYMENT_MOCK', 'true'), FILTER_VALIDATE_BOOLEAN),
        'currency' => $env('PAYMENT_CURRENCY', 'NGN'),
    ],

    // WebRTC calling
    'calling' => [
        'signaling_url' => $env('SIGNALING_URL', 'ws://localhost:8090'),
        'stun_servers'  => $env('STUN_SERVERS', 'stun:stun.l.google.com:19302'),
        'turn_server'   => $env('TURN_SERVER', ''),
        'turn_username' => $env('TURN_USERNAME', ''),
        'turn_password' => $env('TURN_PASSWORD', ''),
    ],

    // Web Push (VAPID). Configured by the Super Admin in Settings.
    'push' => [
        'vapid_public'  => $env('VAPID_PUBLIC_KEY', ''),
        'vapid_private' => $env('VAPID_PRIVATE_KEY', ''),
        'subject'       => $env('VAPID_SUBJECT', 'mailto:admin@codealpha.local'),
    ],
];

// Optional local overrides (git-ignored).
$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $overrides = require $local;
    if (is_array($overrides)) {
        $config = array_replace_recursive($config, $overrides);
    }
}

return $config;
