<?php
declare(strict_types=1);

namespace App\Core;

/** Writes immutable audit records. The database rejects UPDATE/DELETE on audit_logs. */
final class Audit
{
    private const SENSITIVE = '/password|secret|token|code|key|hash|signature/i';

    public static function log(
        ?int $actorId,
        string $action,
        ?string $targetType = null,
        string|int|null $targetId = null,
        ?string $reason = null,
        ?array $before = null,
        ?array $after = null
    ): void {
        Database::exec(
            'INSERT INTO audit_logs (actor_id, actor_ip, action, target_type, target_id, reason, before_data, after_data)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $actorId,
                self::ip(),
                substr($action, 0, 80),
                $targetType,
                $targetId === null ? null : substr((string) $targetId, 0, 64),
                $reason === null ? null : mb_substr($reason, 0, 500),
                self::encode($before),
                self::encode($after),
            ]
        );
    }

    private static function ip(): ?string
    {
        return $_SERVER['REMOTE_ADDR'] ?? null;
    }

    /** Redacts secrets before anything reaches the audit trail. */
    private static function encode(?array $data): ?string
    {
        if ($data === null) {
            return null;
        }
        return json_encode(self::redact($data), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function redact(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($k) && preg_match(self::SENSITIVE, $k)) {
                $data[$k] = '[REDACTED]';
            } elseif (is_array($v)) {
                $data[$k] = self::redact($v);
            }
        }
        return $data;
    }
}
