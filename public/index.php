<?php
/**
 * CodeAlpha front controller. All non-file requests are routed here
 * (see public/.htaccess for Apache; use `php -S localhost:8000 -t public`
 * for the built-in server).
 */

declare(strict_types=1);

// Serve real files (css/js/images/uploads) directly when using the PHP
// built-in server; Apache/nginx handle this natively via .htaccess / config.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $file = __DIR__ . $path;
    if ($path !== '/' && is_file($file)) {
        return false; // Let the built-in server serve the static file.
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/Core/helpers.php';

\CodeAlpha\Core\App::run();
