<?php
/**
 * MessagingService — one-to-one messaging between matched members.
 *
 * Rules:
 *   - Conversations exist only between members with an active match.
 *   - Blocking ends the match and closes the conversation.
 *   - Text, image and voice messages; media is stored in protected storage
 *     and served via signed URLs to conversation members only.
 *   - Delivery and read receipts are tracked per message.
 *   - Unmatching makes the conversation read-only (history preserved).
 */

declare(strict_types=1);

namespace CodeAlpha\Services;

use CodeAlpha\Core\AuditLog;
use CodeAlpha\Core\Database;
use CodeAlpha\Core\Uploader;

final class MessagingService
{
    /** Conversations for the sidebar. */
    public static function conversationsFor(int $userId): array
    {
        $rows = Database::all(
            'SELECT c.id, c.match_id, c.last_message_at, c.updated_at,
                    CASE WHEN m.user_id = :uid THEN
                      (SELECT cm2.user_id FROM conversation_members cm2 WHERE cm2.conversation_id = c.id AND cm2.user_id <> :uid AND cm2.left_at IS NULL LIMIT 1)
                    END AS other_id
               FROM conversation_members m
               JOIN conversations c ON c.id = m.conversation_id
              WHERE m.user_id = :uid AND m.left_at IS NULL
              ORDER BY c.last_message_at DESC, c.updated_at DESC',
            ['uid' => $userId]
        );
        $out = [];
        foreach ($rows as $row) {
            $otherId = $row['other_id'] ? (int) $row['other_id'] : null;
            if (!$otherId) {
                continue;
            }
            $other = Database::one('SELECT id, uuid FROM users WHERE id = :id', ['id' => $otherId]);
            $profile = Database::one('SELECT display_name FROM profiles WHERE user_id = :id', ['id' => $otherId]);
            $avatar = Database::scalar(
                "SELECT path FROM profile_photos WHERE user_id = :id AND is_avatar = 1 AND moderation_status = 'approved' ORDER BY position ASC LIMIT 1",
                ['id' => $otherId]
            );
            $lastMessage = Database::one(
                'SELECT * FROM messages WHERE conversation_id = :cid AND deleted_at IS NULL ORDER BY id DESC LIMIT 1',
                ['cid' => $row['id']]
            );
            $unread = (int) Database::scalar(
                'SELECT COUNT(*) FROM messages WHERE conversation_id = :cid AND sender_id <> :uid AND read_at IS NULL AND deleted_at IS NULL',
                ['cid' => $row['id'], 'uid' => $userId]
            );
            $matched = MatchingService::hasActiveMatch($userId, $otherId);
            $out[] = [
                'id' => (int) $row['id'],
                'other' => [
                    'id' => $otherId,
                    'uuid' => $other['uuid'] ?? null,
                    'display_name' => $profile['display_name'] ?? null,
                    'avatar' => $avatar ? '/uploads/' . $avatar : null,
                ],
                'matched' => $matched,
                'last_message' => $lastMessage ? self::messageView($lastMessage, $userId) : null,
                'unread_count' => $unread,
                'last_message_at' => $row['last_message_at'],
            ];
        }
        return $out;
    }

    public static function messagesFor(int $userId, int $conversationId, ?int $sinceId = null, int $limit = 50): array
    {
        self::requireMember($userId, $conversationId);
        $where = 'conversation_id = :cid AND deleted_at IS NULL';
        $params = ['cid' => $conversationId];
        if ($sinceId) {
            $where .= ' AND id > :since';
            $params['since'] = $sinceId;
        }
        $rows = Database::all(
            "SELECT * FROM messages WHERE $where ORDER BY id DESC LIMIT $limit",
            $params
        );
        return array_reverse(array_map(static fn ($m) => self::messageView($m, $userId), $rows));
    }

