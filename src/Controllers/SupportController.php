<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Audit;
use App\Core\Database;
use App\Core\Notifier;
use App\Core\Rbac;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/**
 * Tickets, account recovery and appeals. Support staff see only what a case needs:
 * the member's username, account status and the conversation. Contact details require users.view_pii.
 */
final class SupportController
{
    private const CATEGORIES = ['general', 'account_recovery', 'appeal', 'safety', 'payments'];

    public function create(Request $r): array
    {
        $uid = $r->userId();
        $category = Validator::in($r->input('category', 'general'), 'category', self::CATEGORIES);
        $subject = Validator::str($r->input('subject'), 'subject', 3, 160);
        $body = Validator::str($r->input('body'), 'body', 10, 4000);
        $targetType = $r->input('appeal_target_type') ? Validator::in($r->input('appeal_target_type'), 'appeal_target_type', ['account', 'report', 'post', 'verification']) : null;
        $targetId = $r->input('appeal_target_id') ? Validator::int($r->input('appeal_target_id'), 'appeal_target_id', 1, PHP_INT_MAX) : null;
        if ($category === 'appeal' && $targetType === null) {
            $targetType = 'account';
        }
        $id = Database::transaction(static function () use ($uid, $category, $subject, $body, $targetType, $targetId): int {
            $id = Database::insert(
                'INSERT INTO support_tickets (user_id, category, subject, appeal_target_type, appeal_target_id) VALUES (?, ?, ?, ?, ?)',
                [$uid, $category, $subject, $targetType, $targetId]
            );
            Database::exec('INSERT INTO support_ticket_messages (ticket_id, author_id, is_staff, body) VALUES (?, ?, 0, ?)', [$id, $uid, $body]);
            return $id;
        });
        Response::status(201);
        return ['id' => $id, 'status' => 'open'];
    }

    public function mine(Request $r): array
    {
        return ['items' => Database::all(
            'SELECT id, category, subject, status, created_at, updated_at FROM support_tickets WHERE user_id = ? ORDER BY id DESC LIMIT 50',
            [$r->userId()]
        )];
    }

    public function showMine(Request $r): array
    {
        $ticket = Database::one('SELECT id, category, subject, status, created_at FROM support_tickets WHERE id = ? AND user_id = ?', [(int) $r->param('id'), $r->userId()]);
        if ($ticket === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Ticket not found.');
        }
        return ['ticket' => $ticket, 'messages' => $this->messages((int) $ticket['id'])];
    }

    public function replyMine(Request $r): array
    {
        $uid = $r->userId();
        $ticket = Database::one('SELECT * FROM support_tickets WHERE id = ? AND user_id = ?', [(int) $r->param('id'), $uid]);
        if ($ticket === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Ticket not found.');
        }
        if (in_array($ticket['status'], ['resolved', 'closed'], true)) {
            throw new ApiException(409, 'TICKET_CLOSED', 'This ticket is closed. Open a new one if you still need help.');
        }
        $body = Validator::str($r->input('body'), 'body', 1, 4000);
        Database::transaction(static function () use ($ticket, $uid, $body): void {
            Database::exec('INSERT INTO support_ticket_messages (ticket_id, author_id, is_staff, body) VALUES (?, ?, 0, ?)', [$ticket['id'], $uid, $body]);
            Database::exec("UPDATE support_tickets SET status = 'open' WHERE id = ?", [$ticket['id']]);
        });
        return ['ok' => true];
    }

    // ----------------------------------------------------------- staff side
    public function queue(Request $r): array
    {
        $where = ["t.status IN ('open','awaiting_user')"];
        $params = [];
        if ($r->query('category') && in_array($r->query('category'), self::CATEGORIES, true)) {
            $where[] = 't.category = ?';
            $params[] = $r->query('category');
        }
        $rows = Database::all(
            'SELECT t.id, t.category, t.subject, t.status, t.priority, t.assigned_to, t.created_at, t.updated_at, u.username, u.status AS account_status
             FROM support_tickets t JOIN users u ON u.id = t.user_id
             WHERE ' . implode(' AND ', $where) . " ORDER BY FIELD(t.priority, 'high', 'normal', 'low'), t.id ASC LIMIT 100",
            $params
        );
        return ['items' => $rows];
    }

