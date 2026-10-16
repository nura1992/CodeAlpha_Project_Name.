<?php

namespace MedCore\Repositories;

use MedCore\Core\Database;

/**
 * Healthcare organizations (hospitals, clinics, labs, pharmacies) and
 * their staff memberships.
 */
final class OrganizationRepository
{
    public static function find(int $id): ?array
    {
        return Database::first('SELECT * FROM organizations WHERE id = ?', [$id]);
    }

    public static function create(array $data): int
    {
        return (int) Database::insert('organizations', [
            'name'       => $data['name'],
            'type'       => $data['type'] ?? 'clinic',
            'email'      => $data['email'] ?? null,
            'phone'      => $data['phone'] ?? null,
            'address'    => $data['address'] ?? null,
            'city'       => $data['city'] ?? null,
            'state'      => $data['state'] ?? null,
            'country'    => $data['country'] ?? 'Nigeria',
            'status'     => $data['status'] ?? 'approved',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function update(int $id, array $data): void
    {
        $fields = [];
        $params = [];
        foreach (['name', 'type', 'email', 'phone', 'address', 'city', 'state', 'country', 'status'] as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "$col = ?";
                $params[] = $data[$col];
            }
        }
        if ($fields === []) {
            return;
        }
        $fields[] = 'updated_at = ' . Database::nowExpr();
        $params[] = $id;
        Database::execute('UPDATE organizations SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
    }

    public static function paginate(int $page, int $perPage, string $search = '', ?array $restrictToIds = null): array
    {
        $where = '1=1';
        $params = [];
        if ($search !== '') {
            $where .= ' AND (name LIKE ? OR city LIKE ? OR state LIKE ?)';
            $like = '%' . $search . '%';
            $params = array_merge($params, [$like, $like, $like]);
        }
        if ($restrictToIds !== null) {
            if ($restrictToIds === []) {
                $where .= ' AND 1=0';
            } else {
                $where .= ' AND id IN (' . implode(',', array_fill(0, count($restrictToIds), '?')) . ')';
                $params = array_merge($params, $restrictToIds);
            }
        }
        $total = (int) (Database::first("SELECT COUNT(*) AS c FROM organizations WHERE $where", $params)['c'] ?? 0);
        $offset = ($page - 1) * $perPage;
        $rows = Database::select("SELECT * FROM organizations WHERE $where ORDER BY name LIMIT $perPage OFFSET $offset", $params);
        foreach ($rows as &$row) {
            $row['member_count'] = (int) (Database::first(
                'SELECT COUNT(*) AS c FROM organization_members WHERE organization_id = ?',
                [$row['id']]
            )['c'] ?? 0);
        }
        unset($row);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public static function allActive(): array
    {
        return Database::select("SELECT * FROM organizations WHERE status = 'approved' ORDER BY name");
    }

    public static function members(int $organizationId): array
    {
        return Database::select(
            'SELECT om.*, u.name, u.email, u.status AS user_status
               FROM organization_members om
               JOIN users u ON u.id = om.user_id
              WHERE om.organization_id = ?
              ORDER BY u.name',
            [$organizationId]
        );
    }

    public static function addMember(int $organizationId, int $userId, ?string $jobTitle = null): bool
    {
        $exists = Database::first(
            'SELECT id FROM organization_members WHERE organization_id = ? AND user_id = ?',
            [$organizationId, $userId]
        );
        if ($exists) {
            return false;
        }
        Database::insert('organization_members', [
            'organization_id' => $organizationId,
            'user_id'         => $userId,
            'job_title'       => $jobTitle,
            'is_primary'      => 1,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);
        return true;
    }

    public static function removeMember(int $organizationId, int $userId): void
    {
        Database::execute(
            'DELETE FROM organization_members WHERE organization_id = ? AND user_id = ?',
            [$organizationId, $userId]
        );
    }

    public static function count(): int
    {
        return (int) (Database::first('SELECT COUNT(*) AS c FROM organizations')['c'] ?? 0);
    }
}
