<?php

return [
    // mysql (production / XAMPP) or sqlite (zero-config local demo)
    'driver' => \MedCore\Core\Env::get('DB_DRIVER', 'mysql'),

    'mysql' => [
        'host'    => \MedCore\Core\Env::get('DB_HOST', '127.0.0.1'),
        'port'    => (int) \MedCore\Core\Env::get('DB_PORT', 3306),
        'name'    => \MedCore\Core\Env::get('DB_NAME', 'medcore_db'),
        'user'    => \MedCore\Core\Env::get('DB_USER', 'root'),
        'pass'    => \MedCore\Core\Env::get('DB_PASS', ''),
        'charset' => \MedCore\Core\Env::get('DB_CHARSET', 'utf8mb4'),
    ],

    'sqlite' => [
        'path' => \MedCore\Core\Env::get('SQLITE_PATH', 'storage/database/medcore.sqlite'),
    ],
];
