<?php

namespace MedCore\Repositories;

use MedCore\Core\Database;

/**
 * MediLink care providers (bookable clinicians of an organization).
 */
final class ProviderRepository
{
    public static function find(int $id): ?array
    {
        return Database::first('SELECT * FROM providers WHERE id = ?', [$id]);
    }

    public static function create(array $data): int
    {
        return (int) Database::insert('providers', [
            'organization_id' => $data['organization_id'],
            'user_id'         => $data['user_id'] ?? null,
            'full_name'       => $data['full_name'],
            'title'           => $data['title'] ?? null,
            'specialty'       => $data['specialty'],
            'email'           => $data['email'] ?? null,
            'phone'           => $data['phone'] ?? null,
            'location'        => $data['location'] ?? null,
            'bio'             => $data['bio'] ?? null,
            'status'          => $data['status'] ?? 'active',
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);
    }

    public static function update(int $id, array $data): void
    {
        $fields = [];
        $params = [];
        foreach (['organization_id', 'user_id', 'full_name', 'title', 'specialty', 'email', 'phone', 'location', 'bio', 'status'] as $col) {
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
        Database::execute('UPDATE providers SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
    }

    /**
     * Internal management list (org-scoped for non super-admins).
     */
    public static function paginate(int $page, int $perPage, string $search, ?array $orgIds): array
    {
        $where = '1=1';
        $params = [];
        if ($search !== '') {
            $where .= ' AND (pr.full_name LIKE ? OR pr.specialty LIKE ? OR pr.location LIKE ?)';
            $like = '%' . $search . '%';
            $params = array_merge($params, [$like, $like, $like]);
        }
        if ($orgIds !== null) {
            if ($orgIds === []) {
                $where .= ' AND 1=0';
            } else {
                $where .= ' AND pr.organization_id IN (' . implode(',', array_fill(0, count($orgIds), '?')) . ')';
                $params = array_merge($params, $orgIds);
            }
        }
        $total = (int) (Database::first("SELECT COUNT(*) AS c FROM providers pr WHERE $where", $params)['c'] ?? 0);
        $offset = ($page - 1) * $perPage;
        $rows = Database::select(
            "SELECT pr.*, o.name AS organization_name
               FROM providers pr
               LEFT JOIN organizations o ON o.id = pr.organization_id
              WHERE $where ORDER BY pr.full_name LIMIT $perPage OFFSET $offset",
            $params
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * Public directory search — only non-sensitive listing fields.
     */
    public static function searchDirectory(string $q = '', string $specialty = '', string $location = ''): array
    {
        $where = "pr.status = 'active'";
        $params = [];
        if ($q !== '') {
            $where .= ' AND (pr.full_name LIKE ? OR pr.specialty LIKE ?)';
            $like = '%' . $q . '%';
            $params = array_merge($params, [$like, $like]);
        }
        if ($specialty !== '') {
            $where .= ' AND pr.specialty LIKE ?';
            $params[] = '%' . $specialty . '%';
        }
        if ($location !== '') {
            $where .= ' AND (pr.location LIKE ? OR o.city LIKE ? OR o.state LIKE ?)';
            $like = '%' . $location . '%';
            $params = array_merge($params, [$like, $like, $like]);
        }
        return Database::select(
            "SELECT pr.id, pr.full_name, pr.title, pr.specialty, pr.location, pr.bio,
                    o.name AS organization_name, o.city, o.state
               FROM providers pr
               LEFT JOIN organizations o ON o.id = pr.organization_id
              WHERE $where
              ORDER BY pr.full_name
              LIMIT 60",
            $params
        );
    }

    public static function distinctSpecialties(): array
    {
        $rows = Database::select(
            "SELECT DISTINCT specialty FROM providers WHERE status = 'active' AND specialty <> '' ORDER BY specialty"
        );
        return array_map(static fn($r) => $r['specialty'], $rows);
    }

    public static function activeByOrganization(?array $orgIds): array
    {
        $where = "status = 'active'";
        $params = [];
        if ($orgIds !== null) {
            if ($orgIds === []) {
                return [];
            }
            $where .= ' AND organization_id IN (' . implode(',', array_fill(0, count($orgIds), '?')) . ')';
            $params = $orgIds;
        }
        return Database::select("SELECT * FROM providers WHERE $where ORDER BY full_name", $params);
    }

    public static function count(): int
    {
        return (int) (Database::first("SELECT COUNT(*) AS c FROM providers WHERE status = 'active'")['c'] ?? 0);
    }
}
