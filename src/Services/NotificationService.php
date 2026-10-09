<?php
/**
 * NotificationService — in-app notifications (always available) plus
 * optional Web Push delivery when the push_notifications feature is on and
 * VAPID keys are configured.
 */

declare(strict_types=1);

namespace CodeAlpha\Services;

use CodeAlpha\Core\Database;
use CodeAlpha\Core\FeatureFlags;
use CodeAlpha\Core\Settings;

final class NotificationService
{
    /**
     * Create an in-app notification and (best effort) push it.
     */
    public static function notify(
        int $userId,
        string $type,
        string $title,
        string $body = '',
        array $data = []
    ): void {
        Database::insert('notifications', [
            'user_id'    => $userId,
            'type'       => substr($type, 0, 60),
            'title'      => substr($title, 0, 200),
            'body'       => $body !== '' ? substr($body, 0, 1000) : null,
            'data'       => $data === [] ? null : json_encode($data, JSON_UNESCAPED_SLASHES),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        PushService::dispatch($userId, $type, $title, $body, $data);
    }

    public static function list(int $userId, int $limit = 30, int $offset = 0, bool $unreadOnly = false): array
    {
        $where = 'user_id = :uid' . ($unreadOnly ? ' AND read_at IS NULL' : '');
        return Database::all(
            "SELECT * FROM notifications WHERE $where ORDER BY id DESC LIMIT $limit OFFSET $offset",
            ['uid' => $userId]
        );
    }

    public static function unreadCount(int $userId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND read_at IS NULL',
            ['uid' => $userId]
        );
    }

    public static function markRead(int $userId, int $notificationId): void
    {
        Database::query(
            'UPDATE notifications SET read_at = :now WHERE id = :id AND user_id = :uid AND read_at IS NULL',
            ['now' => date('Y-m-d H:i:s'), 'id' => $notificationId, 'uid' => $userId]
        );
    }

    public static function markAllRead(int $userId): void
    {
        Database::query(
            'UPDATE notifications SET read_at = :now WHERE user_id = :uid AND read_at IS NULL',
            ['now' => date('Y-m-d H:i:s'), 'uid' => $userId]
        );
    }
}

/**
 * PushService — Web Push delivery.
 *
 * The actual Web Push protocol requires an ECDH library; this implementation
 * records the intent in the log and is structured so a production deployment
 * can swap in a real sender (e.g. via the `web-push` PHP library or a queue
 * worker). Subscriptions are stored and validated regardless.
 */
final class PushService
{
    public static function dispatch(int $userId, string $type, string $title, string $body, array $data = []): void
    {
        if (!FeatureFlags::isEnabled('push_notifications')) {
            return;
        }
        $subs = Database::all('SELECT * FROM push_subscriptions WHERE user_id = :uid', ['uid' => $userId]);
        if (!$subs) {
            return;
        }
        $payload = json_encode([
            'type' => $type, 'title' => $title, 'body' => $body, 'data' => $data, 'at' => date('c'),
        ], JSON_UNESCAPED_SLASHES);
        foreach ($subs as $sub) {
            // In production: encrypt $payload with the subscription keys and POST
            // to $sub['endpoint'] using VAPID (see settings vapid_public/private).
            // We log the dispatch for the dev inbox and audit trail.
            try {
                $dir = (string) config('storage.logs');
                if (!is_dir($dir)) {
                    mkdir($dir, 0770, true);
                }
                file_put_contents(
                    $dir . '/push.log',
                    json_encode(['user_id' => $userId, 'endpoint' => substr((string) $sub['endpoint'], 0, 120), 'payload' => $payload, 'at' => date('c')]) . "\n",
                    FILE_APPEND | LOCK_EX
                );
            } catch (\Throwable $e) {
                error_log('[CodeAlpha] push dispatch failure: ' . $e->getMessage());
            }
        }
    }
}
