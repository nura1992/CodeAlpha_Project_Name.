<?php

namespace MedCore\Modules\Identity;

use MedCore\Core\Auth;
use MedCore\Core\Authorization;
use MedCore\Core\Database;
use MedCore\Core\Request;
use MedCore\Core\Response;
use MedCore\Core\Session;
use MedCore\Core\Validator;
use MedCore\Repositories\OrganizationRepository;
use MedCore\Repositories\RoleRepository;
use MedCore\Repositories\UserRepository;
use MedCore\Services\AuditLogger;

/**
 * Staff user administration (super admin: all users; org admin: own org).
 */
final class UserController
{
    public function index(Request $request): void
    {
        Authorization::authorize('users.view');

        $page = max(1, (int) $request->input('page', 1));
        $search = trim((string) $request->input('q', ''));
        $roleId = (int) $request->input('role', 0) ?: null;
        $orgScope = $this->manageScopeOrgIds();

        $result = UserRepository::paginate($page, 15, $search, $roleId, $orgScope);

        Response::view('admin/users/index', [
            'title'      => 'Users',
            'result'     => $result,
            'search'     => $search,
            'roles'      => RoleRepository::all(),
            'activeRole' => $roleId,
        ]);
    }

    public function create(): void
    {
        Authorization::authorize('users.create');
        Response::view('admin/users/form', [
            'title' => 'Invite user',
            'user'  => null,
            'roles' => $this->assignableRoles(),
            'orgs'  => $this->assignableOrgs(),
            'userRoleIds' => [],
        ]);
    }

    public function store(Request $request): void
    {
        Authorization::authorize('users.create');

        $v = Validator::make($request->all(), [
            'name'     => 'required|min:2|max:120',
            'email'    => 'required|email|max:190',
            'phone'    => 'phone|max:20',
            'password' => 'required|min:' . \MedCore\Core\Config::get('app.auth.password_min_length'),
            'status'   => 'in:active,suspended',
        ]);
        $errors = $v->errors();
        if (UserRepository::findByEmail((string) $request->input('email')) !== null) {
            $errors['email'][] = 'An account with this email already exists.';
        }
        $roleIds = array_map('intval', (array) $request->input('roles', []));
        if ($roleIds === []) {
            $errors['roles'][] = 'Assign at least one role.';
        }
        $errors = array_merge($errors, $this->validateRoleAssignment($roleIds));
        if ($errors !== []) {
            Session::flashInput($request->all());
            Session::flashErrors($errors);
            Response::redirect('/admin/users/create');
            return;
        }

        $userId = Database::transaction(function () use ($request, $roleIds) {
            $id = UserRepository::create([
                'name'              => trim((string) $request->input('name')),
                'email'             => (string) $request->input('email'),
                'phone'             => trim((string) $request->input('phone', '')) ?: null,
                'password_hash'     => password_hash((string) $request->input('password'), PASSWORD_DEFAULT),
                'status'            => (string) $request->input('status', 'active'),
                'email_verified_at' => date('Y-m-d H:i:s'), // staff-invited accounts start verified
            ]);
            UserRepository::syncRoles($id, $roleIds);
            $orgId = (int) $request->input('organization_id', 0);
            if ($orgId > 0 && Authorization::canManageOrganization($orgId)) {
                OrganizationRepository::addMember($orgId, $id, trim((string) $request->input('job_title', '')) ?: null);
            }
            return $id;
        });

        AuditLogger::log(Auth::id(), 'users.created', 'user', $userId, ['roles' => $roleIds], $request);
        Session::flash('success', 'User created.');
        Response::redirect('/admin/users');
    }

    public function edit(Request $request, string $id): void
    {
        Authorization::authorize('users.update');
        $user = UserRepository::find((int) $id);
        if ($user === null || !$this->withinScope((int) $id)) {
            Response::abort(404);
        }
        $roles = UserRepository::rolesOf((int) $id);
        Response::view('admin/users/form', [
            'title'       => 'Edit user',
            'user'        => $user,
            'roles'       => $this->assignableRoles(),
            'orgs'        => $this->assignableOrgs(),
            'userRoleIds' => array_map(static fn($r) => (int) $r['id'], $roles),
            'userOrgId'   => $this->primaryOrgOfUser((int) $id),
        ]);
    }

