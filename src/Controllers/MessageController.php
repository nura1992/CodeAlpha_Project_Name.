<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Database;
use App\Core\Eligibility;
use App\Core\Features;
use App\Core\Notifier;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\SecureStorage;
use App\Core\Validator;

/**
 * One-to-one messaging. Sending requires an ACTIVE match with approved profiles on both
 * sides and no block. History stays readable after unmatching, but cannot be extended.
 */
final class MessageController
{
    private const AUDIO_MIMES = ['audio/webm', 'audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/wav', 'audio/x-wav', 'video/webm', 'video/mp4'];

    public function conversations(Request $r): array
    {
        Features::require('comm.messaging');
        $uid = $r->userId();
        $rows = Database::all(
            "SELECT c.id, c.last_message_at, m.id AS match_id, m.status AS match_status,
                    IF(m.user_low_id = ?, m.user_high_id, m.user_low_id) AS other_id,
                    (SELECT COUNT(*) FROM messages x WHERE x.conversation_id = c.id AND x.sender_id <> ?
                       AND x.deleted_at IS NULL AND x.id > COALESCE(cm.last_read_message_id, 0)) AS unread
             FROM conversation_members cm
             JOIN conversations c ON c.id = cm.conversation_id
             LEFT JOIN matches m ON m.id = c.match_id
             WHERE cm.user_id = ?
             ORDER BY COALESCE(c.last_message_at, c.created_at) DESC LIMIT 100",
            [$uid, $uid, $uid]
        );
        $items = [];
        foreach ($rows as $row) {
            $other = Eligibility::memberRow((int) $row['other_id']);
            $last = Database::one('SELECT type, body, sender_id, created_at FROM messages WHERE conversation_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1', [$row['id']]);
            $items[] = [
                'id' => (int) $row['id'],
                'match_status' => $row['match_status'],
                'can_send' => $row['match_status'] === 'active' && Eligibility::canCommunicate($uid, (int) $row['other_id']) !== null,
                'other' => ['user_id' => (int) $row['other_id'], 'display_name' => $other['display_name'] ?? 'Member'],
                'unread' => (int) $row['unread'],
                'last_message' => $last ? ['type' => $last['type'], 'preview' => $last['type'] === 'text' ? mb_substr((string) $last['body'], 0, 80) : ucfirst($last['type']), 'mine' => (int) $last['sender_id'] === $uid, 'created_at' => $last['created_at']] : null,
                'last_message_at' => $row['last_message_at'],
            ];
        }
        return ['items' => $items];
    }

    public function messages(Request $r): array
    {
        Features::require('comm.messaging');
        $uid = $r->userId();
        $convId = (int) $r->param('id');
        $this->assertMember($convId, $uid);
        $before = max(0, (int) $r->query('before', 0));
        $limit = 30;

        // Delivery receipts: the recipient's device has fetched these messages.
        Database::exec(
            'INSERT IGNORE INTO message_receipts (message_id, user_id, delivered_at)
             SELECT id, ?, NOW() FROM messages WHERE conversation_id = ? AND sender_id <> ? AND deleted_at IS NULL',
            [$uid, $convId, $uid]
        );

        $sql = 'SELECT m.id, m.sender_id, m.type, m.body, m.media_mime, m.media_duration_seconds, m.created_at,
                       (SELECT mr.read_at FROM message_receipts mr WHERE mr.message_id = m.id AND mr.user_id <> m.sender_id LIMIT 1) AS read_at,
                       (SELECT mr.delivered_at FROM message_receipts mr WHERE mr.message_id = m.id AND mr.user_id <> m.sender_id LIMIT 1) AS delivered_at
                FROM messages m WHERE m.conversation_id = ? AND m.deleted_at IS NULL';
        $params = [$convId];
        if ($before > 0) {
            $sql .= ' AND m.id < ?';
            $params[] = $before;
        }
        $sql .= ' ORDER BY m.id DESC LIMIT ' . $limit;
        $rows = array_reverse(Database::all($sql, $params));

        $items = array_map(static fn($m) => [
            'id' => (int) $m['id'],
            'mine' => (int) $m['sender_id'] === $uid,
            'type' => $m['type'],
            'body' => $m['type'] === 'text' ? $m['body'] : null,
            'media_url' => $m['type'] !== 'text' ? '/api/media/messages/' . $m['id'] : null,
            'duration_seconds' => $m['media_duration_seconds'] !== null ? (int) $m['media_duration_seconds'] : null,
            'created_at' => $m['created_at'],
            'status' => $m['read_at'] ? 'read' : ($m['delivered_at'] ? 'delivered' : 'sent'),
        ], $rows);

        $conv = Database::one('SELECT c.match_id, m.status AS match_status FROM conversations c LEFT JOIN matches m ON m.id = c.match_id WHERE c.id = ?', [$convId]);
        $otherId = $this->otherMember($convId, $uid);
        return [
            'items' => $items,
            'can_send' => ($conv['match_status'] ?? null) === 'active' && Eligibility::canCommunicate($uid, $otherId) !== null,
            'next_before' => count($items) === $limit ? $items[0]['id'] : null,
        ];
    }

