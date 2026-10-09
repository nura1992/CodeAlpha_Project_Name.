<?php
declare(strict_types=1);

/**
 * Front controller. Only this directory is web-accessible. All JSON API calls go to
 * /api/* and are dispatched by the router; every other path serves the single-page app shell.
 */
require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(self), microphone=(self), geolocation=()');
header('Cross-Origin-Opener-Policy: same-origin');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (str_starts_with($path, '/api/') || $path === '/api') {
    try {
        $request = Request::capture();
        $router = require APP_ROOT . '/src/routes.php';
        $router->dispatch($request);
    } catch (ApiException $e) {
        Response::error($e);
    }
    exit;
}

// Single-page application shell for every other route (e.g. /share/{token}).
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; connect-src 'self' ws: wss:; frame-ancestors 'none'; base-uri 'self'; form-action 'self' https://checkout.paystack.com https://checkout.flutterwave.com");
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
readfile(__DIR__ . '/index.html');
