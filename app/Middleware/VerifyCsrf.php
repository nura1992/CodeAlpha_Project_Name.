<?php

namespace MedCore\Middleware;

use MedCore\Core\Csrf;
use MedCore\Core\Request;
use MedCore\Core\Response;
use MedCore\Core\Session;

/**
 * CSRF protection for every state-changing request (POST/PUT/PATCH/DELETE).
 * Applied globally inside the front controller.
 */
final class VerifyCsrf
{
    public static function handle(?Request $request = null): void
    {
        $request = $request ?? new Request();
        if (!Csrf::verify($request)) {
            if ($request->wantsJson()) {
                Response::json(['error' => 'CSRF token mismatch. Reload the page and try again.'], 419);
                throw new \MedCore\Core\HttpExit();
            }
            Session::flash('danger', 'Your session expired or the form was invalid. Please try again.');
            Response::back('/login');
            throw new \MedCore\Core\HttpExit();
        }
    }
}
