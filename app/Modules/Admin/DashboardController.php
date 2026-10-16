<?php

namespace MedCore\Modules\Admin;

use MedCore\Core\Auth;
use MedCore\Core\Authorization;
use MedCore\Core\Response;
use MedCore\Repositories\AppointmentRepository;
use MedCore\Repositories\AuditRepository;
use MedCore\Repositories\OrganizationRepository;
use MedCore\Repositories\PatientRepository;
use MedCore\Repositories\ProviderRepository;
use MedCore\Repositories\UserRepository;

/**
 * Main authenticated dashboard — widgets differ per role/permissions.
 */
final class DashboardController
{
    public function index(): void
    {
        $user = Auth::user();
        $userId = (int) $user['id'];
        $isStaff = !Authorization::hasRole($userId, 'patient');

        $data = [
            'title'           => 'Dashboard',
            'user'            => $user,
            'can'             => [
                'users'         => Authorization::can($userId, 'users.view'),
                'roles'         => Authorization::can($userId, 'roles.view'),
                'organizations' => Authorization::can($userId, 'organizations.view'),
                'patients'      => Authorization::can($userId, 'patients.view'),
                'providers'     => Authorization::can($userId, 'providers.view'),
                'appointments'  => Authorization::can($userId, 'appointments.view'),
                'audits'        => Authorization::can($userId, 'audits.view'),
            ],
            'stats'           => [],
            'today'           => [],
            'upcoming'        => [],
            'recentAudit'     => [],
            'myPatientRecord' => null,
        ];

        if ($isStaff) {
            $orgIds = Authorization::organizationIdsOf($userId);
            $data['stats'] = [
                'users'         => Authorization::can($userId, 'users.view') ? UserRepository::count() : null,
                'organizations' => Authorization::can($userId, 'organizations.view') ? OrganizationRepository::count() : null,
                'patients'      => Authorization::can($userId, 'patients.view') ? PatientRepository::count() : null,
                'providers'     => Authorization::can($userId, 'providers.view') ? ProviderRepository::count() : null,
                'today'         => Authorization::can($userId, 'appointments.view') ? AppointmentRepository::countToday() : null,
                'failed_logins' => Authorization::can($userId, 'audits.view') ? AuditRepository::failedLoginsSince(1440) : null,
            ];
            if (Authorization::can($userId, 'appointments.view')) {
                $data['today'] = AppointmentRepository::todayForOrgs($orgIds);
            }
            if (Authorization::can($userId, 'audits.view')) {
                $data['recentAudit'] = AuditRepository::latest(8);
            }
        } else {
            // Patient self-service dashboard: only their own record/appointments.
            $record = PatientRepository::findByUserId($userId);
            $data['myPatientRecord'] = $record;
            if ($record !== null) {
                $data['upcoming'] = AppointmentRepository::upcomingForPatient((int) $record['id']);
            }
        }

        Response::view('dashboard/index', $data);
    }
}
