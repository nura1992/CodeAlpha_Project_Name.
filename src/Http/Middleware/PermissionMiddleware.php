<?php
/**
 * PermissionMiddleware — requires a specific RBAC permission, checked
 * server-side on every request (never merely hidden in the UI).
 */

declare(strict_types=1);

namespace CodeAlpha\Http\Middleware;

use CodeAlpha\Core\Auth;
use CodeAlpha\Core\Request;
use CodeAlpha\Core\Response;
use CodeAlpha\Services\RbacService;

final class PermissionMiddleware
{
    public function __construct(private string $permission)
    {
    }

    public function handle(callable $next)
    {
        $request = new Request();
        if (!Auth::check()) {
            if ($request->wantsJson()) {
                Response::error('Authentication required.', 401, ['code' => 'unauthenticated']);
            }
            Response::redirect('/staff/login?redirect=' . urlencode($request->path()));
        }
        if (!RbacService::can((int) Auth::id(), $this->permission)) {
            \CodeAlpha\Core\AuditLog::record(
                Auth::id(),
                'rbac.denied',
                'permission',
                $this->permission,
                ['path' => $request->path(), 'method' => $request->method()],
                $request
            );
            if ($request->wantsJson()) {
                Response::error('You do not have permission to perform this action.', 403, ['code' => 'forbidden', 'permission' => $this->permission]);
            }
            Response::forbidden('You do not have permission to access this page.');
        }
        return $next();
    }
}
