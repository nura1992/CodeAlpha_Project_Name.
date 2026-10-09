<?php
/**
 * CallService — audio/video call state machine and signalling store.
 *
 * Real-time media runs over WebRTC. Signalling is designed for a dedicated
 * WebSocket service (tools/signaling-server.mjs); this service provides the
 * REST fallback (poll/store signals) and, most importantly, enforces the
 * call permission rules server-side:
 *
 *   - calls only between eligible, approved, opposite-gender members
 *   - an active match is required
 *   - blocking (full block or call-block) prevents calls
 *   - rate limits prevent call spam
 *   - full call history: incoming, outgoing, missed, declined, ended
 */

declare(strict_types=1);

namespace CodeAlpha\Services;

use CodeAlpha\Core\Database;
use CodeAlpha\Core\FeatureFlags;
use CodeAlpha\Core\RateLimiter;

final class CallService
{
    private const RING_TIMEOUT_SECONDS = 45;

    /**
     * Initiate a call. Returns the call row.
     */
    public static function initiate(int $callerId, int $calleeId, string $type = 'audio'): array
    {
        if ($callerId === $calleeId) {
            throw new \InvalidArgumentException('You cannot call yourself.');
        }
        $feature = $type === 'video' ? 'video_calls' : 'audio_calls';
        if (!FeatureFlags::isEnabled($feature)) {
            throw new \InvalidArgumentException(ucfirst($type) . ' calls are currently disabled.');
        }

        $limit = RateLimiter::hit('call:' . $callerId, 20, 3600);
        if (!$limit['allowed']) {
            RateLimiter::tooManyRequests($limit);
        }

        $caller = Database::one('SELECT * FROM users WHERE id = :id', ['id' => $callerId]);
        $callee = Database::one('SELECT * FROM users WHERE id = :id AND deleted_at IS NULL', ['id' => $calleeId]);
        if (!$callee) {
            throw new \InvalidArgumentException('User not found.');
        }
        if (!MatchingService::canInteract($callerId, $callee)) {
            throw new \InvalidArgumentException('You can only call your matches.');
        }
        if (!MatchingService::hasActiveMatch($callerId, $calleeId)) {
            throw new \InvalidArgumentException('You can only call active matches.');
        }
        if (self::isCallBlocked($calleeId, $callerId)) {
            throw new \InvalidArgumentException('This member is not accepting calls.');
        }
        // A callee already in a call cannot be rung.
        $busy = Database::scalar(
            "SELECT COUNT(*) FROM calls
              WHERE status = 'ringing' AND (
                    (caller_id = :a AND callee_id = :b) OR (caller_id = :b AND callee_id = :a)
              )",
            ['a' => $callerId, 'b' => $calleeId]
        );
        if ($busy) {
            throw new \InvalidArgumentException('The other member is on another call.');
        }

        $now = date('Y-m-d H:i:s');
        $callId = (int) Database::insert('calls', [
            'caller_id'  => $callerId,
            'callee_id'  => $calleeId,
            'type'       => $type,
            'status'     => 'ringing',
            'created_at' => $now,
        ]);

        NotificationService::notify(
            $calleeId,
            'call_incoming',
            'Incoming ' . $type . ' call',
            '',
            ['call_id' => $callId, 'caller_id' => $callerId, 'type' => $type]
        );

        return self::find($callId);
    }

    public static function accept(int $userId, int $callId): array
    {
        $call = self::participantCall($userId, $callId);
        if ((int) $call['callee_id'] !== $userId) {
            throw new \InvalidArgumentException('Only the callee can accept.');
        }
        if ($call['status'] !== 'ringing') {
            throw new \InvalidArgumentException('This call is no longer ringing.');
        }
        $now = date('Y-m-d H:i:s');
        Database::update('calls', [
            'status'     => 'accepted',
            'answered_at' => $now,
            'started_at' => $now,
        ], 'id = :id', ['id' => $callId]);
        self::signal($userId, $callId, 'accept', null);
        return self::find($callId);
    }

