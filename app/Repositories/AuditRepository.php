<?php

namespace MedCore\Repositories;

use MedCore\Core\Database;

/**
 * Read-side of the audit trail (write-side is Services\AuditLogger).
 */
final class AuditRepository
{
    public static function paginate(int $page, int $perPage, string $action = '', string $search = '', string $date = ''): array
    {
        $where = '1=1';
        $params = [];
        if ($action !== '') {
            $where .= ' AND a.action LIKE ?';
            $params[] = $action . '%';
        }
        if ($search !== '') {
            $where .= ' AND (u.email LIKE ? OR u.name LIKE ? OR a.entity_type LIKE ?)';
            $like = '%' . $search . '%';
            $params = array_merge($params, [$like, $like, $like]);
        }
        if ($date !== '') {
            $where .= ' AND substr(a.created_at, 1, 10) = ?';
            $params[] = $date;
        }
        $base = "FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE $where";
        $total = (int) (Database::first("SELECT COUNT(*) AS c $base", $params)['c'] ?? 0);
        $offset = ($page - 1) * $perPage;
        $rows = Database::select(
            "SELECT a.*, u.name AS user_name, u.email AS user_email $base ORDER BY a.id DESC LIMIT $perPage OFFSET $offset",
            $params
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public static function latest(int $limit = 10): array
    {
        return Database::select(
            'SELECT a.*, u.name AS user_name FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id
             ORDER BY a.id DESC LIMIT ' . max(1, $limit)
        );
    }

    public static function failedLoginsSince(int $minutes): int
    {
        return (int) (Database::first(
            'SELECT COUNT(*) AS c FROM audit_logs WHERE action = ? AND created_at >= ' . Database::minutesAgo($minutes),
            ['auth.login_failed']
        )['c'] ?? 0);
    }

    public static function distinctActions(): array
    {
        $rows = Database::select('SELECT DISTINCT action FROM audit_logs ORDER BY action');
        return array_map(static fn($r) => $r['action'], $rows);
    }
}