    /**
     * Send a message. $payload: ['type' => text|image|voice, 'body' => ..., 'file' => $_FILES entry]
     */
    public static function send(int $userId, int $conversationId, array $payload): array
    {
        self::requireActiveConversation($userId, $conversationId);

        // Rate limit: 30 messages / minute per user.
        $limit = \CodeAlpha\Core\RateLimiter::hit('msg:' . $userId, 30, 60);
        if (!$limit['allowed']) {
            \CodeAlpha\Core\RateLimiter::tooManyRequests($limit);
        }

        $type = (string) ($payload['type'] ?? 'text');
        $now = date('Y-m-d H:i:s');
        $data = [
            'conversation_id' => $conversationId,
            'sender_id'       => $userId,
            'type'            => $type,
            'body'            => null,
            'media_path'      => null,
            'media_mime'      => null,
            'media_duration'  => null,
            'delivered_at'    => $now, // delivered when stored; read when fetched by peer
            'created_at'      => $now,
        ];

        if ($type === 'text') {
            $body = trim((string) ($payload['body'] ?? ''));
            if ($body === '') {
                throw new \InvalidArgumentException('Message text is required.');
            }
            if (mb_strlen($body) > 4000) {
                throw new \InvalidArgumentException('Message is too long (max 4000 characters).');
            }
            $data['body'] = $body;
        } else {
            $kind = $type === 'voice' ? 'audio' : 'image';
            $file = $payload['file'] ?? null;
            if (!$file) {
                throw new \InvalidArgumentException('Media file is required.');
            }
            $stored = Uploader::storeProtected($file, $kind, 'chat', 'chat');
            $data['media_path'] = $stored['path'];
            $data['media_mime'] = $stored['mime'];
            if (isset($payload['duration'])) {
                $data['media_duration'] = max(0, (int) $payload['duration']);
            }
        }

        $messageId = (int) Database::insert('messages', $data);
        Database::query(
            'UPDATE conversations SET updated_at = :now, last_message_at = :now WHERE id = :id',
            ['now' => $now, 'id' => $conversationId]
        );

        // Notify the other members.
        $others = Database::all(
            'SELECT user_id FROM conversation_members WHERE conversation_id = :cid AND user_id <> :uid AND left_at IS NULL',
            ['cid' => $conversationId, 'uid' => $userId]
        );
        $message = Database::one('SELECT * FROM messages WHERE id = :id', ['id' => $messageId]);
        foreach ($others as $other) {
            NotificationService::notify(
                (int) $other['user_id'],
                'message',
                'New message',
                $type === 'text' ? mb_substr((string) $data['body'], 0, 120) : 'Sent you a ' . $type . '.',
                ['conversation_id' => $conversationId, 'message_id' => $messageId]
            );
        }

        return self::messageView($message, $userId);
    }

    /** Mark all messages in a conversation from the peer as read. */
    public static function markRead(int $userId, int $conversationId): void
    {
        self::requireMember($userId, $conversationId);
        Database::query(
            'UPDATE messages SET read_at = :now, delivered_at = COALESCE(delivered_at, :now)
              WHERE conversation_id = :cid AND sender_id <> :uid AND read_at IS NULL',
            ['now' => date('Y-m-d H:i:s'), 'cid' => $conversationId, 'uid' => $userId]
        );
    }

    /** Conversation id for a pair of matched users (if any). */
    public static function conversationIdBetween(int $userA, int $userB): ?int
    {
        $conv = MatchingService::conversationBetween($userA, $userB);
        return $conv ? (int) $conv['id'] : null;
    }

    public static function messageView(array $message, int $viewerId): array
    {
        $view = [
            'id'              => (int) $message['id'],
            'conversation_id' => (int) $message['conversation_id'],
            'sender_id'       => (int) $message['sender_id'],
            'mine'            => (int) $message['sender_id'] === $viewerId,
            'type'            => $message['type'],
            'body'            => $message['body'],
            'media_url'       => null,
            'media_mime'      => $message['media_mime'],
            'media_duration'  => $message['media_duration'] !== null ? (int) $message['media_duration'] : null,
            'delivered_at'    => $message['delivered_at'],
            'read_at'         => $message['read_at'],
            'created_at'      => $message['created_at'],
        ];
        if (!empty($message['media_path'])) {
            $view['media_url'] = MediaService::signedUrl('/media/chat/' . $message['id'], (string) $message['media_path'], 3600);
        }
        return $view;
    }

    // ------------------------------------------------------------------
    // Guards
    // ------------------------------------------------------------------

    public static function requireMember(int $userId, int $conversationId): void
    {
        $member = Database::one(
            'SELECT * FROM conversation_members WHERE conversation_id = :cid AND user_id = :uid AND left_at IS NULL',
            ['cid' => $conversationId, 'uid' => $userId]
        );
        if (!$member) {
            throw new \InvalidArgumentException('Conversation not found.');
        }
    }

    /** Sending requires an active match behind the conversation. */
    private static function requireActiveConversation(int $userId, int $conversationId): void
    {
        self::requireMember($userId, $conversationId);
        $conv = Database::one('SELECT * FROM conversations WHERE id = :id', ['id' => $conversationId]);
        if (!$conv) {
            throw new \InvalidArgumentException('Conversation not found.');
        }
        $other = Database::one(
            'SELECT user_id FROM conversation_members WHERE conversation_id = :cid AND user_id <> :uid AND left_at IS NULL LIMIT 1',
            ['cid' => $conversationId, 'uid' => $userId]
        );
        $otherId = $other ? (int) $other['user_id'] : 0;
        if (!MatchingService::hasActiveMatch($userId, $otherId)) {
            throw new \InvalidArgumentException('You can only message active matches.');
        }
        if (MatchingService::isBlocked($userId, $otherId) || MatchingService::isBlocked($otherId, $userId)) {
            throw new \InvalidArgumentException('This conversation is not available.');
        }
    }
}