    public static function decline(int $userId, int $callId): array
    {
        $call = self::participantCall($userId, $callId);
        if ((int) $call['callee_id'] !== $userId) {
            throw new \InvalidArgumentException('Only the callee can decline.');
        }
        if ($call['status'] !== 'ringing') {
            throw new \InvalidArgumentException('This call is no longer ringing.');
        }
        self::finish($call, 'declined', 'declined');
        self::signal($userId, $callId, 'decline', null);
        NotificationService::notify((int) $call['caller_id'], 'call_declined', 'Your call was declined', '', ['call_id' => $callId]);
        return self::find($callId);
    }

    public static function cancel(int $userId, int $callId): array
    {
        $call = self::participantCall($userId, $callId);
        if ((int) $call['caller_id'] !== $userId) {
            throw new \InvalidArgumentException('Only the caller can cancel.');
        }
        if (!in_array($call['status'], ['ringing', 'accepted'], true)) {
            throw new \InvalidArgumentException('This call cannot be cancelled.');
        }
        self::finish($call, 'cancelled', 'cancelled');
        self::signal($userId, $callId, 'cancel', null);
        return self::find($callId);
    }

    public static function end(int $userId, int $callId): array
    {
        $call = self::participantCall($userId, $callId);
        if (!in_array($call['status'], ['ringing', 'accepted'], true)) {
            throw new \InvalidArgumentException('This call has already ended.');
        }
        self::finish($call, 'ended', 'ended');
        self::signal($userId, $callId, 'end', null);
        return self::find($callId);
    }

