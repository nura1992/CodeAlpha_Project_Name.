<?php

namespace MedCore\Modules\Identity;

use MedCore\Core\Auth;
use MedCore\Core\Authorization;
use MedCore\Core\Request;
use MedCore\Core\Response;
use MedCore\Core\Session;
use MedCore\Repositories\RoleRepository;
use MedCore\Services\AuditLogger;

/**
 * Role directory and the permission matrix editor.
 * Matrix changes are saved through the JSON API (fetch + CSRF header).
 */
final class RoleController
{
    public function index(): void
    {
        Authorization::authorize('roles.view');
        $roles = RoleRepository::all();
        foreach ($roles as &$role) {
            $role['user_count'] = RoleRepository::usersCount((int) $role['id']);
            $role['permission_count'] = count(RoleRepository::permissionIdsForRole((int) $role['id']));
        }
        unset($role);
        Response::view('admin/roles/index', ['title' => 'Roles & permissions', 'roles' => $roles]);
    }

    public function edit(Request $request, string $id): void
    {
        Authorization::authorize('roles.view');
        $role = RoleRepository::find((int) $id);
        if ($role === null) {
            Response::abort(404);
        }
        $perms = RoleRepository::allPermissions();
        $grouped = [];
        foreach ($perms as $p) {
            $grouped[$p['grp']][] = $p;
        }
        Response::view('admin/roles/edit', [
            'title'    => 'Edit role: ' . $role['name'],
            'role'     => $role,
            'grouped'  => $grouped,
            'granted'  => RoleRepository::permissionIdsForRole((int) $id),
            'canEdit'  => Authorization::can(Auth::id(), 'roles.permissions'),
        ]);
    }

    /** POST /admin/roles/{id}/permissions — JSON body {permission_ids: [...]} */
    public function updatePermissions(Request $request, string $id): void
    {
        Authorization::authorize('roles.permissions');
        $role = RoleRepository::find((int) $id);
        if ($role === null) {
            Response::json(['error' => 'Role not found'], 404);
            return;
        }

        if ($role['slug'] === 'super_admin' && !Authorization::hasRole((int) Auth::id(), 'super_admin')) {
            Response::json(['error' => 'Only a super administrator can edit the super administrator role.'], 403);
            return;
        }

        $ids = (array) $request->input('permission_ids', []);
        $validIds = array_map(static fn($p) => (int) $p['id'], RoleRepository::allPermissions());
        $clean = [];
        foreach ($ids as $pid) {
            $pid = (int) $pid;
            if (in_array($pid, $validIds, true)) {
                $clean[$pid] = true;
            }
        }
        $clean = array_keys($clean);

        RoleRepository::syncPermissions((int) $id, $clean);
        Authorization::clearCache();
        AuditLogger::log(Auth::id(), 'roles.permissions_updated', 'role', (int) $id, [
            'permission_count' => count($clean),
        ], $request);

        Response::json(['ok' => true, 'permission_count' => count($clean)]);
    }
}
