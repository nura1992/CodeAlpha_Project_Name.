<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Features;
use App\Core\Rbac;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\Validator;

/**
 * Super Admin controls: staff accounts, custom roles, feature toggles, protected settings,
 * audit logs, analytics and announcements. Each action is permission-checked by the router
 * AND re-checked here for rules the router cannot express (last super admin, no privilege escalation).
 */
final class AdminController
{
    // ------------------------------------------------------------ staff accounts
    public function staffAccounts(Request $r): array
    {
        $rows = Database::all(
            "SELECT u.id, u.username, u.email, u.status, u.must_change_password, u.last_login_at, u.created_at,
                    GROUP_CONCAT(r.slug ORDER BY r.slug) AS roles
             FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id
             WHERE u.account_type = 'staff' AND u.status <> 'deleted'
             GROUP BY u.id ORDER BY u.id ASC"
        );
        return ['items' => array_map(static fn($row) => $row + ['roles' => $row['roles'] ? explode(',', $row['roles']) : []], $rows)];
    }

    public function createStaff(Request $r): array
    {
        $username = Validator::username($r->input('username'));
        $email = Validator::email($r->input('email'));
        $password = Validator::password($r->input('password'));
        $roleIds = $this->roleIdsInput($r);
        $this->assertCanGrant($r->userId(), $roleIds);
        if (Database::value('SELECT 1 FROM users WHERE username = ? OR email = ?', [$username, $email])) {
            throw new ApiException(409, 'ACCOUNT_EXISTS', 'That username or email is already in use.');
        }
        $id = Database::transaction(static function () use ($username, $email, $password, $roleIds, $r): int {
            $id = Database::insert(
                "INSERT INTO users (username, email, password_hash, account_type, status, must_change_password, email_verified_at)
                 VALUES (?, ?, ?, 'staff', 'active', 1, NOW())",
                [$username, $email, Auth::hashPassword($password)]
            );
            foreach ($roleIds as $roleId) {
                Database::exec('INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (?, ?, ?)', [$id, $roleId, $r->userId()]);
            }
            return $id;
        });
        Audit::log($r->userId(), 'staff.created', 'user', $id, null, null, ['username' => $username, 'role_ids' => $roleIds]);
        Response::status(201);
        return ['id' => $id, 'must_change_password' => true];
    }

    public function updateStaff(Request $r): array
    {
        $id = (int) $r->param('id');
        $this->assertStaff($id);
        $roleIds = $this->roleIdsInput($r);
        $this->assertCanGrant($r->userId(), $roleIds);
        $before = $this->rolesOf($id);
        $removingSuper = $this->hasSuper($id) && !$this->roleSetHasSuper($roleIds);
        if ($removingSuper) {
            $this->assertNotLastSuperAdmin($id);
            $this->requireSuper($r->userId());
        }
        Database::transaction(static function () use ($id, $roleIds, $r): void {
            Database::exec('DELETE FROM user_roles WHERE user_id = ?', [$id]);
            foreach ($roleIds as $roleId) {
                Database::exec('INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (?, ?, ?)', [$id, $roleId, $r->userId()]);
            }
        });
        Rbac::forgetCache($id);
        Audit::log($r->userId(), 'staff.roles_updated', 'user', $id, null, ['roles' => $before], ['roles' => $this->rolesOf($id)]);
        return ['ok' => true, 'roles' => $this->rolesOf($id)];
    }

    public function suspendStaff(Request $r): array
    {
        return $this->setStaffStatus($r, 'suspended');
    }

    public function restoreStaff(Request $r): array
    {
        return $this->setStaffStatus($r, 'active');
    }

