<?php
/**
 * AuditLog — immutable record of sensitive administrative and security
 * actions. Writes are fire-and-forget but never throw into the request path.
 */

declare(strict_types=1);

namespace CodeAlpha\Core;

final class AuditLog
{
    public static function record(
        ?int $actorId,
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        array $details = [],
        ?Request $request = null
    ): void {
        try {
            $request = $request ?? new Request();
            $actor = Auth::user();
            Database::insert('audit_logs', [
                'actor_id'    => $actorId ?? Auth::id(),
                'actor_type'  => $actor && (int) ($actor['is_staff'] ?? 0) === 1 ? 'staff' : 'user',
                'action'      => substr($action, 0, 100),
                'target_type' => $targetType ? substr($targetType, 0, 60) : null,
                'target_id'   => $targetId !== null ? substr((string) $targetId, 0, 64) : null,
                'details'     => $details === [] ? null : json_encode($details, JSON_UNESCAPED_SLASHES),
                'ip_address'  => substr($request->ip(), 0, 45),
                'user_agent'  => substr($request->userAgent(), 0, 255),
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('[CodeAlpha] audit log failure: ' . $e->getMessage());
        }
    }
}
