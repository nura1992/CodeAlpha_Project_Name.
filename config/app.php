<?php

return [
    'name'     => \MedCore\Core\Env::get('APP_NAME', 'MedCore Platform'),
    'brand'    => \MedCore\Core\Env::get('APP_BRAND', 'Medboat'),
    'env'      => \MedCore\Core\Env::get('APP_ENV', 'production'),
    'debug'    => \MedCore\Core\Env::bool('APP_DEBUG', false),
    'url'      => rtrim((string) \MedCore\Core\Env::get('APP_URL', 'http://localhost'), '/'),
    'timezone' => \MedCore\Core\Env::get('APP_TIMEZONE', 'Africa/Lagos'),

    'session' => [
        'name'             => 'medcore_session',
        'secure_cookie'    => \MedCore\Core\Env::bool('SESSION_SECURE_COOKIE', false),
        'idle_timeout'     => (int) \MedCore\Core\Env::get('SESSION_IDLE_TIMEOUT', 1800),
        'absolute_timeout' => (int) \MedCore\Core\Env::get('SESSION_ABSOLUTE_TIMEOUT', 43200),
    ],

    'auth' => [
        'require_verification' => \MedCore\Core\Env::bool('AUTH_REQUIRE_VERIFICATION', false),
        'password_min_length'  => 10,
        'max_login_attempts'   => 5,
        'lockout_minutes'      => 15,
    ],

    // Show password-reset / verification links on screen when no mailer exists.
    // Automatically disabled in production regardless of this flag.
    'dev_show_links' => \MedCore\Core\Env::bool('DEV_SHOW_LINKS', false),
];
