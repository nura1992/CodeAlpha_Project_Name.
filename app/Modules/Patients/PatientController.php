<?php

namespace MedCore\Modules\Patients;

use MedCore\Core\Auth;
use MedCore\Core\Authorization;
use MedCore\Core\Request;
use MedCore\Core\Response;
use MedCore\Core\Session;
use MedCore\Core\Validator;
use MedCore\Repositories\AppointmentRepository;
use MedCore\Repositories\OrganizationRepository;
use MedCore\Repositories\PatientRepository;
use MedCore\Services\AuditLogger;

/**
 * MediID patient registry.
 *
 * Every read/write goes through Authorization::canViewPatientRecord() or the
 * list scope — the clinical-data boundary lives here, not in the UI.
 */
final class PatientController
{
    public function index(Request $request): void
    {
        Authorization::authorize('patients.view');
        $page = max(1, (int) $request->input('page', 1));
        $search = trim((string) $request->input('q', ''));

        Response::view('patients/index', [
            'title'  => 'Patients',
            'result' => PatientRepository::paginate(
                $page,
                15,
                $search,
                Authorization::patientListScope((int) Auth::id())
            ),
            'search' => $search,
        ]);
    }

    public function create(): void
    {
        Authorization::authorize('patients.create');
        Response::view('patients/form', [
            'title'   => 'Register patient',
            'patient' => null,
            'orgs'    => $this->writableOrgs(),
        ]);
    }

    public function store(Request $request): void
    {
        Authorization::authorize('patients.create');
        $v = $this->validatePatient($request);
        if ($v->fails()) {
            Session::flashInput($request->all());
            Session::flashErrors($v->errors());
            Response::redirect('/patients/create');
            return;
        }
        $data = $v->validated();
        $orgId = (int) ($data['primary_organization_id'] ?? 0);
        if (!$this->canWriteToOrg($orgId)) {
            Response::abort(403, 'You cannot register patients for that organization.');
        }
        $data['created_by'] = Auth::id();
        $data['consent_recorded_at'] = $request->input('consent') ? date('Y-m-d H:i:s') : null;
        if ($data['consent_recorded_at'] === null) {
            Session::flashInput($request->all());
            Session::flashErrors(['consent' => ['Consent must be recorded before creating a health record.']]);
            Response::redirect('/patients/create');
            return;
        }
        $id = PatientRepository::create($data);
        AuditLogger::log(Auth::id(), 'patients.created', 'patient', $id, ['patient_code' => PatientRepository::generateCode($id)], $request);
        Session::flash('success', 'Patient registered: ' . PatientRepository::generateCode($id));
        Response::redirect('/patients/' . $id);
    }

    public function show(Request $request, string $id): void
    {
        $patient = PatientRepository::find((int) $id);
        if ($patient === null || !Authorization::canViewPatientRecord((int) Auth::id(), $patient)) {
            AuditLogger::log(Auth::id(), 'patients.access_denied', 'patient', (int) $id, null, $request);
            Response::abort(403, 'You are not authorized to view this patient record.');
        }

        // Audit every record VIEW — clinical access is always traceable.
        AuditLogger::log(Auth::id(), 'patients.viewed', 'patient', (int) $id, ['patient_code' => $patient['patient_code']], $request);

        $upcoming = AppointmentRepository::upcomingForPatient((int) $id, 10);
        $org = $patient['primary_organization_id']
            ? OrganizationRepository::find((int) $patient['primary_organization_id'])
            : null;

        Response::view('patients/show', [
            'title'    => $patient['first_name'] . ' ' . $patient['last_name'],
            'patient'  => $patient,
            'org'      => $org,
            'upcoming' => $upcoming,
            'canEdit'  => Authorization::can((int) Auth::id(), 'patients.update')
                          && $this->canWriteToOrg((int) ($patient['primary_organization_id'] ?? 0)),
        ]);
    }

    /** Patient role: shortcut to their own record. */
    public function mine(): void
    {
        $record = PatientRepository::findByUserId((int) Auth::id());
        if ($record === null) {
            Response::view('patients/no-record', ['title' => 'My health record']);
            return;
        }
        Response::redirect('/patients/' . (int) $record['id']);
    }

    public function edit(Request $request, string $id): void
    {
        Authorization::authorize('patients.update');
        $patient = PatientRepository::find((int) $id);
        if ($patient === null
            || !Authorization::canViewPatientRecord((int) Auth::id(), $patient)
            || !$this->canWriteToOrg((int) ($patient['primary_organization_id'] ?? 0))) {
            Response::abort(403);
        }
        Response::view('patients/form', [
            'title'   => 'Edit patient',
            'patient' => $patient,
            'orgs'    => $this->writableOrgs(),
        ]);
    }

    public function update(Request $request, string $id): void
    {
        Authorization::authorize('patients.update');
        $patient = PatientRepository::find((int) $id);
        if ($patient === null
            || !Authorization::canViewPatientRecord((int) Auth::id(), $patient)
            || !$this->canWriteToOrg((int) ($patient['primary_organization_id'] ?? 0))) {
            Response::abort(403);
        }
        $v = $this->validatePatient($request);
        if ($v->fails()) {
            Session::flashErrors($v->errors());
            Response::redirect('/patients/' . (int) $id . '/edit');
            return;
        }
        $data = $v->validated();
        $newOrg = (int) ($data['primary_organization_id'] ?? 0);
        if (!$this->canWriteToOrg($newOrg)) {
            Response::abort(403, 'You cannot transfer a record to that organization.');
        }
        PatientRepository::update((int) $id, $data);
        AuditLogger::log(Auth::id(), 'patients.updated', 'patient', (int) $id, ['patient_code' => $patient['patient_code']], $request);
        Session::flash('success', 'Patient record updated.');
        Response::redirect('/patients/' . (int) $id);
    }

    /* ------------------------------ helpers --------------------------- */

    private function validatePatient(Request $request): Validator
    {
        return Validator::make($request->all(), [
            'first_name'              => 'required|min:2|max:80',
            'last_name'               => 'required|min:2|max:80',
            'date_of_birth'           => 'date',
            'sex'                     => 'in:female,male,other',
            'phone'                   => 'phone|max:20',
            'blood_group'             => 'in:A+,A-,B+,B-,AB+,AB-,O+,O-',
            'allergies'               => 'max:500',
            'emergency_contact'       => 'max:160',
            'primary_organization_id' => 'required|integer',
        ]);
    }

    /** Orgs the current user may attach records to. */
    private function writableOrgs(): array
    {
        if (Authorization::hasRole((int) Auth::id(), 'super_admin')) {
            return OrganizationRepository::allActive();
        }
        $orgs = [];
        foreach (Authorization::organizationIdsOf((int) Auth::id()) as $orgId) {
            $org = OrganizationRepository::find($orgId);
            if ($org !== null && $org['status'] === 'approved') {
                $orgs[] = $org;
            }
        }
        return $orgs;
    }

    private function canWriteToOrg(int $orgId): bool
    {
        foreach ($this->writableOrgs() as $org) {
            if ((int) $org['id'] === $orgId) {
                return true;
            }
        }
        return false;
    }
}