    public function update(Request $request, string $id): void
    {
        Authorization::authorize('users.update');
        $user = UserRepository::find((int) $id);
        if ($user === null || !$this->withinScope((int) $id)) {
            Response::abort(404);
        }

        $v = Validator::make($request->all(), [
            'name'   => 'required|min:2|max:120',
            'email'  => 'required|email|max:190',
            'phone'  => 'phone|max:20',
            'status' => 'in:active,suspended,deactivated',
        ]);
        $errors = $v->errors();
        $existing = UserRepository::findByEmail((string) $request->input('email'));
        if ($existing !== null && (int) $existing['id'] !== (int) $id) {
            $errors['email'][] = 'That email is used by another account.';
        }
        $roleIds = array_map('intval', (array) $request->input('roles', []));
        if ($roleIds === []) {
            $errors['roles'][] = 'Assign at least one role.';
        }
        $errors = array_merge($errors, $this->validateRoleAssignment($roleIds));

        $target = UserRepository::rolesOf((int) $id);
        $targetIsSuperAdmin = array_filter($target, static fn($r) => $r['slug'] === 'super_admin') !== [];
        if ($targetIsSuperAdmin && !Authorization::hasRole((int) Auth::id(), 'super_admin')) {
            $errors['email'][] = 'Only a super administrator can modify another super administrator.';
        }
        if ((int) $id === (int) Auth::id() && $request->input('status') !== 'active') {
            $errors['status'][] = 'You cannot suspend or deactivate your own account.';
        }
        if ($errors !== []) {
            Session::flashErrors($errors);
            Response::redirect('/admin/users/' . (int) $id . '/edit');
            return;
        }

        Database::transaction(function () use ($request, $id, $roleIds, $user) {
            UserRepository::update((int) $id, [
                'name'   => trim((string) $request->input('name')),
                'email'  => (string) $request->input('email'),
                'phone'  => trim((string) $request->input('phone', '')) ?: null,
                'status' => (string) $request->input('status', 'active'),
            ]);
            if (trim((string) $request->input('password', '')) !== '') {
                UserRepository::updatePasswordHash((int) $id, password_hash((string) $request->input('password'), PASSWORD_DEFAULT));
            }
            UserRepository::syncRoles((int) $id, $roleIds);
        });

        Authorization::clearCache();
        AuditLogger::log(Auth::id(), 'users.updated', 'user', (int) $id, [
            'roles' => $roleIds,
            'password_reset' => trim((string) $request->input('password', '')) !== '',
        ], $request);
        Session::flash('success', 'User updated.');
        Response::redirect('/admin/users');
    }

    /* ------------------------------ scope ----------------------------- */

    /** Returns null for super admin (all orgs) or the admin's own org ids. */
    private function manageScopeOrgIds(): ?array
    {
        if (Authorization::hasRole((int) Auth::id(), 'super_admin')) {
            return null;
        }
        return Authorization::organizationIdsOf((int) Auth::id());
    }

    private function withinScope(int $userId): bool
    {
        $scope = $this->manageScopeOrgIds();
        if ($scope === null) {
            return true; // super admin
        }
        if ($userId === (int) Auth::id()) {
            return true; // self
        }
        $targetOrgs = Authorization::organizationIdsOf($userId);
        // Org admins may only touch users who share an organization with them.
        return array_intersect($scope, $targetOrgs) !== [];
    }

    private function assignableRoles(): array
    {
        $roles = RoleRepository::all();
        if (Authorization::hasRole((int) Auth::id(), 'super_admin')) {
            return $roles;
        }
        // Org admins may never grant elevated platform roles.
        return array_values(array_filter($roles, static fn($r) => !in_array($r['slug'], ['super_admin'], true)));
    }

    private function validateRoleAssignment(array $roleIds): array
    {
        $assignable = array_map(static fn($r) => (int) $r['id'], $this->assignableRoles());
        foreach ($roleIds as $rid) {
            if (!in_array($rid, $assignable, true)) {
                return ['roles' => ['You cannot assign one of the selected roles.']];
            }
        }
        return [];
    }

    private function assignableOrgs(): array
    {
        $scope = $this->manageScopeOrgIds();
        if ($scope === null) {
            return OrganizationRepository::allActive();
        }
        $orgs = [];
        foreach ($scope as $orgId) {
            $org = OrganizationRepository::find($orgId);
            if ($org !== null) {
                $orgs[] = $org;
            }
        }
        return $orgs;
    }

    private function primaryOrgOfUser(int $userId): ?int
    {
        $ids = Authorization::organizationIdsOf($userId);
        return $ids[0] ?? null;
    }
}