    public function showStaff(Request $r): array
    {
        $ticket = Database::one(
            'SELECT t.id, t.category, t.subject, t.status, t.priority, t.assigned_to, t.appeal_target_type, t.appeal_target_id, t.created_at,
                    u.id AS user_id, u.username, u.status AS account_status, u.suspension_reason
             FROM support_tickets t JOIN users u ON u.id = t.user_id WHERE t.id = ?',
            [(int) $r->param('id')]
        );
        if ($ticket === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Ticket not found.');
        }
        if (Rbac::can($r->userId(), 'users.view_pii')) {
            $ticket += Database::one('SELECT email, phone FROM users WHERE id = ?', [$ticket['user_id']]) ?? [];
        }
        return ['ticket' => $ticket, 'messages' => $this->messages((int) $ticket['id'])];
    }

    public function assign(Request $r): array
    {
        Database::exec("UPDATE support_tickets SET assigned_to = ?, status = IF(status = 'open', 'open', status) WHERE id = ?", [$r->userId(), (int) $r->param('id')]);
        Audit::log($r->userId(), 'ticket.assigned', 'ticket', (int) $r->param('id'));
        return ['ok' => true];
    }

    public function replyStaff(Request $r): array
    {
        $ticket = Database::one('SELECT * FROM support_tickets WHERE id = ?', [(int) $r->param('id')]);
        if ($ticket === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Ticket not found.');
        }
        $body = Validator::str($r->input('body'), 'body', 1, 4000);
        Database::transaction(static function () use ($ticket, $r, $body): void {
            Database::exec('INSERT INTO support_ticket_messages (ticket_id, author_id, is_staff, body) VALUES (?, ?, 1, ?)', [$ticket['id'], $r->userId(), $body]);
            Database::exec("UPDATE support_tickets SET status = 'awaiting_user', assigned_to = COALESCE(assigned_to, ?) WHERE id = ?", [$r->userId(), $ticket['id']]);
        });
        Notifier::notify((int) $ticket['user_id'], 'support', 'Support replied', 'Open your ticket to read the reply.', ['ticket_id' => $ticket['id']]);
        return ['ok' => true];
    }

    public function close(Request $r): array
    {
        $status = Validator::in($r->input('status', 'closed'), 'status', ['resolved', 'closed']);
        Database::exec('UPDATE support_tickets SET status = ? WHERE id = ?', [$status, (int) $r->param('id')]);
        Audit::log($r->userId(), 'ticket.' . $status, 'ticket', (int) $r->param('id'));
        return ['ok' => true];
    }

    /** Upholds an account appeal: restores the suspended member. Requires users.suspend. */
    public function upholdAppeal(Request $r): array
    {
        Rbac::require($r->userId(), 'users.suspend');
        $ticket = Database::one("SELECT * FROM support_tickets WHERE id = ? AND category = 'appeal'", [(int) $r->param('id')]);
        if ($ticket === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Appeal not found.');
        }
        Database::exec("UPDATE users SET status = 'active', suspension_reason = NULL WHERE id = ? AND status = 'suspended'", [$ticket['user_id']]);
        Database::exec("UPDATE support_tickets SET status = 'resolved' WHERE id = ?", [$ticket['id']]);
        Audit::log($r->userId(), 'appeal.upheld', 'ticket', $ticket['id'], null, null, ['user_id' => $ticket['user_id']]);
        Notifier::notify((int) $ticket['user_id'], 'support', 'Your appeal was upheld', 'Your account has been restored.');
        return ['ok' => true];
    }

    private function messages(int $ticketId): array
    {
        return Database::all(
            'SELECT id, is_staff, body, created_at FROM support_ticket_messages WHERE ticket_id = ? ORDER BY id ASC',
            [$ticketId]
        );
    }
}
