<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers\Staff;

use CodeAlpha\Core\Database;
use CodeAlpha\Http\Controllers\Controller;

final class AdminDashboardController extends Controller
{
    public function index(): never
    {
        $this->view('staff/admin/dashboard', [
            'users_total'=>(int)Database::scalar('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL'),
            'pending_verification'=>(int)Database::scalar("SELECT COUNT(*) FROM verification_requests WHERE status IN ('submitted','under_review')"),
            'open_reports'=>(int)Database::scalar("SELECT COUNT(*) FROM user_reports WHERE status IN ('open','in_review')"),
            'posts_today'=>(int)Database::scalar("SELECT COUNT(*) FROM community_posts WHERE created_at >= :d",['d'=>date('Y-m-d 00:00:00')]),
        ]);
    }
}
