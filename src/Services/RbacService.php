<?php
/**
 * RbacService — role-based access control for staff.
 *
 * The Super Admin (role slug 'super_admin' or any role flagged is_super)
 * bypasses all permission checks. Every other staff member is authorised by
 * the union of permissions attached to their roles. All checks happen
 * server-side; the UI only mirrors them.
 */

declare(strict_types=1);

namespace CodeAlpha\Services;

use CodeAlpha\Core\Database;

final class RbacService
{
    /** @var array<string, array<string, mixed>> */
    private static ?array $catalog = null;
    /** @var array<int, array<string, bool>> */
    private static array $permissionCache = [];

    /**
     * The full permission catalog. These rows are seeded into the
     * permissions table by the installer. Slugs are stable identifiers.
     */
    public static function catalog(): array
    {
        if (self::$catalog !== null) {
            return self::$catalog;
        }
        self::$catalog = [
            // Dashboard access
            'dashboard.super_admin' => ['name' => 'Access Super Admin dashboard', 'category' => 'dashboards', 'description' => 'Full system control area.'],
            'dashboard.admin'       => ['name' => 'Access Admin dashboard', 'category' => 'dashboards', 'description' => 'Users, profiles and moderation area.'],
            'dashboard.finance'     => ['name' => 'Access Finance dashboard', 'category' => 'dashboards', 'description' => 'Payments, plans and refunds area.'],
            'dashboard.support'     => ['name' => 'Access Support dashboard', 'category' => 'dashboards', 'description' => 'Tickets, reports and appeals area.'],
            // Users
            'users.view'            => ['name' => 'View users', 'category' => 'users', 'description' => 'View permitted user records.'],
            'users.suspend'         => ['name' => 'Suspend users', 'category' => 'users', 'description' => 'Suspend and reinstate member accounts.'],
            'users.delete'          => ['name' => 'Delete users', 'category' => 'users', 'description' => 'Soft-delete member accounts.'],
            'users.impersonate'     => ['name' => 'Impersonate users', 'category' => 'users', 'description' => 'Sign in as a member for support (audited).'],
            // Staff & roles
            'staff.manage'          => ['name' => 'Manage staff', 'category' => 'staff', 'description' => 'Create, edit, suspend and delete staff accounts.'],
            'roles.manage'          => ['name' => 'Manage roles', 'category' => 'staff', 'description' => 'Create custom roles and assign granular permissions.'],
            // Verification
            'verification.review'   => ['name' => 'Review verification', 'category' => 'verification', 'description' => 'Open assigned verification cases and view verification videos.'],
            'verification.approve'  => ['name' => 'Approve verification', 'category' => 'verification', 'description' => 'Approve verified profiles.'],
            'verification.reject'   => ['name' => 'Reject verification', 'category' => 'verification', 'description' => 'Reject verification with a reason.'],
            // Moderation & community
            'moderation.review'     => ['name' => 'Review moderation queue', 'category' => 'moderation', 'description' => 'Work the user-report review queue.'],
            'moderation.action'     => ['name' => 'Moderation actions', 'category' => 'moderation', 'description' => 'Remove content, warn or suspend from reports.'],
            'community.manage'      => ['name' => 'Manage community', 'category' => 'community', 'description' => 'Edit announcements, guidelines, topics; remove content.'],
            // Payments
            'payments.view'         => ['name' => 'View payments', 'category' => 'payments', 'description' => 'View payment records, subscriptions and references.'],
            'payments.refund'       => ['name' => 'Process refunds', 'category' => 'payments', 'description' => 'Process authorized refunds.'],
            'payments.configure'    => ['name' => 'Configure payments', 'category' => 'payments', 'description' => 'Configure gateways, plans and pricing.'],
            'payments.reports'      => ['name' => 'Financial reports', 'category' => 'payments', 'description' => 'Configure and download financial reports.'],
            // Campaigns & ads
            'campaigns.manage'      => ['name' => 'Manage campaigns', 'category' => 'campaigns', 'description' => 'Create and schedule campaigns and promo codes.'],
            'advertisements.manage' => ['name' => 'Manage advertisements', 'category' => 'campaigns', 'description' => 'Create, review and suspend adverts.'],
            // System
            'features.manage'       => ['name' => 'Manage features', 'category' => 'system', 'description' => 'Toggle system modules and kill switches.'],
            'settings.manage'       => ['name' => 'Manage settings', 'category' => 'system', 'description' => 'Change protected configuration.'],
            'audit.view'            => ['name' => 'View audit logs', 'category' => 'system', 'description' => 'View audit logs and system analytics.'],
            'analytics.view'        => ['name' => 'View analytics', 'category' => 'system', 'description' => 'View system analytics and reports.'],
            // Support
            'support.tickets'       => ['name' => 'Handle tickets', 'category' => 'support', 'description' => 'View and reply to support tickets.'],
            'support.appeals'       => ['name' => 'Handle appeals', 'category' => 'support', 'description' => 'Review account recovery and verification appeals.'],
            'support.reports'       => ['name' => 'Escalate reports', 'category' => 'support', 'description' => 'Report suspicious accounts to moderators.'],
        ];
        return self::$catalog;
    }

