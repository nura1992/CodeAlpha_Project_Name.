<?php

namespace MedCore\Modules\Appointments;

use MedCore\Core\Auth;
use MedCore\Core\Authorization;
use MedCore\Core\Request;
use MedCore\Core\Response;
use MedCore\Core\Session;
use MedCore\Core\Validator;
use MedCore\Repositories\AppointmentRepository;
use MedCore\Repositories\PatientRepository;
use MedCore\Repositories\ProviderRepository;
use MedCore\Services\AuditLogger;
use MedCore\Services\NotificationService;

/**
 * MediLink appointment booking and workflow (booked -> confirmed ->
 * completed, or rescheduled / cancelled / declined / no_show).
 */
final class AppointmentController
{
    public function index(Request $request): void
    {
        Authorization::authorize('appointments.view');
        $page = max(1, (int) $request->input('page', 1));
        $status = trim((string) $request->input('status', ''));
        $date = trim((string) $request->input('date', ''));

        Response::view('appointments/index', [
            'title'    => 'Appointments',
            'result'   => AppointmentRepository::paginate($page, 15, $this->scope(), $status, $date),
            'status'   => $status,
            'date'     => $date,
            'statuses' => AppointmentRepository::STATUSES,
            'canUpdate' => Authorization::can((int) Auth::id(), 'appointments.update'),
        ]);
    }

    public function create(Request $request): void
    {
        Authorization::authorize('appointments.create');
        $myRecord = PatientRepository::findByUserId((int) Auth::id());
        Response::view('appointments/form', [
            'title'     => 'Book appointment',
            'patients'  => $this->bookablePatients(),
            'providers' => $this->visibleProviders(),
            'prefillPatient' => (int) $request->input('patient_id', 0) ?: null,
            'myPatientId' => $myRecord['id'] ?? null,
        ]);
    }

    public function store(Request $request): void
    {
        Authorization::authorize('appointments.create');
        $v = Validator::make($request->all(), [
            'patient_id'   => 'required|integer',
            'provider_id'  => 'required|integer',
            'scheduled_at' => 'required|datetime',
            'duration_min' => 'integer',
            'reason'       => 'max:500',
        ]);
        if ($v->fails()) {
            Session::flashInput($request->all());
            Session::flashErrors($v->errors());
            Response::redirect('/appointments/create');
            return;
        }

        $patient = PatientRepository::find((int) $request->input('patient_id'));
        if ($patient === null || !Authorization::canViewPatientRecord((int) Auth::id(), $patient)) {
            Response::abort(403, 'You cannot book appointments for that patient.');
        }

        $provider = ProviderRepository::find((int) $request->input('provider_id'));
        if ($provider === null || $provider['status'] !== 'active' || !$this->providerVisible($provider)) {
            Session::flash('danger', 'The selected provider is not available.');
            Response::redirect('/appointments/create');
            return;
        }

        $ts = strtotime((string) $request->input('scheduled_at'));
        if ($ts === false || $ts < time() - 300) {
            Session::flashInput($request->all());
            Session::flashErrors(['scheduled_at' => ['Choose a future date and time.']]);
            Response::redirect('/appointments/create');
            return;
        }

        $duration = (int) $request->input('duration_min', 30);
        $duration = in_array($duration, [15, 30, 45, 60, 90, 120], true) ? $duration : 30;

        $id = AppointmentRepository::create([
            'patient_id'      => (int) $patient['id'],
            'provider_id'     => (int) $provider['id'],
            'organization_id' => (int) $provider['organization_id'],
            'scheduled_at'    => date('Y-m-d H:i:s', $ts),
            'duration_min'    => $duration,
            'status'          => 'booked',
            'reason'          => trim((string) $request->input('reason', '')) ?: null,
            'created_by'      => Auth::id(),
        ]);

        AuditLogger::log(Auth::id(), 'appointments.booked', 'appointment', $id, [
            'patient' => $patient['patient_code'],
            'provider' => $provider['full_name'],
        ], $request);

        // Notify the linked patient account, when there is one.
        if (!empty($patient['user_id'])) {
            NotificationService::send(
                (int) $patient['user_id'],
                'Appointment booked',
                sprintf('%s with %s on %s.', $provider['specialty'], $provider['full_name'], date('D j M Y, g:ia', $ts)),
                'info',
                '/my/appointments'
            );
        }

        Session::flash('success', 'Appointment booked.');
        Response::redirect('/appointments');
    }

