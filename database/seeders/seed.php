<?php

declare(strict_types=1);

/**
 * MedCore seeder — SYNTHETIC DEMO DATA ONLY.
 *
 *   php scripts/seed.php
 *
 * Never seed real patient data. Every record below is fictional and exists
 * so each role can be explored from a fresh install.
 */

use MedCore\Core\Database;
use MedCore\Repositories\AppointmentRepository;
use MedCore\Repositories\OrganizationRepository;
use MedCore\Repositories\PatientRepository;
use MedCore\Repositories\ProviderRepository;
use MedCore\Repositories\RoleRepository;
use MedCore\Repositories\UserRepository;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}

if (!Database::tableExists('users')) {
    fwrite(STDERR, "Run migrations first: php scripts/migrate.php\n");
    exit(1);
}

if (UserRepository::count() > 0) {
    fwrite(STDOUT, "Database already contains users — skipping seed.\n");
    exit(0);
}

fwrite(STDOUT, "Seeding MedCore demo data (all synthetic)...\n");

/* ------------------------------ permissions --------------------------- */
$permissions = [
    // slug => [name, group]
    'users.view'          => ['View users', 'Users & access'],
    'users.create'        => ['Create users', 'Users & access'],
    'users.update'        => ['Update users', 'Users & access'],
    'users.deactivate'    => ['Suspend/deactivate users', 'Users & access'],
    'roles.view'          => ['View roles', 'Users & access'],
    'roles.permissions'   => ['Edit role permissions', 'Users & access'],
    'organizations.view'  => ['View organizations', 'Organizations'],
    'organizations.create' => ['Register organizations', 'Organizations'],
    'organizations.update' => ['Update organizations & members', 'Organizations'],
    'organizations.deactivate' => ['Suspend organizations', 'Organizations'],
    'patients.view'       => ['View patient records (requires care relationship)', 'MediID records'],
    'patients.create'     => ['Register patients', 'MediID records'],
    'patients.update'     => ['Update patient records', 'MediID records'],
    'providers.view'      => ['View providers', 'MediLink care'],
    'providers.create'    => ['Add providers', 'MediLink care'],
    'providers.update'    => ['Update providers', 'MediLink care'],
    'appointments.view'   => ['View appointments', 'MediLink care'],
    'appointments.create' => ['Book appointments', 'MediLink care'],
    'appointments.update' => ['Confirm/complete appointments', 'MediLink care'],
    'appointments.cancel' => ['Cancel appointments', 'MediLink care'],
    'audits.view'         => ['View audit logs', 'Compliance'],
    'settings.manage'     => ['Manage platform settings', 'Platform'],
];

$permIds = [];
foreach ($permissions as $slug => [$name, $grp]) {
    $permIds[$slug] = (int) Database::insert('permissions', ['slug' => $slug, 'name' => $name, 'grp' => $grp]);
}

/* -------------------------------- roles ------------------------------- */
$roleDefs = [
    'super_admin'        => ['Super administrator', 'Platform configuration, organizations, roles, monitoring. No clinical-record access by default.'],
    'org_admin'          => ['Organization administrator', 'Runs one approved hospital, clinic, laboratory or pharmacy.'],
    'doctor'             => ['Doctor / clinician', 'Assigned patients, appointments, encounters and care plans.'],
    'nurse'              => ['Nurse / health worker', 'Assigned care tasks and permitted clinical records.'],
    'lab_staff'          => ['Laboratory staff', 'Orders, specimen workflow, results and verification.'],
    'pharmacist'         => ['Pharmacist', 'Prescriptions, dispensing, stock and fulfillment.'],
    'patient'            => ['Patient', 'Own profile, appointments, records, bills and receipts.'],
    'finance_officer'    => ['Finance officer', 'Billing, reconciliations and refunds (MediFund, later phase).'],
    'integration_admin'  => ['Integration administrator', 'API credentials, integrations and delivery logs (MediBridge, later).'],
    'analytics_user'     => ['AI / analytics user', 'Approved aggregate reporting and decision support (MedIntellect, later).'],
    'auditor'            => ['Auditor / compliance officer', 'Read-only access to authorized audit and compliance reports.'],
];

