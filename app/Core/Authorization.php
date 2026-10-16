<?php

namespace MedCore\Core;

use MedCore\Repositories\OrganizationRepository;

/**
 * Role-based access control (RBAC) + record-level access rules.
 *
 * SECURITY PRINCIPLE (from the platform blueprint):
 * Platform administration and clinical-data access are SEPARATE permissions.
 * A super administrator manages users, roles and organizations but does NOT
 * automatically gain access to patient records. Every record read/write must
 * pass an explicit check here — never hide data only in the UI.
 */
final class Authorization
{
    /** @var array<int, array<string,bool>> permission cache per user per request */
    private static array $permissionCache = [];
    /** @var array<int, string[]> role cache */
    private static array $roleCache = [];

    /** All permission slugs held by the user through any role. */
    public static function permissionsOf(int $userId): array
    {
        if (!isset(self::$permissionCache[$userId])) {
            $rows = Database::select(
                'SELECT DISTINCT p.slug
                   FROM permissions p
                   JOIN role_permissions rp ON rp.permission_id = p.id
                   JOIN user_roles ur ON ur.role_id = rp.role_id
                  WHERE ur.user_id = ?',
                [$userId]
            );
            $set = [];
            foreach ($rows as $row) {
                $set[$row['slug']] = true;
            }
            self::$permissionCache[$userId] = $set;
        }
        return array_keys(self::$permissionCache[$userId]);
    }

    public static function rolesOf(int $userId): array
    {
        if (!isset(self::$roleCache[$userId])) {
            $rows = Database::select(
                'SELECT r.slug FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ?',
                [$userId]
            );
            self::$roleCache[$userId] = array_map(static fn($r) => $r['slug'], $rows);
        }
        return self::$roleCache[$userId];
    }

    /** Basic permission check against the current user's roles. */
    public static function can(?int $userId, string $permission): bool
    {
        if ($userId === null) {
            return false;
        }
        return in_array($permission, self::permissionsOf($userId), true);
    }

    /** Check a permission or send a 403 response. */
    public static function authorize(string $permission): void
    {
        if (!self::can(Auth::id(), $permission)) {
            Response::abort(403, 'You do not have permission to perform this action.');
        }
    }

    /* ------------------------------------------------------------------ */
    /* Organization scoping                                                */
    /* ------------------------------------------------------------------ */

    /** Organization IDs the user belongs to. */
    public static function organizationIdsOf(int $userId): array
    {
        $rows = Database::select(
            'SELECT organization_id FROM organization_members WHERE user_id = ?',
            [$userId]
        );
        return array_map(static fn($r) => (int) $r['organization_id'], $rows);
    }

    /**
     * May the current user manage the given organization?
     * Super admins manage any org; org admins manage only orgs they belong to.
     */
    public static function canManageOrganization(int $organizationId): bool
    {
        $userId = Auth::id();
        if ($userId === null) {
            return false;
        }
        if (self::hasRole($userId, 'super_admin')) {
            return self::can($userId, 'organizations.update');
        }
        return self::can($userId, 'organizations.update')
            && in_array($organizationId, self::organizationIdsOf($userId), true);
    }

    public static function hasRole(int $userId, string ...$slugs): bool
    {
        return array_intersect($slugs, self::rolesOf($userId)) !== [];
    }

    /* ------------------------------------------------------------------ */
    /* Patient record access — the critical clinical boundary              */
    /* ------------------------------------------------------------------ */

    /**
     * May the current user view this patient's record?
     *
     * Rules (enforced on every request, backend-side):
     *  1. A patient user may only ever access their OWN linked record.
     *  2. Staff need the patients.view permission AND a care relationship:
     *     same primary organization, or an appointment with a provider in
     *     one of their organizations.
     *  3. Super admin without patients.view is DENIED — administration of
     *     the platform never implies clinical access.
     */
    public static function canViewPatientRecord(int $userId, array $patient): bool
    {
        // 1. Self-service access to the linked record.
        if (!empty($patient['user_id']) && (int) $patient['user_id'] === $userId) {
            return true;
        }

        // 2/3. Clinical permission + care relationship.
        if (!self::can($userId, 'patients.view')) {
            return false;
        }

        $orgIds = self::organizationIdsOf($userId);
        if ($orgIds === []) {
            return false;
        }

        if (!empty($patient['primary_organization_id'])
            && in_array((int) $patient['primary_organization_id'], $orgIds, true)) {
            return true;
        }

        // Appointment-based relationship.
        $placeholders = implode(',', array_fill(0, count($orgIds), '?'));
        $row = Database::first(
            "SELECT id FROM appointments
              WHERE patient_id = ? AND organization_id IN ({$placeholders}) LIMIT 1",
            array_merge([(int) $patient['id']], $orgIds)
        );
        return $row !== null;
    }

    /**
     * Scoping filter for patient lists.
     * Returns [sqlFragment, params] to AND into a WHERE clause.
     * Patient-role users only ever see their own record; staff see records
     * attached to their organizations.
     */
    public static function patientListScope(int $userId): array
    {
        $user = UserRepositoryLike::isPatientRole($userId);
        if ($user) {
            return ['p.user_id = ?', [$userId]];
        }
        $orgIds = self::organizationIdsOf($userId);
        if ($orgIds === []) {
            return ['1 = 0', []]; // sees nothing
        }
        $placeholders = implode(',', array_fill(0, count($orgIds), '?'));
        return ["p.primary_organization_id IN ({$placeholders})", $orgIds];
    }

    public static function clearCache(): void
    {
        self::$permissionCache = [];
        self::$roleCache = [];
    }
}

/**
 * Tiny internal helper kept out of the public API to avoid a circular
 * dependency with the repository layer.
 */
final class UserRepositoryLike
{
    public static function isPatientRole(int $userId): bool
    {
        return Authorization::hasRole($userId, 'patient');
    }
}
