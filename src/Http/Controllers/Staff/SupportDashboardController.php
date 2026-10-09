<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers\Staff;

use CodeAlpha\Core\Database;
use CodeAlpha\Http\Controllers\Controller;

final class SupportDashboardController extends Controller
{
    public function index(): never
    {
        $this->view('staff/support/dashboard', [
            'open'=>(int)Database::scalar("SELECT COUNT(*) FROM support_tickets WHERE status='open'"),
            'in_progress'=>(int)Database::scalar("SELECT COUNT(*) FROM support_tickets WHERE status='in_progress'"),
            'appeals'=>(int)Database::scalar("SELECT COUNT(*) FROM support_tickets WHERE subject LIKE '[Appeal]%' AND status IN ('open','in_progress')"),
        ]);
    }
}