$roleGrantMap = [
    'super_admin' => [
        'users.view', 'users.create', 'users.update', 'users.deactivate',
        'roles.view', 'roles.permissions',
        'organizations.view', 'organizations.create', 'organizations.update', 'organizations.deactivate',
        'audits.view', 'settings.manage',
        // NOTE: deliberately NO patients.* — platform administration is
        // separated from clinical-data access (blueprint security rule).
    ],
    'org_admin' => [
        'users.view', 'users.create', 'users.update',
        'organizations.view', 'organizations.update',
        'providers.view', 'providers.create', 'providers.update',
        'patients.view', 'patients.create', 'patients.update',
        'appointments.view', 'appointments.create', 'appointments.update', 'appointments.cancel',
    ],
    'doctor' => [
        'patients.view', 'patients.create', 'patients.update',
        'providers.view',
        'appointments.view', 'appointments.create', 'appointments.update',
    ],
    'nurse' => [
        'patients.view',
        'providers.view',
        'appointments.view', 'appointments.update',
    ],
    'lab_staff' => ['patients.view'],
    'pharmacist' => ['patients.view'],
    'patient' => [
        'appointments.view', 'appointments.create', 'appointments.cancel',
    ],
    'finance_officer' => ['appointments.view'],
    'integration_admin' => [],
    'analytics_user' => [],
    'auditor' => ['audits.view'],
];

$roleIds = [];
foreach ($roleDefs as $slug => [$name, $description]) {
    $roleIds[$slug] = (int) Database::insert('roles', ['slug' => $slug, 'name' => $name, 'description' => $description]);
    foreach ($roleGrantMap[$slug] as $permSlug) {
        Database::insert('role_permissions', [
            'role_id'       => $roleIds[$slug],
            'permission_id' => $permIds[$permSlug],
        ]);
    }
}

/* ----------------------------- organizations -------------------------- */
$org1 = OrganizationRepository::create([
    'name' => 'Medboat Demo Teaching Hospital',
    'type' => 'hospital',
    'email' => 'contact@demo-hospital.medboat.test',
    'phone' => '+234 700 000 0001',
    'address' => '12 Marina Boulevard',
    'city' => 'Lagos',
    'state' => 'Lagos',
    'country' => 'Nigeria',
    'status' => 'approved',
]);
$org2 = OrganizationRepository::create([
    'name' => 'Medboat Community Clinic Ikeja',
    'type' => 'clinic',
    'email' => 'hello@ikeja-clinic.medboat.test',
    'phone' => '+234 700 000 0002',
    'address' => '5 Allen Avenue',
    'city' => 'Ikeja',
    'state' => 'Lagos',
    'country' => 'Nigeria',
    'status' => 'approved',
]);

/* -------------------------------- users ------------------------------- */
$demoPassword = 'MedCore#2026'; // documented dev credential — change/remove in production
$hash = password_hash($demoPassword, PASSWORD_DEFAULT);
$now = date('Y-m-d H:i:s');

$mk = function (string $name, string $email, string $roleSlug, array $extra = []) use ($hash, $now, $roleIds) {
    $id = UserRepository::create([
        'name' => $name,
        'email' => $email,
        'phone' => $extra['phone'] ?? null,
        'password_hash' => $hash,
        'status' => 'active',
        'email_verified_at' => $now,
    ]);
    UserRepository::addRole($id, $roleIds[$roleSlug]);
    return $id;
};

$superAdmin = $mk('Sade Adewale', 'admin@medboat.test', 'super_admin', ['phone' => '+234 801 111 0001']);
$orgAdmin   = $mk('Chinedu Okafor', 'orgadmin@medboat.test', 'org_admin', ['phone' => '+234 801 111 0002']);
$doctorUser = $mk('Dr. Amina Yusuf', 'doctor@medboat.test', 'doctor', ['phone' => '+234 801 111 0003']);
$nurseUser  = $mk('Nurse Tobi Adeyemi', 'nurse@medboat.test', 'nurse', ['phone' => '+234 801 111 0004']);
$patientUser = $mk('John Bello', 'patient@medboat.test', 'patient', ['phone' => '+234 801 111 0005']);
$auditorUser = $mk('Kemi Balogun', 'auditor@medboat.test', 'auditor', ['phone' => '+234 801 111 0006']);

