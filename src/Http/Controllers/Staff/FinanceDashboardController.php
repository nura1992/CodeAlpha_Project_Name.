<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers\Staff;

use CodeAlpha\Core\Database;
use CodeAlpha\Http\Controllers\Controller;
use CodeAlpha\Services\PaymentService;

final class FinanceDashboardController extends Controller
{
    public function index(): never
    {
        $summary=PaymentService::reconciliationSummary();
        $recent=PaymentService::searchTransactions([],20);
        $activeSubs=(int)Database::scalar("SELECT COUNT(*) FROM subscriptions WHERE status='active'");
        $this->view('staff/finance/dashboard', ['summary'=>$summary,'recent'=>$recent,'activeSubs'=>$activeSubs]);
    }
    public function subscriptions(): never
    {
        $subs=Database::all('SELECT s.*, u.email, p.name as plan_name FROM subscriptions s JOIN users u ON u.id=s.user_id JOIN subscription_plans p ON p.id=s.plan_id ORDER BY s.created_at DESC LIMIT 100');
        $this->view('staff/finance/subscriptions', ['subs'=>$subs]);
    }
}
