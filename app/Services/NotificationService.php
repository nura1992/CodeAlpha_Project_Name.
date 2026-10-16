<?php

namespace MedCore\Services;

use MedCore\Core\Database;

/**
 * In-app notifications (bell icon in the top bar).
 * E-mail/SMS delivery is a later MediBridge concern; this stores the record.
 */
final class NotificationService
{
    public static function send(int $userId, string $title, string $body = '', string $type = 'info', ?string $link = null): void
    {
        Database::insert('notifications', [
            'user_id'    => $userId,
            'title'      => $title,
            'body'       => $body,
            'type'       => $type,
            'link'       => $link,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function forUser(int $userId, int $limit = 50): array
    {
        return Database::select(
            'SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit),
            [$userId]
        );
    }

    public static function unreadCount(int $userId): int
    {
        $row = Database::first(
            'SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND read_at IS NULL',
            [$userId]
        );
        return (int) ($row['c'] ?? 0);
    }

    public static function markRead(int $userId, int $notificationId): void
    {
        Database::execute(
            'UPDATE notifications SET read_at = ' . Database::nowExpr() . ' WHERE id = ? AND user_id = ?',
            [$notificationId, $userId]
        );
    }

    public static function markAllRead(int $userId): void
    {
        Database::execute(
            'UPDATE notifications SET read_at = ' . Database::nowExpr() . ' WHERE user_id = ? AND read_at IS NULL',
            [$userId]
        );
    }
}
