<?php

namespace MedCore\Repositories;

use MedCore\Core\Database;

/**
 * MediID patient records.
 *
 * The internal auto-increment id is the permanent primary key; the public
 * patient_code (e.g. MCB-2026-00042) is the human-facing identifier.
 * Never use email/phone as an identifier — they change and can repeat.
 */
final class PatientRepository
{
    public static function find(int $id): ?array
    {
        return Database::first('SELECT * FROM patients WHERE id = ?', [$id]);
    }

    public static function findByUserId(int $userId): ?array
    {
        return Database::first('SELECT * FROM patients WHERE user_id = ?', [$userId]);
    }

    public static function create(array $data): int
    {
        return (int) Database::transaction(function () use ($data) {
            $id = (int) Database::insert('patients', [
                'patient_code'           => 'TMP',
                'user_id'                => $data['user_id'] ?? null,
                'primary_organization_id' => $data['primary_organization_id'] ?? null,
                'first_name'             => $data['first_name'],
                'last_name'              => $data['last_name'],
                'date_of_birth'          => $data['date_of_birth'] ?? null,
                'sex'                    => $data['sex'] ?? null,
                'phone'                  => $data['phone'] ?? null,
                'blood_group'            => $data['blood_group'] ?? null,
                'allergies'              => $data['allergies'] ?? null,
                'emergency_contact'      => $data['emergency_contact'] ?? null,
                'consent_recorded_at'    => $data['consent_recorded_at'] ?? null,
                'status'                 => 'active',
                'created_by'             => $data['created_by'] ?? null,
                'created_at'             => date('Y-m-d H:i:s'),
                'updated_at'             => date('Y-m-d H:i:s'),
            ]);
            Database::execute(
                'UPDATE patients SET patient_code = ? WHERE id = ?',
                [self::generateCode($id), $id]
            );
            return $id;
        });
    }

    public static function generateCode(int $id): string
    {
        return 'MCB-' . date('Y') . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    public static function update(int $id, array $data): void
    {
        $fields = [];
        $params = [];
        foreach ([
            'first_name', 'last_name', 'date_of_birth', 'sex', 'phone',
            'blood_group', 'allergies', 'emergency_contact',
            'primary_organization_id', 'consent_recorded_at', 'status',
        ] as $col) {
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
        Database::execute('UPDATE patients SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
    }

    /**
     * Paginated list. $scope is [sqlFragment, params] from
     * Authorization::patientListScope() — ALWAYS applied, never optional.
     */
    public static function paginate(int $page, int $perPage, string $search, array $scope): array
    {
        [$scopeSql, $scopeParams] = $scope;
        $where = "p.status = 'active' AND $scopeSql";
        $params = $scopeParams;
        if ($search !== '') {
            $where .= ' AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.patient_code LIKE ? OR p.phone LIKE ?)';
            $like = '%' . $search . '%';
            $params = array_merge($params, [$like, $like, $like, $like]);
        }
        $total = (int) (Database::first("SELECT COUNT(*) AS c FROM patients p WHERE $where", $params)['c'] ?? 0);
        $offset = ($page - 1) * $perPage;
        $rows = Database::select(
            "SELECT p.*, o.name AS organization_name
               FROM patients p
               LEFT JOIN organizations o ON o.id = p.primary_organization_id
              WHERE $where
              ORDER BY p.id DESC
              LIMIT $perPage OFFSET $offset",
            $params
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public static function count(): int
    {
        return (int) (Database::first("SELECT COUNT(*) AS c FROM patients WHERE status = 'active'")['c'] ?? 0);
    }
}
