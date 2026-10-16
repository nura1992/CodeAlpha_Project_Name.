<?php

use MedCore\Core\Router;
use MedCore\Modules\Api\PlatformApiController;

/**
 * Versioned JSON API routes (prefix /api/v1).
 *
 * These endpoints are session-authenticated (the browser fetch() sends
 * cookies) and protected by the same global CSRF check as web routes —
 * the front end sends the X-CSRF-TOKEN header on mutations.
 *
 * MediBridge (a later phase) will add credential/token-authenticated
 * external endpoints under this same versioned scheme.
 */

return function (Router $r): void {
    $r->get('/api/v1/me', [PlatformApiController::class, 'me'])->middleware('auth');
    $r->get('/api/v1/summary', [PlatformApiController::class, 'summary'])->middleware('auth');
};
