<?php

namespace MedCore\Repositories;

use MedCore\Core\Database;

/**
 * Roles, permissions and the role<->permission matrix.
 */
final class RoleRepository
{
    public static function all(): array
    {
        return Database::select('SELECT * FROM roles ORDER BY name');
    }

    public static function find(int $id): ?array
    {
        return Database::first('SELECT * FROM roles WHERE id = ?', [$id]);
    }

    public static function findBySlug(string $slug): ?array
    {
        return Database::first('SELECT * FROM roles WHERE slug = ?', [$slug]);
    }

    public static function allPermissions(): array
    {
        return Database::select('SELECT * FROM permissions ORDER BY grp, slug');
    }

    public static function permissionIdsForRole(int $roleId): array
    {
        $rows = Database::select('SELECT permission_id FROM role_permissions WHERE role_id = ?', [$roleId]);
        return array_map(static fn($r) => (int) $r['permission_id'], $rows);
    }

    public static function syncPermissions(int $roleId, array $permissionIds): void
    {
        Database::transaction(function () use ($roleId, $permissionIds) {
            Database::execute('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
            foreach ($permissionIds as $pid) {
                Database::insert('role_permissions', ['role_id' => $roleId, 'permission_id' => (int) $pid]);
            }
        });
    }

    public static function usersCount(int $roleId): int
    {
        return (int) (Database::first(
            'SELECT COUNT(*) AS c FROM user_roles WHERE role_id = ?',
            [$roleId]
        )['c'] ?? 0);
    }
}
