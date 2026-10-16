<?php

namespace MedCore\Repositories;

use MedCore\Core\Database;

/**
 * User accounts, role assignments and login-attempt records.
 */
final class UserRepository
{
    public static function find(int $id): ?array
    {
        return Database::first('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public static function findByEmail(string $email): ?array
    {
        return Database::first('SELECT * FROM users WHERE email = ?', [strtolower(trim($email))]);
    }

    public static function create(array $data): int
    {
        return (int) Database::insert('users', [
            'name'               => $data['name'],
            'email'              => strtolower(trim($data['email'])),
            'phone'              => $data['phone'] ?? null,
            'password_hash'      => $data['password_hash'],
            'status'             => $data['status'] ?? 'active',
            'email_verified_at'  => $data['email_verified_at'] ?? null,
            'email_verify_token' => $data['email_verify_token'] ?? null,
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);
    }

    public static function update(int $id, array $data): void
    {
        $fields = [];
        $params = [];
        foreach (['name', 'email', 'phone', 'status', 'email_verified_at', 'email_verify_token'] as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "$col = ?";
                $params[] = $col === 'email' && is_string($data[$col]) ? strtolower(trim($data[$col])) : $data[$col];
            }
        }
        if ($fields === []) {
            return;
        }
        $fields[] = "updated_at = " . Database::nowExpr();
        $params[] = $id;
        Database::execute('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
    }

    public static function updatePasswordHash(int $id, string $hash): void
    {
        Database::execute(
            'UPDATE users SET password_hash = ?, updated_at = ' . Database::nowExpr() . ' WHERE id = ?',
            [$hash, $id]
        );
    }

    public static function touchLastLogin(int $id): void
    {
        Database::execute(
            'UPDATE users SET last_login_at = ' . Database::nowExpr() . ' WHERE id = ?',
            [$id]
        );
    }

    /**
     * Paginated user directory with optional search + role filter.
     * $orgIds: when provided, restricts to users who are members of those orgs.
     */
    public static function paginate(int $page, int $perPage, string $search = '', ?int $roleId = null, ?array $orgIds = null): array
    {
        $where = '1=1';
        $params = [];

        if ($search !== '') {
            $where .= ' AND (u.name LIKE ? OR u.email LIKE ?)';
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if ($roleId !== null) {
            $where .= ' AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role_id = ?)';
            $params[] = $roleId;
        }
        if ($orgIds !== null) {
            if ($orgIds === []) {
                $where .= ' AND 1=0';
            } else {
                $in = implode(',', array_fill(0, count($orgIds), '?'));
                $where .= " AND EXISTS (SELECT 1 FROM organization_members om WHERE om.user_id = u.id AND om.organization_id IN ($in))";
                $params = array_merge($params, $orgIds);
            }
        }

        $total = (int) (Database::first("SELECT COUNT(*) AS c FROM users u WHERE $where", $params)['c'] ?? 0);

        $offset = ($page - 1) * $perPage;
        $rows = Database::select(
            "SELECT u.* FROM users u WHERE $where ORDER BY u.id ASC LIMIT $perPage OFFSET $offset",
            $params
        );
        foreach ($rows as &$row) {
            $row['roles'] = self::rolesOf((int) $row['id']);
        }
        unset($row);

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public static function rolesOf(int $userId): array
    {
        return Database::select(
            'SELECT r.* FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ? ORDER BY r.name',
            [$userId]
        );
    }

    public static function syncRoles(int $userId, array $roleIds): void
    {
        Database::execute('DELETE FROM user_roles WHERE user_id = ?', [$userId]);
        foreach ($roleIds as $roleId) {
            Database::insert('user_roles', ['user_id' => $userId, 'role_id' => (int) $roleId]);
        }
    }

    public static function addRole(int $userId, int $roleId): void
    {
        $exists = Database::first('SELECT 1 AS x FROM user_roles WHERE user_id = ? AND role_id = ?', [$userId, $roleId]);
        if (!$exists) {
            Database::insert('user_roles', ['user_id' => $userId, 'role_id' => $roleId]);
        }
    }

    public static function recordLoginAttempt(string $email, string $ip, bool $success): void
    {
        Database::insert('login_attempts', [
            'email'        => $email,
            'ip'           => $ip,
            'success'      => $success ? 1 : 0,
            'attempted_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function count(): int
    {
        return (int) (Database::first('SELECT COUNT(*) AS c FROM users')['c'] ?? 0);
    }

    public static function countByStatus(string $status): int
    {
        return (int) (Database::first('SELECT COUNT(*) AS c FROM users WHERE status = ?', [$status])['c'] ?? 0);
    }
}
