<?php

use MedCore\Core\Router;
use MedCore\Modules\Admin\AuditController;
use MedCore\Modules\Admin\DashboardController;
use MedCore\Modules\Admin\NotificationController;
use MedCore\Modules\Admin\SettingsController;
use MedCore\Modules\Appointments\AppointmentController;
use MedCore\Modules\Identity\AuthController;
use MedCore\Modules\Identity\ProfileController;
use MedCore\Modules\Identity\RoleController;
use MedCore\Modules\Identity\UserController;
use MedCore\Modules\Organizations\OrganizationController;
use MedCore\Modules\Patients\PatientController;
use MedCore\Modules\Providers\ProviderController;
use MedCore\Modules\PublicSite\LandingController;

/**
 * Web routes. Middleware: auth, guest, perm:<slug>.
 * CSRF is enforced globally for all POST routes (see public/index.php).
 */

return function (Router $r): void {
    /* ------------------------- Public ------------------------- */
    $r->get('/', [LandingController::class, 'landing']);
    $r->get('/directory', [LandingController::class, 'directory']);

    /* --------------------------- Auth ------------------------- */
    $r->get('/login', [AuthController::class, 'showLogin'])->middleware('guest');
    $r->post('/login', [AuthController::class, 'login'])->middleware('guest');
    $r->get('/register', [AuthController::class, 'showRegister'])->middleware('guest');
    $r->post('/register', [AuthController::class, 'register'])->middleware('guest');
    $r->get('/verify', [AuthController::class, 'verify']);
    $r->get('/password/forgot', [AuthController::class, 'showForgot'])->middleware('guest');
    $r->post('/password/forgot', [AuthController::class, 'sendReset'])->middleware('guest');
    $r->get('/password/reset', [AuthController::class, 'showReset'])->middleware('guest');
    $r->post('/password/reset', [AuthController::class, 'reset'])->middleware('guest');
    $r->post('/logout', [AuthController::class, 'logout'])->middleware('auth');

    /* ------------------------ Authenticated -------------------- */
    $r->get('/dashboard', [DashboardController::class, 'index'])->middleware('auth');

    $r->get('/profile', [ProfileController::class, 'show'])->middleware('auth');
    $r->post('/profile', [ProfileController::class, 'update'])->middleware('auth');
    $r->post('/profile/password', [ProfileController::class, 'changePassword'])->middleware('auth');

    $r->get('/notifications', [NotificationController::class, 'index'])->middleware('auth');
    $r->post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->middleware('auth');
    $r->post('/notifications/{id}/read', [NotificationController::class, 'markRead'])->middleware('auth');

    /* ---------------------- MediID: patients ------------------- */
    $r->get('/my/record', [PatientController::class, 'mine'])->middleware('auth');
    $r->get('/patients', [PatientController::class, 'index'])->middleware('auth', 'perm:patients.view');
    $r->get('/patients/create', [PatientController::class, 'create'])->middleware('auth', 'perm:patients.create');
    $r->post('/patients', [PatientController::class, 'store'])->middleware('auth', 'perm:patients.create');
    $r->get('/patients/{id}', [PatientController::class, 'show'])->middleware('auth');
    $r->get('/patients/{id}/edit', [PatientController::class, 'edit'])->middleware('auth', 'perm:patients.update');
    $r->post('/patients/{id}/update', [PatientController::class, 'update'])->middleware('auth', 'perm:patients.update');

    /* --------------------- MediLink: providers ----------------- */
    $r->get('/providers', [ProviderController::class, 'index'])->middleware('auth', 'perm:providers.view');
    $r->get('/providers/create', [ProviderController::class, 'create'])->middleware('auth', 'perm:providers.create');
    $r->post('/providers', [ProviderController::class, 'store'])->middleware('auth', 'perm:providers.create');
    $r->get('/providers/{id}/edit', [ProviderController::class, 'edit'])->middleware('auth', 'perm:providers.update');
    $r->post('/providers/{id}/update', [ProviderController::class, 'update'])->middleware('auth', 'perm:providers.update');

    /* -------------------- MediLink: appointments --------------- */
    $r->get('/my/appointments', [\MedCore\Modules\Admin\RedirectController::class, 'toAppointments'])->middleware('auth');
    $r->get('/appointments', [AppointmentController::class, 'index'])->middleware('auth', 'perm:appointments.view');
    $r->get('/appointments/create', [AppointmentController::class, 'create'])->middleware('auth', 'perm:appointments.create');
    $r->post('/appointments', [AppointmentController::class, 'store'])->middleware('auth', 'perm:appointments.create');
    $r->post('/appointments/{id}/status', [AppointmentController::class, 'setStatus'])->middleware('auth');

    /* -------------------------- Admin -------------------------- */
    $r->get('/admin/users', [UserController::class, 'index'])->middleware('auth', 'perm:users.view');
    $r->get('/admin/users/create', [UserController::class, 'create'])->middleware('auth', 'perm:users.create');
    $r->post('/admin/users', [UserController::class, 'store'])->middleware('auth', 'perm:users.create');
    $r->get('/admin/users/{id}/edit', [UserController::class, 'edit'])->middleware('auth', 'perm:users.update');
    $r->post('/admin/users/{id}/update', [UserController::class, 'update'])->middleware('auth', 'perm:users.update');

    $r->get('/admin/roles', [RoleController::class, 'index'])->middleware('auth', 'perm:roles.view');
    $r->get('/admin/roles/{id}/edit', [RoleController::class, 'edit'])->middleware('auth', 'perm:roles.view');
    $r->post('/admin/roles/{id}/permissions', [RoleController::class, 'updatePermissions'])->middleware('auth', 'perm:roles.permissions');

    $r->get('/admin/organizations', [OrganizationController::class, 'index'])->middleware('auth', 'perm:organizations.view');
    $r->get('/admin/organizations/create', [OrganizationController::class, 'create'])->middleware('auth', 'perm:organizations.create');
    $r->post('/admin/organizations', [OrganizationController::class, 'store'])->middleware('auth', 'perm:organizations.create');
    $r->get('/admin/organizations/{id}', [OrganizationController::class, 'show'])->middleware('auth', 'perm:organizations.view');
    $r->get('/admin/organizations/{id}/edit', [OrganizationController::class, 'edit'])->middleware('auth', 'perm:organizations.update');
    $r->post('/admin/organizations/{id}/update', [OrganizationController::class, 'update'])->middleware('auth', 'perm:organizations.update');
    $r->post('/admin/organizations/{id}/members', [OrganizationController::class, 'addMember'])->middleware('auth', 'perm:organizations.update');
    $r->post('/admin/organizations/{id}/members/{userId}/remove', [OrganizationController::class, 'removeMember'])->middleware('auth', 'perm:organizations.update');

    $r->get('/admin/audit', [AuditController::class, 'index'])->middleware('auth', 'perm:audits.view');

    $r->get('/admin/settings', [SettingsController::class, 'index'])->middleware('auth', 'perm:settings.manage');
    $r->post('/admin/settings', [SettingsController::class, 'update'])->middleware('auth', 'perm:settings.manage');
};