    /** Cron / timeout hook: ringing calls expire as missed. */
    public static function expireRinging(): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - self::RING_TIMEOUT_SECONDS);
        $rows = Database::all(
            "SELECT * FROM calls WHERE status = 'ringing' AND created_at < :cutoff",
            ['cutoff' => $cutoff]
        );
        foreach ($rows as $call) {
            self::finish($call, 'missed', 'no_answer');
        }
        return count($rows);
    }

    // ------------------------------------------------------------------
    // Signalling (REST fallback; WebSocket service is primary)
    // ------------------------------------------------------------------

    public static function signal(int $userId, int $callId, string $kind, ?array $payload): void
    {
        self::participantCall($userId, $callId);
        Database::insert('call_signals', [
            'call_id'    => $callId,
            'sender_id'  => $userId,
            'kind'       => $kind,
            'payload'    => $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLUGES),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function signalsSince(int $userId, int $callId, int $sinceId = 0): array
    {
        self::participantCall($userId, $callId);
        $rows = Database::all(
            'SELECT * FROM call_signals WHERE call_id = :cid AND id > :since ORDER BY id ASC',
            ['cid' => $callId, 'since' => $sinceId]
        );
        return array_map(static function ($row) {
            return [
                'id'         => (int) $row['id'],
                'call_id'    => (int) $row['call_id'],
                'sender_id'  => (int) $row['sender_id'],
                'kind'       => $row['kind'],
                'payload'    => json_decode((string) ($row['payload'] ?? ''), true),
                'created_at' => $row['created_at'],
            ];
        }, $rows);
    }

    /**
     * Short-lived token the WebSocket signalling server accepts to
     * authenticate a participant for a specific call.
     */
    public static function signalToken(int $userId, int $callId): string
    {
        self::participantCall($userId, $callId);
        $expires = time() + 300;
        $payload = $userId . ':' . $callId . ':' . $expires;
        $sig = hash_hmac('sha256', $payload, self::signingKey());
        return base64_encode(json_encode(['uid' => $userId, 'call' => $callId, 'exp' => $expires, 'sig' => $sig]));
    }

    public static function verifySignalToken(string $token): ?array
    {
        $decoded = json_decode((string) base64_decode($token, true), true);
        if (!is_array($decoded) || !isset($decoded['uid'], $decoded['call'], $decoded['exp'], $decoded['sig'])) {
            return null;
        }
        if ((int) $decoded['exp'] < time()) {
            return null;
        }
        $payload = $decoded['uid'] . ':' . $decoded['call'] . ':' . $decoded['exp'];
        $expected = hash_hmac('sha256', $payload, self::signingKey());
        if (!hash_equals($expected, (string) $decoded['sig'])) {
            return null;
        }
        return ['uid' => (int) $decoded['uid'], 'call' => (int) $decoded['call']];
    }

    // ------------------------------------------------------------------
    // History & blocking
    // ------------------------------------------------------------------

    public static function history(int $userId, array $filters = []): array
    {
        $where = '(caller_id = :uid OR callee_id = :uid)';
        $params = ['uid' => $userId];
        if (!empty($filters['status'])) {
            $where .= ' AND status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['direction'])) {
            if ($filters['direction'] === 'incoming') {
                $where .= ' AND callee_id = :uid';
            } elseif ($filters['direction'] === 'outgoing') {
                $where .= ' AND caller_id = :uid';
            }
        }
        if (!empty($filters['type'])) {
            $where .= ' AND type = :type';
            $params['type'] = $filters['type'];
        }
        $rows = Database::all(
            "SELECT * FROM calls WHERE $where ORDER BY created_at DESC LIMIT 100",
            $params
        );
        return array_map(static function ($row) use ($userId) {
            $otherId = (int) $row['caller_id'] === $userId ? (int) $row['callee_id'] : (int) $row['caller_id'];
            $profile = Database::one('SELECT display_name FROM profiles WHERE user_id = :id', ['id' => $otherId]);
            $avatar = Database::scalar(
                "SELECT path FROM profile_photos WHERE user_id = :id AND is_avatar = 1 AND moderation_status = 'approved' ORDER BY position ASC LIMIT 1",
                ['id' => $otherId]
            );
            return [
                'id'              => (int) $row['id'],
                'direction'       => (int) $row['caller_id'] === $userId ? 'outgoing' : 'incoming',
                'type'            => $row['type'],
                'status'          => $row['status'],
                'other'           => [
                    'id' => $otherId,
                    'display_name' => $profile['display_name'] ?? null,
                    'avatar' => $avatar ? '/uploads/' . $avatar : null,
                ],
                'duration_seconds' => (int) $row['duration_seconds'],
                'started_at'      => $row['started_at'],
                'answered_at'     => $row['answered_at'],
                'ended_at'        => $row['ended_at'],
                'created_at'      => $row['created_at'],
            ];
        }, $rows);
    }

    public static function blockCalls(int $userId, int $targetId): bool
    {
        $exists = Database::one(
            'SELECT id FROM call_blocks WHERE blocker_id = :a AND blocked_id = :b',
            ['a' => $userId, 'b' => $targetId]
        );
        if (!$exists) {
            Database::insert('call_blocks', [
                'blocker_id' => $userId, 'blocked_id' => $targetId, 'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
        return true;
    }

    public static function unblockCalls(int $userId, int $targetId): bool
    {
        Database::delete('call_blocks', 'blocker_id = :a AND blocked_id = :b', ['a' => $userId, 'b' => $targetId]);
        return true;
    }

    public static function isCallBlocked(int $blockerId, int $blockedId): bool
    {
        return (bool) Database::scalar(
            'SELECT COUNT(*) FROM call_blocks WHERE blocker_id = :a AND blocked_id = :b',
            ['a' => $blockerId, 'b' => $blockedId]
        );
    }

    public static function blockedList(int $userId): array
    {
        return Database::all(
            'SELECT cb.blocked_id, p.display_name
               FROM call_blocks cb LEFT JOIN profiles p ON p.user_id = cb.blocked_id
              WHERE cb.blocker_id = :uid ORDER BY cb.created_at DESC',
            ['uid' => $userId]
        );
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    public static function find(int $callId): ?array
    {
        $call = Database::one('SELECT * FROM calls WHERE id = :id', ['id' => $callId]);
        return $call ?: null;
    }

    private static function participantCall(int $userId, int $callId): array
    {
        $call = self::find($callId);
        if (!$call) {
            throw new \InvalidArgumentException('Call not found.');
        }
        if ((int) $call['caller_id'] !== $userId && (int) $call['callee_id'] !== $userId) {
            throw new \InvalidArgumentException('You are not a participant of this call.');
        }
        return $call;
    }

    private static function finish(array $call, string $status, string $reason): void
    {
        $now = date('Y-m-d H:i:s');
        $duration = 0;
        if (!empty($call['answered_at'])) {
            $duration = max(0, time() - strtotime((string) $call['answered_at']));
        }
        Database::update('calls', [
            'status'           => $status,
            'ended_at'         => $now,
            'duration_seconds' => $duration,
            'end_reason'       => $reason,
        ], 'id = :id', ['id' => $call['id']]);
    }

    private static function signingKey(): string
    {
        return hash_hkdf('sha256', (string) config('app.key'), 32, 'codealpha-call-signaling');
    }
}
