<?php
/**
 * VerifiedMiddleware — requires the member's profile to be approved.
 *
 * Profile visibility defaults to denied: unverified or pending members cannot
 * enter discovery, receive full profile details, message, or call. They can
 * still use permitted community features.
 */

declare(strict_types=1);

namespace CodeAlpha\Http\Middleware;

use CodeAlpha\Core\Auth;
use CodeAlpha\Core\Request;
use CodeAlpha\Core\Response;

final class VerifiedMiddleware
{
    public function handle(callable $next)
    {
        $user = Auth::user();
        $request = new Request();
        if (!$user) {
            Response::error('Authentication required.', 401, ['code' => 'unauthenticated']);
        }
        $status = (string) ($user['verification_status'] ?? 'unverified');
        if ($status !== 'approved') {
            if ($request->wantsJson()) {
                Response::error(
                    'Your profile must be approved before you can use matchmaking. ' .
                    ($status === 'pending'
                        ? 'Your verification is under review.'
                        : ($status === 'rejected'
                            ? 'Your verification was rejected. Please resubmit.'
                            : 'Please complete profile verification.')),
                    403,
                    ['code' => 'not_verified', 'verification_status' => $status]
                );
            }
            Response::redirect('/verify');
        }
        return $next();
    }
}
