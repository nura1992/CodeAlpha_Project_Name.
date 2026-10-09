<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers\Staff;

use CodeAlpha\Http\Controllers\Controller;
use CodeAlpha\Services\AnalyticsService;

final class SuperAnalyticsController extends Controller
{
    public function index(): never
    {
        $this->view('staff/super/analytics', [
            'overview'=>AnalyticsService::overview(),
            'signups'=>AnalyticsService::signupsPerDay(30),
            'revenue'=>AnalyticsService::revenuePerDay(30),
            'gender'=>AnalyticsService::genderDistribution(),
            'funnel'=>AnalyticsService::verificationFunnel(),
        ]);
    }
}
