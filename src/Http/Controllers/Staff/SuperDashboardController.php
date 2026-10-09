<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers\Staff;

use CodeAlpha\Http\Controllers\Controller;
use CodeAlpha\Services\AnalyticsService;

final class SuperDashboardController extends Controller
{
    public function index(): never
    {
        $this->view('staff/super/dashboard', [
            'overview'=> AnalyticsService::overview(),
            'signups'=> AnalyticsService::signupsPerDay(14),
            'revenue'=> AnalyticsService::revenuePerDay(14),
        ]);
    }
}