foreach ([[$org1, $orgAdmin, 'Medical Director'], [$org1, $doctorUser, 'Consultant Physician'], [$org1, $nurseUser, 'Charge Nurse'], [$org2, $orgAdmin, 'Director']] as [$orgId, $userId, $title]) {
    OrganizationRepository::addMember($orgId, $userId, $title);
}

/* ------------------------------- providers ---------------------------- */
$prov1 = ProviderRepository::create([
    'organization_id' => $org1, 'user_id' => $doctorUser,
    'full_name' => 'Dr. Amina Yusuf', 'title' => 'Dr.',
    'specialty' => 'General Medicine', 'email' => 'doctor@medboat.test',
    'phone' => '+234 801 111 0003', 'location' => 'Lagos — Ikoyi',
    'bio' => 'Consultant physician with 12 years of primary and internal medicine practice.',
]);
$prov2 = ProviderRepository::create([
    'organization_id' => $org1,
    'full_name' => 'Dr. Emeka Nwosu', 'title' => 'Dr.',
    'specialty' => 'Pediatrics', 'phone' => '+234 802 222 0007',
    'location' => 'Lagos — Ikoyi',
    'bio' => 'Pediatrician focused on child wellness, immunization and adolescent health.',
]);
$prov3 = ProviderRepository::create([
    'organization_id' => $org1,
    'full_name' => 'Dr. Funke Alabi', 'title' => 'Dr.',
    'specialty' => 'Dermatology', 'phone' => '+234 802 222 0008',
    'location' => 'Lagos — Victoria Island',
    'bio' => 'Dermatologist, skin-health screening and minor procedures.',
]);
$prov4 = ProviderRepository::create([
    'organization_id' => $org2,
    'full_name' => 'Nurse Blessing Eze', 'title' => 'Nurse',
    'specialty' => 'General Practice', 'phone' => '+234 802 222 0009',
    'location' => 'Ikeja',
    'bio' => 'Community-health nurse, first-contact care and chronic-disease follow-up.',
]);

/* -------------------------------- patients ---------------------------- */
$pat1 = PatientRepository::create([
    'user_id' => $patientUser, 'primary_organization_id' => $org1,
    'first_name' => 'John', 'last_name' => 'Bello',
    'date_of_birth' => '1991-03-14', 'sex' => 'male', 'phone' => '+234 801 111 0005',
    'blood_group' => 'O+', 'allergies' => 'None known',
    'emergency_contact' => 'Mary Bello (+234 801 999 0001)',
    'consent_recorded_at' => $now, 'created_by' => $patientUser,
]);
$pat2 = PatientRepository::create([
    'primary_organization_id' => $org1,
    'first_name' => 'Ada', 'last_name' => 'Okechukwu',
    'date_of_birth' => '1986-07-02', 'sex' => 'female', 'phone' => '+234 803 333 0010',
    'blood_group' => 'A+', 'allergies' => 'Penicillin',
    'emergency_contact' => 'Obi Okechukwu (+234 803 999 0011)',
    'consent_recorded_at' => $now, 'created_by' => $doctorUser,
]);
$pat3 = PatientRepository::create([
    'primary_organization_id' => $org1,
    'first_name' => 'Musa', 'last_name' => 'Ibrahim',
    'date_of_birth' => '1979-11-25', 'sex' => 'male', 'phone' => '+234 803 333 0012',
    'blood_group' => 'B+', 'consent_recorded_at' => $now, 'created_by' => $nurseUser,
]);
$pat4 = PatientRepository::create([
    'primary_organization_id' => $org2,
    'first_name' => 'Chioma', 'last_name' => 'Eze',
    'date_of_birth' => '1995-01-30', 'sex' => 'female', 'phone' => '+234 803 333 0013',
    'consent_recorded_at' => $now, 'created_by' => $orgAdmin,
]);
$pat5 = PatientRepository::create([
    'primary_organization_id' => $org1,
    'first_name' => 'Tunde', 'last_name' => 'Sanni',
    'date_of_birth' => '1968-05-19', 'sex' => 'male', 'phone' => '+234 803 333 0014',
    'blood_group' => 'AB+', 'allergies' => 'Sulfa drugs',
    'consent_recorded_at' => $now, 'created_by' => $doctorUser,
]);

