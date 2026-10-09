<?php
/**
 * SupportService — tickets, messages, appeals.
 *
 * Support staff see only the information necessary for assigned cases and
 * cannot approve verification or issue refunds unless explicitly granted
 * those permissions (enforced by route middleware, not by this service).
 */

declare(strict_types=1);

namespace CodeAlpha\Services;

use CodeAlpha\Core\AuditLog;
use CodeAlpha\Core\Database;

final class SupportService
{
    public static function createTicket(int $userId, string $subject, string $category, string $message, string $priority = 'normal'): array
    {
        $now = date('Y-m-d H:i:s');
        $ticketId = (int) Database::insert('support_tickets', [
            'user_id'    => $userId,
            'subject'    => substr($subject, 0, 200),
            'category'   => in_array($category, ['account', 'payment', 'verification', 'community', 'safety', 'other'], true) ? $category : 'other',
            'priority'   => in_array($priority, ['low', 'normal', 'high', 'urgent'], true) ? $priority : 'normal',
            'status'     => 'open',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Database::insert('support_messages', [
            'ticket_id'  => $ticketId,
            'sender_id'  => $userId,
            'is_staff'   => 0,
            'body'       => $message,
            'created_at' => $now,
        ]);
        self::notifyStaff($ticketId, 'A new support ticket was opened.', $subject);
        return self::ticket($userId, $ticketId);
    }

    public static function ticketsForUser(int $userId): array
    {
        return Database::all(
            'SELECT t.*, (SELECT COUNT(*) FROM support_messages m WHERE m.ticket_id = t.id) AS message_count,
                    (SELECT body FROM support_messages m WHERE m.ticket_id = t.id ORDER BY m.id DESC LIMIT 1) AS last_message
               FROM support_tickets t WHERE t.user_id = :uid ORDER BY t.updated_at DESC',
            ['uid' => $userId]
        );
    }

    public static function ticket(int $userId, int $ticketId): ?array
    {
        $ticket = Database::one('SELECT * FROM support_tickets WHERE id = :id AND user_id = :uid', ['id' => $ticketId, 'uid' => $userId]);
        return $ticket ?: null;
    }

    public static function ticketMessages(int $ticketId): array
    {
        return Database::all(
            'SELECT m.*, u.is_staff, p.display_name
               FROM support_messages m JOIN users u ON u.id = m.sender_id
               LEFT JOIN profiles p ON p.user_id = m.sender_id
              WHERE m.ticket_id = :id ORDER BY m.id ASC',
            ['id' => $ticketId]
        );
    }

    public static function addMessage(int $ticketId, int $senderId, string $body, bool $isStaff): array
    {
        $ticket = Database::one('SELECT * FROM support_tickets WHERE id = :id', ['id' => $ticketId]);
        if (!$ticket) {
            throw new \InvalidArgumentException('Ticket not found.');
        }
        if (!$isStaff && (int) $ticket['user_id'] !== $senderId) {
            throw new \InvalidArgumentException('Ticket not found.');
        }
        $now = date('Y-m-d H:i:s');
        $messageId = (int) Database::insert('support_messages', [
            'ticket_id'  => $ticketId,
            'sender_id'  => $senderId,
            'is_staff'   => $isStaff ? 1 : 0,
            'body'       => $body,
            'created_at' => $now,
        ]);
        $status = $isStaff && $ticket['status'] === 'open' ? 'in_progress' : $ticket['status'];
        Database::update('support_tickets', ['updated_at' => $now, 'status' => $status], 'id = :id', ['id' => $ticketId]);

        if ($isStaff) {
            NotificationService::notify((int) $ticket['user_id'], 'ticket_reply', 'Support replied to your ticket', mb_substr($body, 0, 120), ['ticket_id' => $ticketId]);
        } else {
            self::notifyStaff($ticketId, 'A member replied to a ticket.', (string) $ticket['subject']);
        }

        return ['message_id' => $messageId, 'status' => $status];
    }

    // ------------------------------------------------------------------
    // Staff-side queues
    // ------------------------------------------------------------------

    public static function staffTickets(array $filters = []): array
    {
        $where = '1=1';
        $params = [];
        if (!empty($filters['status'])) {
            $where .= ' AND t.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['category'])) {
            $where .= ' AND t.category = :cat';
            $params['cat'] = $filters['category'];
        }
        return Database::all(
            "SELECT t.*, u.email AS user_email, p.display_name,
                    (SELECT COUNT(*) FROM support_messages m WHERE m.ticket_id = t.id) AS message_count
               FROM support_tickets t
               JOIN users u ON u.id = t.user_id
               LEFT JOIN profiles p ON p.user_id = t.user_id
              WHERE $where ORDER BY t.updated_at DESC LIMIT 200",
            $params
        );
    }

    public static function staffTicket(int $ticketId): ?array
    {
        $ticket = Database::one(
            'SELECT t.*, u.email AS user_email, u.phone AS user_phone, p.display_name
               FROM support_tickets t
               JOIN users u ON u.id = t.user_id
               LEFT JOIN profiles p ON p.user_id = t.user_id
              WHERE t.id = :id',
            ['id' => $ticketId]
        );
        return $ticket ?: null;
    }

    public static function setTicketStatus(int $ticketId, string $status, int $staffId): void
    {
        $allowed = ['open', 'in_progress', 'resolved', 'closed'];
        if (!in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException('Invalid status.');
        }
        $now = date('Y-m-d H:i:s');
        Database::update('support_tickets', [
            'status'      => $status,
            'resolved_at' => in_array($status, ['resolved', 'closed'], true) ? $now : null,
            'updated_at'  => $now,
        ], 'id = :id', ['id' => $ticketId]);
        AuditLog::record($staffId, 'support.ticket_status', 'support_ticket', (string) $ticketId, ['status' => $status]);
    }

    // ------------------------------------------------------------------
    // Appeals (verification rejections, suspensions)
    // ------------------------------------------------------------------

    public static function createAppeal(int $userId, string $subject, string $message): array
    {
        return self::createTicket($userId, '[Appeal] ' . $subject, 'account', $message, 'high');
    }

    public static function appealsQueue(): array
    {
        return Database::all(
            "SELECT t.*, u.email AS user_email, p.display_name
               FROM support_tickets t
               JOIN users u ON u.id = t.user_id
               LEFT JOIN profiles p ON p.user_id = t.user_id
              WHERE t.subject LIKE '[Appeal]%' ORDER BY t.created_at DESC LIMIT 100"
        );
    }

    private static function notifyStaff(int $ticketId, string $title, string $body): void
    {
        $staff = Database::all(
            'SELECT DISTINCT ur.user_id FROM user_roles ur
               JOIN role_permissions rp ON rp.role_id = ur.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE p.slug = :slug',
            ['slug' => 'support.tickets']
        );
        foreach ($staff as $row) {
            NotificationService::notify((int) $row['user_id'], 'support', $title, $body, ['ticket_id' => $ticketId]);
        }
    }
}