    public static function isSuperAdmin(?int $userId): bool
    {
        if (!$userId) {
            return false;
        }
        if (!\CodeAlpha\Core\Auth::user()) {
            // During CLI/install contexts there may be no session.
        }
        $count = Database::scalar(
            'SELECT COUNT(*) FROM user_roles ur
               JOIN roles r ON r.id = ur.role_id
              WHERE ur.user_id = :uid AND r.is_super = 1',
            ['uid' => $userId]
        );
        if ($count) {
            return true;
        }
        // Legacy: a role literally named super_admin also counts.
        return (bool) Database::scalar(
            'SELECT COUNT(*) FROM user_roles ur
               JOIN roles r ON r.id = ur.role_id
              WHERE ur.user_id = :uid AND r.slug = :slug',
            ['uid' => $userId, 'slug' => 'super_admin']
        );
    }

    /**
     * All permissions for a user (slugs). Super Admins get the full catalog.
     *
     * @return array<string, bool>
     */
    public static function permissionsFor(?int $userId): array
    {
        if (!$userId) {
            return [];
        }
        if (self::isSuperAdmin($userId)) {
            return array_fill_keys(array_keys(self::catalog()), true);
        }
        if (isset(self::$permissionCache[$userId])) {
            return self::$permissionCache[$userId];
        }
        $rows = Database::all(
            'SELECT DISTINCT p.slug FROM user_roles ur
               JOIN role_permissions rp ON rp.role_id = ur.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE ur.user_id = :uid',
            ['uid' => $userId]
        );
        $perms = [];
        foreach ($rows as $row) {
            $perms[$row['slug']] = true;
        }
        self::$permissionCache[$userId] = $perms;
        return $perms;
    }

    public static function can(?int $userId, string $permission): bool
    {
        if (!$userId) {
            return false;
        }
        $perms = self::permissionsFor($userId);
        return !empty($perms[$permission]);
    }

    public static function hasRole(?int $userId, string $slug): bool
    {
        if (!$userId) {
            return false;
        }
        return (bool) Database::scalar(
            'SELECT COUNT(*) FROM user_roles ur
               JOIN roles r ON r.id = ur.role_id
              WHERE ur.user_id = :uid AND r.slug = :slug',
            ['uid' => $userId, 'slug' => $slug]
        );
    }

    /** Roles held by a user. */
    public static function rolesFor(?int $userId): array
    {
        if (!$userId) {
            return [];
        }
        return Database::all(
            'SELECT r.* FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :uid ORDER BY r.id',
            ['uid' => $userId]
        );
    }

    /** Dashboards the staff member may enter (for sidebar rendering). */
    public static function dashboardsFor(?int $userId): array
    {
        if (self::isSuperAdmin($userId)) {
            return ['super_admin', 'admin', 'finance', 'support'];
        }
        $rows = Database::all(
            'SELECT DISTINCT r.dashboard FROM user_roles ur
               JOIN roles r ON r.id = ur.role_id
              WHERE ur.user_id = :uid AND r.dashboard IS NOT NULL',
            ['uid' => $userId]
        );
        return array_values(array_filter(array_column($rows, 'dashboard')));
    }

    public static function assignRole(int $userId, int $roleId, ?int $assignedBy): void
    {
        $exists = Database::one(
            'SELECT 1 FROM user_roles WHERE user_id = :u AND role_id = :r',
            ['u' => $userId, 'r' => $roleId]
        );
        if (!$exists) {
            Database::insert('user_roles', [
                'user_id' => $userId, 'role_id' => $roleId, 'assigned_by' => $assignedBy, 'assigned_at' => date('Y-m-d H:i:s'),
            ]);
        }
        unset(self::$permissionCache[$userId]);
    }

    public static function removeRole(int $userId, int $roleId): void
    {
        Database::delete('user_roles', 'user_id = :u AND role_id = :r', ['u' => $userId, 'r' => $roleId]);
        unset(self::$permissionCache[$userId]);
    }

    /** Replace the permission set of a role. */
    public static function setRolePermissions(int $roleId, array $permissionSlugs): void
    {
        Database::delete('role_permissions', 'role_id = :r', ['r' => $roleId]);
        $ids = Database::all('SELECT id, slug FROM permissions');
        $bySlug = [];
        foreach ($ids as $row) {
            $bySlug[$row['slug']] = (int) $row['id'];
        }
        foreach (array_unique($permissionSlugs) as $slug) {
            if (isset($bySlug[$slug])) {
                Database::insert('role_permissions', [
                    'role_id' => $roleId, 'permission_id' => $bySlug[$slug], 'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
        self::$permissionCache = [];
    }

    /** Seed the permission catalog into the database (idempotent). */
    public static function seedPermissions(): void
    {
        foreach (self::catalog() as $slug => $meta) {
            $existing = Database::one('SELECT id FROM permissions WHERE slug = :s', ['s' => $slug]);
            if ($existing) {
                Database::update('permissions', [
                    'name' => $meta['name'], 'category' => $meta['category'], 'description' => $meta['description'],
                ], 'slug = :s', ['s' => $slug]);
            } else {
                Database::insert('permissions', [
                    'slug' => $slug, 'name' => $meta['name'], 'category' => $meta['category'], 'description' => $meta['description'],
                ]);
            }
        }
    }
}
