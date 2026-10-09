<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Role-Based Access Control. Permissions are checked on the server for every
 * sensitive action. Hidden buttons in the UI are a convenience, never a control.
 */
final class Rbac
{
    private static array $cache = [];

    /** @return string[] permission codes held by the user (all codes for super_admin). */
    public static function permissionsFor(int $userId): array
    {
        if (isset(self::$cache[$userId])) {
            return self::$cache[$userId];
        }
        $isSuper = (bool) Database::value(
            "SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = ? AND r.slug = 'super_admin' LIMIT 1",
            [$userId]
        );
        if ($isSuper) {
            $codes = array_column(Database::all('SELECT code FROM permissions'), 'code');
        } else {
            $codes = array_column(Database::all(
                'SELECT DISTINCT p.code FROM user_roles ur
                 JOIN role_permissions rp ON rp.role_id = ur.role_id
                 JOIN permissions p ON p.id = rp.permission_id
                 JOIN users u ON u.id = ur.user_id
                 WHERE ur.user_id = ? AND u.account_type = "staff" AND u.status = "active"',
                [$userId]
            ), 'code');
        }
        return self::$cache[$userId] = $codes;
    }

    public static function isSuperAdmin(int $userId): bool
    {
        return (bool) Database::value(
            "SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.slug = 'super_admin' LIMIT 1",
            [$userId]
        );
    }

    public static function can(int $userId, string $permission): bool
    {
        return in_array($permission, self::permissionsFor($userId), true);
    }

    public static function require(int $userId, string $permission): void
    {
        if (!self::can($userId, $permission)) {
            Audit::log($userId, 'access.denied', 'permission', $permission);
            throw new ApiException(403, 'FORBIDDEN', 'You do not have permission to perform this action.', ['permission' => $permission]);
        }
    }

    public static function forgetCache(int $userId): void
    {
        unset(self::$cache[$userId]);
    }
}