    /** POST /appointments/{id}/status — workflow transitions. */
    public function setStatus(Request $request, string $id): void
    {
        $appointment = AppointmentRepository::find((int) $id);
        if ($appointment === null || !$this->canTouch($appointment)) {
            Response::abort(403);
        }
        $target = (string) $request->input('status', '');
        $allowed = $this->allowedTransitions((string) $appointment['status']);
        if (!in_array($target, $allowed, true)) {
            Session::flash('danger', 'That status change is not allowed from ' . $appointment['status'] . '.');
            Response::redirect('/appointments');
            return;
        }
        $perm = in_array($target, ['cancelled'], true) ? 'appointments.cancel' : 'appointments.update';
        if (!Authorization::can((int) Auth::id(), $perm)) {
            Response::abort(403);
        }
        AppointmentRepository::setStatus((int) $id, $target);
        AuditLogger::log(Auth::id(), 'appointments.status_changed', 'appointment', (int) $id, [
            'from' => $appointment['status'],
            'to'   => $target,
        ], $request);

        $patient = PatientRepository::find((int) $appointment['patient_id']);
        if ($patient !== null && !empty($patient['user_id']) && (int) $patient['user_id'] !== (int) Auth::id()) {
            NotificationService::send(
                (int) $patient['user_id'],
                'Appointment ' . str_replace('_', ' ', $target),
                sprintf('Your appointment %s with %s is now %s.', $appointment['reference'], $appointment['provider_name'], str_replace('_', ' ', $target)),
                $target === 'cancelled' || $target === 'declined' ? 'warning' : 'info',
                '/appointments'
            );
        }

        Session::flash('success', 'Appointment ' . $appointment['reference'] . ' marked as ' . str_replace('_', ' ', $target) . '.');
        Response::redirect('/appointments');
    }

    /* ------------------------------ helpers --------------------------- */

    /** Who can view which appointments (list scope). */
    private function scope(): array
    {
        $userId = (int) Auth::id();
        if (Authorization::hasRole($userId, 'patient')) {
            $record = PatientRepository::findByUserId($userId);
            return ['a.patient_id = ?', [$record['id'] ?? -1]];
        }
        if (Authorization::hasRole($userId, 'super_admin')) {
            return ['1=1', []];
        }
        $orgIds = Authorization::organizationIdsOf($userId);
        if ($orgIds === []) {
            return ['1=0', []];
        }
        return ['a.organization_id IN (' . implode(',', array_fill(0, count($orgIds), '?')) . ')', $orgIds];
    }

    /** May the current user change this appointment's workflow state? */
    private function canTouch(array $appointment): bool
    {
        $userId = (int) Auth::id();
        if (Authorization::hasRole($userId, 'super_admin')) {
            return Authorization::can($userId, 'appointments.update');
        }
        return in_array((int) $appointment['organization_id'], Authorization::organizationIdsOf($userId), true);
    }

    private function allowedTransitions(string $from): array
    {
        return match ($from) {
            'booked'       => ['confirmed', 'cancelled', 'declined'],
            'confirmed'    => ['completed', 'cancelled', 'no_show', 'rescheduled'],
            'rescheduled'  => ['confirmed', 'cancelled'],
            default        => [],
        };
    }

    private function visibleProviders(): array
    {
        if (Authorization::hasRole((int) Auth::id(), 'patient')) {
            // Patients may book any active provider (public directory).
            return ProviderRepository::activeByOrganization(null);
        }
        if (Authorization::hasRole((int) Auth::id(), 'super_admin')) {
            return ProviderRepository::activeByOrganization(null);
        }
        return ProviderRepository::activeByOrganization(Authorization::organizationIdsOf((int) Auth::id()));
    }

    private function providerVisible(array $provider): bool
    {
        if (Authorization::hasRole((int) Auth::id(), 'patient')
            || Authorization::hasRole((int) Auth::id(), 'super_admin')) {
            return true;
        }
        return in_array((int) $provider['organization_id'], Authorization::organizationIdsOf((int) Auth::id()), true);
    }

    /** Patients selectable in the booking form (staff: their orgs' records). */
    private function bookablePatients(): ?array
    {
        $userId = (int) Auth::id();
        if (Authorization::hasRole($userId, 'patient')) {
            return null; // fixed to self in the form
        }
        if (!Authorization::can($userId, 'patients.view')) {
            return [];
        }
        $scope = Authorization::patientListScope($userId);
        [$sql, $params] = $scope;
        return \MedCore\Core\Database::select(
            "SELECT p.id, p.patient_code, p.first_name, p.last_name FROM patients p WHERE p.status = 'active' AND $sql ORDER BY p.last_name, p.first_name LIMIT 500",
            $params
        );
    }
}
