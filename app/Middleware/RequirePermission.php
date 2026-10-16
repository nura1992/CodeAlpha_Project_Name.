<?php

namespace MedCore\Middleware;

use MedCore\Core\Auth;
use MedCore\Core\Authorization;
use MedCore\Core\Request;
use MedCore\Core\Response;

/**
 * Authorization gate: user must hold the given permission slug.
 */
final class RequirePermission
{
    public static function handle(string $permission, Request $request): void
    {
        if (!Auth::check()) {
            if ($request->wantsJson()) {
                Response::json(['error' => 'Unauthenticated'], 401);
                throw new \MedCore\Core\HttpExit();
            }
            Response::redirect('/login');
            throw new \MedCore\Core\HttpExit();
        }
        if (!Authorization::can(Auth::id(), $permission)) {
            if ($request->wantsJson()) {
                Response::json(['error' => 'Forbidden', 'permission' => $permission], 403);
                throw new \MedCore\Core\HttpExit();
            }
            Response::abort(403, 'You do not have the required permission: ' . $permission);
        }
    }
}
