<?php
/**
 * StaffMiddleware — requires an authenticated staff member.
 *
 * Optionally takes a dashboard area argument ('super_admin', 'admin',
 * 'finance', 'support') which requires the staff member to hold a role
 * attached to that dashboard — unless they are a Super Admin.
 */

declare(strict_types=1);

namespace CodeAlpha\Http\Middleware;

use CodeAlpha\Core\Auth;
use CodeAlpha\Core\Database;
use CodeAlpha\Core\Request;
use CodeAlpha\Core\Response;
use CodeAlpha\Services\RbacService;

final class StaffMiddleware
{
    public function __construct(private ?string $dashboard = null)
    {
    }

    public function handle(callable $next)
    {
        Auth::startSession();
        $request = new Request();

        if (!Auth::check() || !Auth::isStaff()) {
            if ($request->wantsJson()) {
                Response::error('Staff authentication required.', 401, ['code' => 'unauthenticated']);
            }
            Response::redirect('/staff/login?redirect=' . urlencode($request->path()));
        }
        if (Auth::isSuspended()) {
            Response::error('This account has been suspended.', 403, ['code' => 'suspended']);
        }

        $userId = (int) Auth::id();

        // Super Admins bypass dashboard scoping.
        if (!RbacService::isSuperAdmin($userId)) {
            if ($this->dashboard !== null) {
                $hasDashboard = Database::scalar(
                    'SELECT COUNT(*) FROM user_roles ur
                       JOIN roles r ON r.id = ur.role_id
                      WHERE ur.user_id = :uid AND r.dashboard = :dash',
                    ['uid' => $userId, 'dash' => $this->dashboard]
                );
                if (!$hasDashboard) {
                    if ($request->wantsJson()) {
                        Response::error('You do not have access to this staff area.', 403, ['code' => 'forbidden']);
                    }
                    Response::redirect('/staff');
                }
            }
        }

        return $next();
    }
}