    public function deleteStaff(Request $r): array
    {
        $this->requireSuper($r->userId());
        $id = (int) $r->param('id');
        $this->assertStaff($id);
        if ($id === $r->userId()) {
            throw new ApiException(422, 'SELF_ACTION', 'You cannot delete your own account.');
        }
        $this->assertNotLastSuperAdmin($id);
        Database::exec("UPDATE users SET status = 'deleted', deleted_at = NOW(), email = NULL WHERE id = ?", [$id]);
        Auth::revokeAll($id);
        Rbac::forgetCache($id);
        Audit::log($r->userId(), 'staff.deleted', 'user', $id, $r->input('reason'));
        return ['ok' => true];
    }

    private function setStaffStatus(Request $r, string $status): array
    {
        $id = (int) $r->param('id');
        $this->assertStaff($id);
        if ($id === $r->userId()) {
            throw new ApiException(422, 'SELF_ACTION', 'You cannot change the status of your own account.');
        }
        $reason = Validator::str($r->input('reason'), 'reason', 5, 500);
        if ($status === 'suspended') {
            $this->assertNotLastSuperAdmin($id);
            if ($this->hasSuper($id)) {
                $this->requireSuper($r->userId());
            }
            Auth::revokeAll($id);
        }
        Database::exec('UPDATE users SET status = ? WHERE id = ?', [$status, $id]);
        Audit::log($r->userId(), $status === 'active' ? 'staff.restored' : 'staff.suspended', 'user', $id, $reason);
        return ['ok' => true, 'status' => $status];
    }

    // ------------------------------------------------------------------ roles
    public function roles(Request $r): array
    {
        $roles = Database::all('SELECT id, slug, name, description, is_system FROM roles ORDER BY id');
        foreach ($roles as &$role) {
            $role['permissions'] = array_column(Database::all(
                'SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? ORDER BY p.code',
                [$role['id']]
            ), 'code');
            $role['staff_count'] = (int) Database::value('SELECT COUNT(*) FROM user_roles WHERE role_id = ?', [$role['id']]);
        }
        return ['items' => $roles];
    }

    public function permissions(Request $r): array
    {
        return ['items' => Database::all('SELECT code, group_name, description FROM permissions ORDER BY group_name, code')];
    }

