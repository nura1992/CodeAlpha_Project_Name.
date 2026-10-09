<?php
/**
 * AuthMiddleware — requires an authenticated, active member session.
 */

declare(strict_types=1);

namespace CodeAlpha\Http\Middleware;

use CodeAlpha\Core\Auth;
use CodeAlpha\Core\Response;

final class AuthMiddleware
{
    public function handle(callable $next)
    {
        Auth::startSession();
        if (!Auth::check()) {
            $request = new \CodeAlpha\Core\Request();
            if ($request->wantsJson()) {
                Response::error('Authentication required.', 401, ['code' => 'unauthenticated']);
            }
            $target = $request->path();
            Response::redirect('/login?redirect=' . urlencode($target));
        }
        if (Auth::isSuspended()) {
            Response::error('This account has been suspended. Please contact support.', 403, ['code' => 'suspended']);
        }
        return $next();
    }
}
