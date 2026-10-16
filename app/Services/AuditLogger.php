<?php

namespace MedCore\Services;

use MedCore\Core\Auth;
use MedCore\Core\Database;
use MedCore\Core\Request;

/**
 * Append-only audit trail.
 *
 * Logs WHO did WHAT to WHICH record, from WHERE, WHEN.
 * Never log passwords, tokens, session data or full clinical payloads —
 * only references and short, necessary metadata.
 */
final class AuditLogger
{
    public static function log(
        ?int $userId,
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?array $details = null,
        ?Request $request = null
    ): void {
        $request = $request ?? new Request();

        // Never persist secrets in the audit trail.
        if (is_array($details)) {
            foreach (['password', 'password_confirmation', 'token', '_token', 'secret', 'key'] as $sensitive) {
                unset($details[$sensitive]);
            }
        }

        Database::insert('audit_logs', [
            'user_id'     => $userId ?? Auth::id(),
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'details'     => $details === null ? null : json_encode($details, JSON_UNESCAPED_SLASHES),
            'ip'          => $request->ip(),
            'user_agent'  => $request->userAgent(),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }
}
