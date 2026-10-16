<?php

namespace MedCore\Repositories;

use MedCore\Core\Database;

/**
 * MediLink appointments.
 */
final class AppointmentRepository
{
    public const STATUSES = ['booked', 'confirmed', 'rescheduled', 'completed', 'cancelled', 'declined', 'no_show'];

    public static function find(int $id): ?array
    {
        return Database::first(
            'SELECT a.*, p.first_name, p.last_name, p.patient_code,
                    pr.full_name AS provider_name, pr.specialty AS provider_specialty,
                    o.name AS organization_name
               FROM appointments a
               JOIN patients p ON p.id = a.patient_id
               JOIN providers pr ON pr.id = a.provider_id
               LEFT JOIN organizations o ON o.id = a.organization_id
              WHERE a.id = ?',
            [$id]
        );
    }

    public static function create(array $data): int
    {
        return (int) Database::transaction(function () use ($data) {
            $id = (int) Database::insert('appointments', [
                'reference'       => 'TMP',
                'patient_id'      => $data['patient_id'],
                'provider_id'     => $data['provider_id'],
                'organization_id' => $data['organization_id'],
                'scheduled_at'    => $data['scheduled_at'],
                'duration_min'    => $data['duration_min'] ?? 30,
                'status'          => $data['status'] ?? 'booked',
                'reason'          => $data['reason'] ?? null,
                'notes'           => $data['notes'] ?? null,
                'created_by'      => $data['created_by'] ?? null,
                'created_at'      => date('Y-m-d H:i:s'),
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);
            Database::execute(
                'UPDATE appointments SET reference = ? WHERE id = ?',
                ['APT-' . date('Y') . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT), $id]
            );
            return $id;
        });
    }

    public static function setStatus(int $id, string $status, ?string $notes = null): void
    {
        Database::execute(
            'UPDATE appointments SET status = ?, notes = COALESCE(?, notes), updated_at = ' . Database::nowExpr() . ' WHERE id = ?',
            [$status, $notes, $id]
        );
    }

    /**
     * Scoped list. $scope is [sql, params]:
     *  - patient role:   a.patient_id = own patient id
     *  - staff:          a.organization_id IN (their orgs)
     *  - super admin:    no scope (operational overview; no clinical fields)
     */
    public static function paginate(int $page, int $perPage, array $scope, string $status = '', string $date = ''): array
    {
        [$scopeSql, $params] = $scope;
        $where = $scopeSql;
        if ($status !== '' && in_array($status, self::STATUSES, true)) {
            $where .= ' AND a.status = ?';
            $params[] = $status;
        }
        if ($date !== '') {
            $where .= ' AND substr(a.scheduled_at, 1, 10) = ?';
            $params[] = $date;
        }
        $base = "FROM appointments a
                 JOIN patients p ON p.id = a.patient_id
                 JOIN providers pr ON pr.id = a.provider_id
                 LEFT JOIN organizations o ON o.id = a.organization_id
                 WHERE $where";
        $total = (int) (Database::first("SELECT COUNT(*) AS c $base", $params)['c'] ?? 0);
        $offset = ($page - 1) * $perPage;
        $rows = Database::select(
            "SELECT a.*, p.first_name, p.last_name, p.patient_code,
                    pr.full_name AS provider_name, pr.specialty AS provider_specialty,
                    o.name AS organization_name
             $base
             ORDER BY a.scheduled_at DESC
             LIMIT $perPage OFFSET $offset",
            $params
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public static function upcomingForPatient(int $patientId, int $limit = 5): array
    {
        if (Database::driver() === 'sqlite') {
            $future = "a.scheduled_at >= datetime('now')";
        } else {
            $future = 'a.scheduled_at >= NOW()';
        }
        return Database::select(
            "SELECT a.*, pr.full_name AS provider_name, pr.specialty AS provider_specialty, o.name AS organization_name
               FROM appointments a
               JOIN providers pr ON pr.id = a.provider_id
               LEFT JOIN organizations o ON o.id = a.organization_id
              WHERE a.patient_id = ? AND a.status IN ('booked','confirmed','rescheduled') AND $future
              ORDER BY a.scheduled_at ASC
              LIMIT " . max(1, $limit),
            [$patientId]
        );
    }

    public static function todayForOrgs(array $orgIds): array
    {
        if ($orgIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($orgIds), '?'));
        $today = Database::driver() === 'sqlite' ? "date('now')" : 'CURDATE()';
        return Database::select(
            "SELECT a.*, p.first_name, p.last_name, pr.full_name AS provider_name
               FROM appointments a
               JOIN patients p ON p.id = a.patient_id
               JOIN providers pr ON pr.id = a.provider_id
              WHERE a.organization_id IN ($in)
                AND substr(a.scheduled_at, 1, 10) = $today
              ORDER BY a.scheduled_at ASC",
            $orgIds
        );
    }

    /** Appointments per day for the next N days (dashboard chart). */
    public static function dailyCounts(int $days = 14): array
    {
        if (Database::driver() === 'sqlite') {
            $range = "a.scheduled_at >= datetime('now', '-' || ? || ' days')";
        } else {
            $range = 'a.scheduled_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
        }
        $rows = Database::select(
            "SELECT substr(a.scheduled_at, 1, 10) AS day, COUNT(*) AS c
               FROM appointments a WHERE $range GROUP BY day ORDER BY day",
            [$days]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[$row['day']] = (int) $row['c'];
        }
        return $out;
    }

    public static function countToday(): int
    {
        $today = Database::driver() === 'sqlite' ? "date('now')" : 'CURDATE()';
        return (int) (Database::first(
            "SELECT COUNT(*) AS c FROM appointments WHERE substr(scheduled_at, 1, 10) = $today"
        )['c'] ?? 0);
    }

    public static function count(): int
    {
        return (int) (Database::first('SELECT COUNT(*) AS c FROM appointments')['c'] ?? 0);
    }
}