/* ------------------------------ appointments -------------------------- */
$mkAppt = function (int $patient, int $provider, string $when, string $status, string $reason, int $by) use ($org1) {
    $prov = ProviderRepository::find($provider);
    return AppointmentRepository::create([
        'patient_id' => $patient,
        'provider_id' => $provider,
        'organization_id' => (int) $prov['organization_id'],
        'scheduled_at' => date('Y-m-d H:i:s', strtotime($when)),
        'duration_min' => 30,
        'status' => $status,
        'reason' => $reason,
        'created_by' => $by,
    ]);
};

$mkAppt($pat1, $prov1, 'today 10:00', 'confirmed', 'Routine check-up and blood pressure review.', $orgAdmin);
$mkAppt($pat1, $prov3, 'tomorrow 14:30', 'booked', 'Skin rash follow-up.', $patientUser);
$mkAppt($pat2, $prov1, 'today 11:30', 'booked', 'Persistent headaches for two weeks.', $doctorUser);
$mkAppt($pat2, $prov2, 'yesterday 09:00', 'completed', "Child's immunization visit.", $nurseUser);
$mkAppt($pat3, $prov1, '-3 days 15:00', 'completed', 'Diabetes medication review.', $doctorUser);
$mkAppt($pat5, $prov1, '+3 days 09:30', 'booked', 'Pre-operative assessment.', $orgAdmin);
$mkAppt($pat4, $prov4, 'tomorrow 09:00', 'confirmed', 'Antenatal booking visit.', $orgAdmin);

/* ----------------------------- system settings ------------------------ */
foreach ([
    'platform_name' => 'MedCore Platform',
    'support_email' => 'support@medboat.test',
    'support_phone' => '+234 700 633 2673',
    'maintenance_message' => '',
] as $key => $value) {
    Database::insert('system_settings', ['key' => $key, 'value' => $value, 'updated_at' => $now]);
}

/* ------------------------------ notifications ------------------------- */
foreach ([
    [$superAdmin, 'MedCore installed', 'Phase 1 core platform is running. Baseline roles, organizations and audit logging are active.', 'success', '/dashboard'],
    [$orgAdmin, 'Organization approved', 'Medboat Demo Teaching Hospital is approved and ready to onboard staff and patients.', 'success', '/admin/organizations'],
    [$doctorUser, 'You have 2 appointments today', 'Review today\'s list on the appointments screen.', 'info', '/appointments'],
    [$patientUser, 'Welcome to Medboat', 'Your MediID health identity is ready. Book appointments and view your records anytime.', 'success', '/my/record'],
    [$auditorUser, 'Audit trail is active', 'All security and clinical-record access events are being recorded.', 'info', '/admin/audit'],
] as [$userId, $title, $body, $type, $link]) {
    \MedCore\Services\NotificationService::send($userId, $title, $body, $type, $link);
}

// Record the seed itself in the audit trail.
Database::insert('audit_logs', [
    'user_id' => null,
    'action' => 'system.seeded',
    'entity_type' => 'system',
    'entity_id' => null,
    'details' => json_encode(['demo_users' => 6, 'demo_password' => 'documented in README']),
    'ip' => '127.0.0.1',
    'user_agent' => 'cli',
    'created_at' => $now,
]);

fwrite(STDOUT, "Seed complete.\n");
fwrite(STDOUT, "Demo password for all accounts: {$demoPassword}\n");
fwrite(STDOUT, "  super admin : admin@medboat.test\n");
fwrite(STDOUT, "  org admin   : orgadmin@medboat.test\n");
fwrite(STDOUT, "  doctor      : doctor@medboat.test\n");
fwrite(STDOUT, "  nurse       : nurse@medboat.test\n");
fwrite(STDOUT, "  patient     : patient@medboat.test\n");
fwrite(STDOUT, "  auditor     : auditor@medboat.test\n");
