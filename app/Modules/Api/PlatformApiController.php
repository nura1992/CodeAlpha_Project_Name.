<?php

namespace MedCore\Modules\Api;

use MedCore\Core\Auth;
use MedCore\Core\Authorization;
use MedCore\Core\Request;
use MedCore\Core\Response;
use MedCore\Repositories\AppointmentRepository;
use MedCore\Repositories\UserRepository;
use MedCore\Services\NotificationService;

/**
 * Versioned JSON API (v1) used by the dashboard front end via fetch().
 * Session-authenticated + CSRF header for mutations, same as web routes.
 */
final class PlatformApiController
{
    /** GET /api/v1/me — current identity, roles and permissions. */
    public function me(): void
    {
        $user = Auth::user();
        Response::json([
            'data' => [
                'id'          => (int) $user['id'],
                'name'        => $user['name'],
                'email'       => $user['email'],
                'roles'       => Authorization::rolesOf((int) $user['id']),
                'permissions' => Authorization::permissionsOf((int) $user['id']),
            ],
        ]);
    }

    /** GET /api/v1/summary — counters + chart series for the dashboard. */
    public function summary(): void
    {
        $userId = (int) Auth::id();

        // Per-day appointments for the last 14 days (line/bar chart series).
        $daily = AppointmentRepository::dailyCounts(14);
        $series = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-$i days"));
            $series[] = ['day' => $day, 'label' => date('D', strtotime($day)), 'count' => $daily[$day] ?? 0];
        }

        $counts = [];
        if (Authorization::can($userId, 'patients.view')) {
            $counts['patients'] = \MedCore\Repositories\PatientRepository::count();
        }
        if (Authorization::can($userId, 'providers.view')) {
            $counts['providers'] = \MedCore\Repositories\ProviderRepository::count();
        }
        if (Authorization::can($userId, 'users.view')) {
            $counts['users'] = UserRepository::count();
        }
        if (Authorization::can($userId, 'appointments.view')) {
            $counts['appointments_today'] = AppointmentRepository::countToday();
        }

        Response::json([
            'data' => [
                'counts'           => $counts,
                'appointments'     => $series,
                'unread_notifications' => NotificationService::unreadCount($userId),
            ],
        ]);
    }
}
