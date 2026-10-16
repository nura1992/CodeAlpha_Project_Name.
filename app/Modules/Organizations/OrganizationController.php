<?php

namespace MedCore\Modules\Organizations;

use MedCore\Core\Auth;
use MedCore\Core\Authorization;
use MedCore\Core\Request;
use MedCore\Core\Response;
use MedCore\Core\Session;
use MedCore\Core\Validator;
use MedCore\Repositories\OrganizationRepository;
use MedCore\Repositories\RoleRepository;
use MedCore\Repositories\UserRepository;
use MedCore\Services\AuditLogger;
use MedCore\Services\NotificationService;

/**
 * Organization administration (hospitals, clinics, labs, pharmacies).
 */
final class OrganizationController
{
    public const TYPES = ['hospital', 'clinic', 'laboratory', 'pharmacy', 'other'];

    public function index(Request $request): void
    {
        Authorization::authorize('organizations.view');
        $page = max(1, (int) $request->input('page', 1));
        $search = trim((string) $request->input('q', ''));
        $restrict = Authorization::hasRole((int) Auth::id(), 'super_admin')
            ? null
            : Authorization::organizationIdsOf((int) Auth::id());

        Response::view('admin/orgs/index', [
            'title'  => 'Organizations',
            'result' => OrganizationRepository::paginate($page, 15, $search, $restrict),
            'search' => $search,
        ]);
    }

    public function create(): void
    {
        Authorization::authorize('organizations.create');
        Response::view('admin/orgs/form', ['title' => 'Register organization', 'org' => null, 'types' => self::TYPES]);
    }

    public function store(Request $request): void
    {
        Authorization::authorize('organizations.create');
        $v = $this->validateOrg($request);
        if ($v->fails()) {
            Session::flashInput($request->all());
            Session::flashErrors($v->errors());
            Response::redirect('/admin/organizations/create');
            return;
        }
        $id = OrganizationRepository::create($v->validated());
        AuditLogger::log(Auth::id(), 'organizations.created', 'organization', $id, ['name' => $request->input('name')], $request);
        Session::flash('success', 'Organization registered.');
        Response::redirect('/admin/organizations/' . $id);
    }

    public function show(Request $request, string $id): void
    {
        Authorization::authorize('organizations.view');
        $org = OrganizationRepository::find((int) $id);
        if ($org === null || !$this->withinScope((int) $id)) {
            Response::abort(404);
        }
        Response::view('admin/orgs/show', [
            'title'     => $org['name'],
            'org'       => $org,
            'members'   => OrganizationRepository::members((int) $id),
            'canManage' => Authorization::canManageOrganization((int) $id),
        ]);
    }

    public function edit(Request $request, string $id): void
    {
        $org = OrganizationRepository::find((int) $id);
        if ($org === null || !Authorization::canManageOrganization((int) $id)) {
            Response::abort(404);
        }
        Response::view('admin/orgs/form', ['title' => 'Edit organization', 'org' => $org, 'types' => self::TYPES]);
    }

    public function update(Request $request, string $id): void
    {
        $org = OrganizationRepository::find((int) $id);
        if ($org === null || !Authorization::canManageOrganization((int) $id)) {
            Response::abort(404);
        }
        $v = $this->validateOrg($request);
        if ($v->fails()) {
            Session::flashErrors($v->errors());
            Response::redirect('/admin/organizations/' . (int) $id . '/edit');
            return;
        }
        $data = $v->validated();
        // Org admins cannot change approval status — super admin only.
        if (!Authorization::hasRole((int) Auth::id(), 'super_admin')) {
            unset($data['status']);
        }
        OrganizationRepository::update((int) $id, $data);
        AuditLogger::log(Auth::id(), 'organizations.updated', 'organization', (int) $id, null, $request);
        Session::flash('success', 'Organization updated.');
        Response::redirect('/admin/organizations/' . (int) $id);
    }

    public function addMember(Request $request, string $id): void
    {
        $org = OrganizationRepository::find((int) $id);
        if ($org === null || !Authorization::canManageOrganization((int) $id)) {
            Response::abort(404);
        }
        $v = Validator::make($request->all(), [
            'email'     => 'required|email',
            'job_title' => 'max:80',
            'role'      => 'integer',
        ]);
        if ($v->fails()) {
            Session::flashErrors($v->errors());
            Response::redirect('/admin/organizations/' . (int) $id);
            return;
        }
        $user = UserRepository::findByEmail((string) $request->input('email'));
        if ($user === null) {
            Session::flash('danger', 'No user found with that email. Create the user account first.');
            Response::redirect('/admin/organizations/' . (int) $id);
            return;
        }
        $added = OrganizationRepository::addMember((int) $id, (int) $user['id'], trim((string) $request->input('job_title', '')) ?: null);
        $roleId = (int) $request->input('role', 0);
        if ($roleId > 0) {
            $role = RoleRepository::find($roleId);
            if ($role !== null && $role['slug'] !== 'super_admin') {
                UserRepository::addRole((int) $user['id'], $roleId);
            }
        }
        if ($added) {
            AuditLogger::log(Auth::id(), 'organizations.member_added', 'organization', (int) $id, ['user_id' => (int) $user['id']], $request);
            NotificationService::send((int) $user['id'], 'You joined ' . $org['name'], 'An administrator added you to this organization on MedCore.', 'info', '/dashboard');
            Session::flash('success', $user['name'] . ' added to ' . $org['name'] . '.');
        } else {
            Session::flash('warning', $user['name'] . ' is already a member.');
        }
        Response::redirect('/admin/organizations/' . (int) $id);
    }

    public function removeMember(Request $request, string $id, string $userId): void
    {
        $org = OrganizationRepository::find((int) $id);
        if ($org === null || !Authorization::canManageOrganization((int) $id)) {
            Response::abort(404);
        }
        if ((int) $userId === (int) Auth::id()) {
            Session::flash('danger', 'You cannot remove yourself from the organization.');
            Response::redirect('/admin/organizations/' . (int) $id);
            return;
        }
        OrganizationRepository::removeMember((int) $id, (int) $userId);
        AuditLogger::log(Auth::id(), 'organizations.member_removed', 'organization', (int) $id, ['user_id' => (int) $userId], $request);
        Session::flash('success', 'Member removed.');
        Response::redirect('/admin/organizations/' . (int) $id);
    }

    /* ------------------------------ helpers ---------------------------- */

    private function validateOrg(Request $request): Validator
    {
        return Validator::make($request->all(), [
            'name'    => 'required|min:2|max:160',
            'type'    => 'required|in:' . implode(',', self::TYPES),
            'email'   => 'email|max:190',
            'phone'   => 'phone|max:20',
            'address' => 'max:255',
            'city'    => 'max:80',
            'state'   => 'max:80',
            'country' => 'max:80',
            'status'  => 'in:pending,approved,suspended',
        ]);
    }

    private function withinScope(int $orgId): bool
    {
        if (Authorization::hasRole((int) Auth::id(), 'super_admin')) {
            return true;
        }
        return in_array($orgId, Authorization::organizationIdsOf((int) Auth::id()), true);
    }
}