    public function createRole(Request $r): array
    {
        $name = Validator::str($r->input('name'), 'name', 3, 100);
        $description = Validator::str($r->input('description'), 'description', 0, 255, false);
        $codes = $this->permissionCodesInput($r);
        $this->assertCanGrantPermissions($r->userId(), $codes);
        $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '_', $name), '_'));
        if (Database::value('SELECT 1 FROM roles WHERE slug = ?', [$slug])) {
            throw new ApiException(409, 'ROLE_EXISTS', 'A role with that name already exists.');
        }
        $id = Database::transaction(static function () use ($slug, $name, $description, $codes, $r): int {
            $id = Database::insert('INSERT INTO roles (slug, name, description, is_system, created_by) VALUES (?, ?, ?, 0, ?)',
                [$slug, $name, $description, $r->userId()]);
            foreach ($codes as $code) {
                Database::exec('INSERT INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE code = ?', [$id, $code]);
            }
            return $id;
        });
        Audit::log($r->userId(), 'role.created', 'role', $id, null, null, ['name' => $name, 'permissions' => $codes]);
        Response::status(201);
        return ['id' => $id];
    }

    public function updateRole(Request $r): array
    {
        $id = (int) $r->param('id');
        $role = Database::one('SELECT * FROM roles WHERE id = ?', [$id]);
        if ($role === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Role not found.');
        }
        if ($role['slug'] === 'super_admin') {
            throw new ApiException(422, 'SYSTEM_ROLE', 'The Super Admin role always holds every permission and cannot be edited.');
        }
        $codes = $this->permissionCodesInput($r);
        $this->assertCanGrantPermissions($r->userId(), $codes);
        $before = array_column(Database::all('SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?', [$id]), 'code');
        Database::transaction(static function () use ($id, $codes, $r): void {
            $name = Validator::str($r->input('name', null), 'name', 3, 100, false);
            if ($name !== null) {
                Database::exec('UPDATE roles SET name = ? WHERE id = ?', [$name, $id]);
            }
            Database::exec('DELETE FROM role_permissions WHERE role_id = ?', [$id]);
            foreach ($codes as $code) {
                Database::exec('INSERT INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE code = ?', [$id, $code]);
            }
        });
        // Cached permission sets for everyone holding this role are refreshed on their next request.
        Audit::log($r->userId(), 'role.permissions_updated', 'role', $id, null, ['permissions' => $before], ['permissions' => $codes]);
        return ['ok' => true];
    }

    public function deleteRole(Request $r): array
    {
        $id = (int) $r->param('id');
        $role = Database::one('SELECT * FROM roles WHERE id = ?', [$id]);
        if ($role === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Role not found.');
        }
        if ((int) $role['is_system'] === 1) {
            throw new ApiException(422, 'SYSTEM_ROLE', 'System roles cannot be deleted.');
        }
        if (Database::value('SELECT 1 FROM user_roles WHERE role_id = ? LIMIT 1', [$id])) {
            throw new ApiException(409, 'ROLE_IN_USE', 'Remove this role from all staff accounts first.');
        }
        Database::exec('DELETE FROM roles WHERE id = ?', [$id]);
        Audit::log($r->userId(), 'role.deleted', 'role', $id, null, ['slug' => $role['slug']]);
        return ['ok' => true];
    }

    // --------------------------------------------------------------- features
    public function features(Request $r): array
    {
        return ['items' => array_values(Features::map()), 'lockdown' => Features::enabled(Features::LOCKDOWN)];
    }

    public function toggleFeature(Request $r): array
    {
        $key = (string) $r->param('key');
        $enabled = Validator::boolean($r->input('enabled'));
        $reason = $r->input('reason') !== null ? Validator::str($r->input('reason'), 'reason', 0, 500, false) : null;
        Features::set($key, $enabled, $r->userId(), $reason);
        return ['items' => array_values(Features::map())];
    }

    public function enableAllFeatures(Request $r): array
    {
        Features::enableAll($r->userId());
        return ['items' => array_values(Features::map())];
    }

    // --------------------------------------------------------------- settings
    public function settings(Request $r): array
    {
        $items = [];
        foreach (Settings::ALLOWED as $key => $rule) {
            $items[] = ['key' => $key, 'value' => Settings::get($key), 'type' => $rule['type']] + array_intersect_key($rule, ['min' => 1, 'max' => 1]);
        }
        return ['items' => $items];
    }

    public function updateSetting(Request $r): array
    {
        $key = (string) $r->param('key');
        if (!array_key_exists('value', $r->input)) {
            Validator::fail('value', 'A value is required.');
        }
        Settings::set($key, $r->input('value'), $r->userId(), $r->input('reason'));
        return ['key' => $key, 'value' => Settings::get($key)];
    }

    // ------------------------------------------------------------------ audit
    public function auditLogs(Request $r): array
    {
        $where = ['1=1'];
        $params = [];
        if ($r->query('action')) {
            $where[] = 'action LIKE ?';
            $params[] = str_replace(['%', '_'], ['\\%', '\\_'], Validator::str($r->query('action'), 'action', 1, 80)) . '%';
        }
        if ($r->query('actor_id')) {
            $where[] = 'actor_id = ?';
            $params[] = Validator::int($r->query('actor_id'), 'actor_id', 1, PHP_INT_MAX);
        }
        if ($r->query('target_type')) {
            $where[] = 'target_type = ?';
            $params[] = Validator::str($r->query('target_type'), 'target_type', 1, 40);
        }
        $before = max(0, (int) $r->query('before', 0));
        if ($before > 0) {
            $where[] = 'id < ?';
            $params[] = $before;
        }
        $rows = Database::all(
            'SELECT id, actor_id, actor_ip, action, target_type, target_id, reason, before_data, after_data, created_at
             FROM audit_logs WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 100',
            $params
        );
        return ['items' => $rows, 'next_before' => $rows === [] ? null : (int) end($rows)['id']];
    }

    // -------------------------------------------------------------- analytics
    public function analytics(Request $r): array
    {
        $scalar = static fn(string $sql) => (int) Database::value($sql);
        return [
            'members' => [
                'total' => $scalar("SELECT COUNT(*) FROM users WHERE account_type = 'member' AND deleted_at IS NULL"),
                'active' => $scalar("SELECT COUNT(*) FROM users WHERE account_type = 'member' AND status = 'active'"),
                'suspended' => $scalar("SELECT COUNT(*) FROM users WHERE account_type = 'member' AND status = 'suspended'"),
                'new_7d' => $scalar("SELECT COUNT(*) FROM users WHERE account_type = 'member' AND created_at > NOW() - INTERVAL 7 DAY"),
            ],
            'profiles' => [
                'approved' => $scalar("SELECT COUNT(*) FROM profiles WHERE approval_status = 'approved'"),
                'pending' => $scalar("SELECT COUNT(*) FROM profiles WHERE approval_status = 'pending'"),
                'rejected' => $scalar("SELECT COUNT(*) FROM profiles WHERE approval_status = 'rejected'"),
            ],
            'verification_queue' => $scalar("SELECT COUNT(*) FROM verification_requests WHERE status IN ('submitted','in_review')"),
            'matches' => ['active' => $scalar("SELECT COUNT(*) FROM matches WHERE status = 'active'"),
                          'today' => $scalar('SELECT COUNT(*) FROM matches WHERE created_at >= CURDATE())')],
            'messages_today' => $scalar('SELECT COUNT(*) FROM messages WHERE created_at >= CURDATE()'),
            'calls_7d' => Database::all("SELECT status, COUNT(*) AS count FROM calls WHERE created_at > NOW() - INTERVAL 7 DAY GROUP BY status"),
            'community_7d' => [
                'posts' => $scalar("SELECT COUNT(*) FROM community_posts WHERE created_at > NOW() - INTERVAL 7 DAY"),
                'reactions' => $scalar("SELECT COUNT(*) FROM post_reactions WHERE created_at > NOW() - INTERVAL 7 DAY"),
            ],
            'revenue_30d' => Database::all("SELECT gateway, COUNT(*) AS count, COALESCE(SUM(amount_minor),0) AS amount_minor
                                            FROM transactions WHERE status IN ('successful','partially_refunded','refunded') AND verified_at > NOW() - INTERVAL 30 DAY GROUP BY gateway"),
            'active_subscriptions' => $scalar("SELECT COUNT(*) FROM subscriptions WHERE status = 'active' AND ends_at > NOW()"),
            'ads_30d' => Database::all("SELECT event_type, COUNT(*) AS count FROM ad_events WHERE created_at > NOW() - INTERVAL 30 DAY GROUP BY event_type"),
            'open_reports' => $scalar("SELECT COUNT(*) FROM user_reports WHERE status IN ('open','reviewing')"),
            'open_tickets' => $scalar("SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','awaiting_user')"),
        ];
    }

    // ----------------------------------------------------------- announcements
    public function announcements(Request $r): array
    {
        return ['items' => Database::all('SELECT id, title, body, status, published_at, created_at FROM announcements ORDER BY id DESC LIMIT 50')];
    }

    public function createAnnouncement(Request $r): array
    {
        $title = Validator::str($r->input('title'), 'title', 3, 160);
        $body = Validator::str($r->input('body'), 'body', 10, 5000);
        $publish = Validator::boolean($r->input('publish'));
        $id = Database::insert(
            "INSERT INTO announcements (title, body, status, published_at, created_by) VALUES (?, ?, ?, ?, ?)",
            [$title, $body, $publish ? 'published' : 'draft', $publish ? date('Y-m-d H:i:s') : null, $r->userId()]
        );
        Audit::log($r->userId(), 'announcement.created', 'announcement', $id, null, null, ['published' => $publish]);
        Response::status(201);
        return ['id' => $id];
    }

    // ---------------------------------------------------------------- helpers
    private function roleIdsInput(Request $r): array
    {
        $ids = $r->input('role_ids', []);
        if (!is_array($ids) || $ids === []) {
            Validator::fail('role_ids', 'Assign at least one role.');
        }
        $clean = array_values(array_unique(array_map(static fn($v) => Validator::int($v, 'role_ids', 1, PHP_INT_MAX), $ids)));
        $found = array_column(Database::all('SELECT id FROM roles WHERE id IN (' . implode(',', array_fill(0, count($clean), '?')) . ')', $clean), 'id');
        if (count($found) !== count($clean)) {
            Validator::fail('role_ids', 'One or more roles do not exist.');
        }
        return $clean;
    }

    private function permissionCodesInput(Request $r): array
    {
        $codes = $r->input('permissions', []);
        if (!is_array($codes)) {
            Validator::fail('permissions', 'Permissions must be a list.');
        }
        $codes = array_values(array_unique(array_map('strval', $codes)));
        $valid = array_column(Database::all('SELECT code FROM permissions'), 'code');
        foreach ($codes as $code) {
            if (!in_array($code, $valid, true)) {
                Validator::fail('permissions', "Unknown permission: {$code}");
            }
        }
        return $codes;
    }

    /** No privilege escalation: you may only grant roles whose permissions you already hold. */
    private function assertCanGrant(int $actorId, array $roleIds): void
    {
        $slugs = array_column(Database::all('SELECT slug FROM roles WHERE id IN (' . implode(',', array_fill(0, count($roleIds), '?')) . ')', $roleIds), 'slug');
        if (in_array('super_admin', $slugs, true)) {
            $this->requireSuper($actorId);
        }
        $codes = array_column(Database::all(
            'SELECT DISTINCT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id IN (' . implode(',', array_fill(0, count($roleIds), '?')) . ')',
            $roleIds
        ), 'code');
        $this->assertCanGrantPermissions($actorId, $codes);
    }

    private function assertCanGrantPermissions(int $actorId, array $codes): void
    {
        if (Rbac::isSuperAdmin($actorId)) {
            return;
        }
        $held = Rbac::permissionsFor($actorId);
        $missing = array_values(array_diff($codes, $held));
        if ($missing !== []) {
            throw new ApiException(403, 'PRIVILEGE_ESCALATION', 'You cannot grant permissions you do not hold.', ['permissions' => $missing]);
        }
    }

    private function requireSuper(int $actorId): void
    {
        if (!Rbac::isSuperAdmin($actorId)) {
            throw new ApiException(403, 'FORBIDDEN', 'Only the Super Admin can perform this action.');
        }
    }

    private function assertStaff(int $id): void
    {
        if (!Database::value("SELECT 1 FROM users WHERE id = ? AND account_type = 'staff' AND status <> 'deleted'", [$id])) {
            throw new ApiException(404, 'NOT_FOUND', 'Staff account not found.');
        }
    }

    private function hasSuper(int $userId): bool
    {
        return Rbac::isSuperAdmin($userId);
    }

    private function roleSetHasSuper(array $roleIds): bool
    {
        return (bool) Database::value("SELECT 1 FROM roles WHERE slug = 'super_admin' AND id IN (" . implode(',', array_fill(0, count($roleIds), '?')) . ')', $roleIds);
    }

    private function assertNotLastSuperAdmin(int $userId): void
    {
        $count = (int) Database::value(
            "SELECT COUNT(DISTINCT ur.user_id) FROM user_roles ur JOIN roles r ON r.id = ur.role_id
             JOIN users u ON u.id = ur.user_id WHERE r.slug = 'super_admin' AND u.status = 'active'"
        );
        if ($count <= 1 && $this->hasSuper($userId)) {
            throw new ApiException(422, 'LAST_SUPER_ADMIN', 'At least one active Super Admin must remain.');
        }
    }

    private function rolesOf(int $userId): array
    {
        return array_column(Database::all('SELECT r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.slug', [$userId]), 'slug');
    }
}
