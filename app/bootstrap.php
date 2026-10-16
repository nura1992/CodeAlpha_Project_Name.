<?php

declare(strict_types=1);

/*
 * Application bootstrap: autoloading, environment, config, error handling,
 * timezone and secure session defaults.
 */

use MedCore\Core\Config;
use MedCore\Core\Database;
use MedCore\Core\Env;

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('PUBLIC_PATH', BASE_PATH . '/public');
define('STORAGE_PATH', BASE_PATH . '/storage');

/* ------------------------------ autoload ------------------------------ */
spl_autoload_register(function (string $class): void {
    $prefix = 'MedCore\\';
    if (str_starts_with($class, $prefix)) {
        $path = APP_PATH . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

require APP_PATH . '/helpers.php';

/* ---------------------------- environment ---------------------------- */
Env::load(BASE_PATH . '/.env');
Config::load(BASE_PATH . '/config');

date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));

/* --------------------------- error handling -------------------------- */
$debug = (bool) Config::get('app.debug', false);
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', STORAGE_PATH . '/logs/php-error.log');

set_exception_handler(function (Throwable $e) use ($debug): void {
    if ($e instanceof \MedCore\Core\HttpExit) {
        return; // normal request finalization — not an error
    }
    error_log('MedCore exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (http_response_code() === 200) {
        http_response_code(500);
    }
    if ($debug) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "MedCore error (debug mode)\n\n";
        echo get_class($e) . ': ' . $e->getMessage() . "\n";
        echo $e->getFile() . ':' . $e->getLine() . "\n\n";
        echo $e->getTraceAsString();
    } else {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        echo \MedCore\Core\View::render('errors/error', [
            'status'  => 500,
            'title'   => 'Something went wrong',
            'message' => 'An unexpected error occurred. The incident has been logged.',
        ], 'auth');
    }
    exit;
});

/* ------------------------------ database ----------------------------- */
Database::boot();
