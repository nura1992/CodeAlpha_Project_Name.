<?php
declare(strict_types=1);

/**
 * Application bootstrap: autoloading, configuration and global error handling.
 * Loaded by public/index.php and by every CLI script in bin/.
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = APP_ROOT . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use App\Core\Env;
use App\Core\ApiException;
use App\Core\Response;

Env::load(APP_ROOT . '/config/.env');

date_default_timezone_set('UTC');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Never leak stack traces to clients. Log them server-side instead.
set_exception_handler(static function (Throwable $e): void {
    error_log('[' . date('c') . '] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        Response::json(['error' => ['code' => 'SERVER_ERROR', 'message' => 'An unexpected error occurred.']], 500);
    }
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
});

// Harden sessions/cookies globally.
ini_set('session.use_strict_mode', '1');