    public function send(Request $r): array
    {
        Features::require('comm.messaging');
        $uid = $r->userId();
        $convId = (int) $r->param('id');
        $this->assertMember($convId, $uid);
        $otherId = $this->otherMember($convId, $uid);
        if (Eligibility::canCommunicate($uid, $otherId) === null) {
            throw new ApiException(403, 'CONVERSATION_CLOSED', 'You can only message an active match with an approved profile.');
        }
        RateLimiter::hit('msg:send:' . $uid, 60, 60);

        $type = Validator::in($r->input('type', 'text'), 'type', ['text', 'image', 'voice']);
        $clientUuid = $r->input('client_uuid');
        if ($clientUuid !== null && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $clientUuid)) {
            Validator::fail('client_uuid', 'Invalid message identifier.');
        }
        if ($clientUuid !== null) {
            // Idempotency: retried sends return the original message.
            $existing = Database::one('SELECT id FROM messages WHERE sender_id = ? AND client_uuid = ?', [$uid, $clientUuid]);
            if ($existing) {
                return ['id' => (int) $existing['id'], 'duplicate' => true];
            }
        }

        $body = null;
        $key = null;
        $mime = null;
        $size = null;
        $duration = null;
        if ($type === 'text') {
            $body = Validator::str($r->input('body'), 'body', 1, 2000);
        } elseif ($type === 'image') {
            $file = SecureStorage::inspectUpload($r->files['file'] ?? null, ['image/jpeg', 'image/png', 'image/webp'], 10 * 1048576, 'file');
            $key = SecureStorage::putFile('messages', $file['tmp']);
            $mime = $file['mime'];
            $size = $file['size'];
        } else {
            $file = SecureStorage::inspectUpload($r->files['file'] ?? null, self::AUDIO_MIMES, 5 * 1048576, 'file');
            $duration = Validator::int($r->input('duration_seconds', 1), 'duration_seconds', 1, 300);
            $key = SecureStorage::putFile('messages', $file['tmp']);
            $mime = $file['mime'];
            $size = $file['size'];
        }

        $id = Database::transaction(static function () use ($convId, $uid, $clientUuid, $type, $body, $key, $mime, $size, $duration): int {
            $id = Database::insert(
                'INSERT INTO messages (conversation_id, sender_id, client_uuid, type, body, storage_key, media_mime, media_size_bytes, media_duration_seconds)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$convId, $uid, $clientUuid, $type, $body, $key, $mime, $size, $duration]
            );
            Database::exec('UPDATE conversations SET last_message_at = NOW() WHERE id = ?', [$convId]);
            return $id;
        });

        $senderName = Eligibility::memberRow($uid)['display_name'] ?? 'Someone';
        Notifier::notify($otherId, 'message', 'New message', 'From ' . $senderName, ['conversation_id' => $convId]);
        Response::status(201);
        return ['id' => $id, 'type' => $type, 'duplicate' => false];
    }

    public function markRead(Request $r): array
    {
        $uid = $r->userId();
        $convId = (int) $r->param('id');
        $this->assertMember($convId, $uid);
        $messageId = Validator::int($r->input('message_id'), 'message_id', 1, PHP_INT_MAX);
        Database::transaction(static function () use ($convId, $uid, $messageId): void {
            Database::exec(
                'UPDATE conversation_members SET last_read_message_id = GREATEST(COALESCE(last_read_message_id, 0), ?)
                 WHERE conversation_id = ? AND user_id = ?',
                [$messageId, $convId, $uid]
            );
            Database::exec(
                'INSERT INTO message_receipts (message_id, user_id, delivered_at, read_at)
                 SELECT id, ?, NOW(), NOW() FROM messages WHERE conversation_id = ? AND sender_id <> ? AND id <= ? AND deleted_at IS NULL
                 ON DUPLICATE KEY UPDATE read_at = COALESCE(read_at, NOW()), delivered_at = COALESCE(delivered_at, NOW())',
                [$uid, $convId, $uid, $messageId]
            );
        });
        return ['ok' => true];
    }

    /** Voice notes and images are decrypted only for members of the conversation. */
    public function media(Request $r): ?array
    {
        $uid = $r->userId();
        $msg = Database::one('SELECT * FROM messages WHERE id = ? AND deleted_at IS NULL AND storage_key IS NOT NULL', [(int) $r->param('id')]);
        if ($msg === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Media not found.');
        }
        $this->assertMember((int) $msg['conversation_id'], $uid);
        Response::binary(SecureStorage::get('messages', $msg['storage_key']), $msg['media_mime'], 'inline', 'message-media');
        return null;
    }

    private function assertMember(int $convId, int $uid): void
    {
        if (!Database::value('SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ?', [$convId, $uid])) {
            throw new ApiException(404, 'NOT_FOUND', 'Conversation not found.');
        }
    }

    private function otherMember(int $convId, int $uid): int
    {
        return (int) Database::value('SELECT user_id FROM conversation_members WHERE conversation_id = ? AND user_id <> ?', [$convId, $uid]);
    }
}
