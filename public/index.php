<?php

/**
 * MedCore front controller.
 *
 * ALL HTTP requests enter here (web pages, JSON API, form posts).
 * The web server document root must be this public/ directory —
 * app/, config/, storage/ and database/ stay outside the web root.
 */

use MedCore\Core\Csrf;
use MedCore\Core\Request;
use MedCore\Core\Router;
use MedCore\Core\Session;
use MedCore\Core\View;
use MedCore\Middleware\VerifyCsrf;
use MedCore\Services\NotificationService;

require dirname(__DIR__) . '/app/bootstrap.php';

/* ---------------------- security response headers --------------------- */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");

/* ------------------------------- session ------------------------------ */
Session::start();

$request = Request::capture();

/* ------------------------------ view state ---------------------------- */
$currentUser = \MedCore\Core\Auth::user();
View::share('currentUser', $currentUser);
View::share('flashMessages', Session::pullFlash());
View::share('csrfToken', Csrf::token());
View::share('currentPath', $request->path());
View::share(
    'unreadNotifications',
    $currentUser ? NotificationService::unreadCount((int) $currentUser['id']) : 0
);
Session::pullOld(); // old input is read once per request

/* -------------------------------- routes ------------------------------ */
$router = new Router();
(require BASE_PATH . '/routes/web.php')($router);
(require BASE_PATH . '/routes/api.php')($router);

try {
    /* --------------------------- CSRF shield ------------------------- */
    // Every state-changing request (web or API) must carry a valid token,
    // either as the _token form field or the X-CSRF-TOKEN header.
    if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        VerifyCsrf::handle($request);
    }
    $router->dispatch($request);
} catch (\MedCore\Core\HttpExit $e) {
    // A response (redirect/abort/json/self-contained view) finalized the request.
}
