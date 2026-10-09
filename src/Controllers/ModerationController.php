<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Notifier;
use App\Core\Rbac;
use App\Core\Request;
use App\Core\Response;
use App\Core\SecureStorage;
use App\Core\Validator;

/** Report queue, member account actions and profile-photo moderation. */
final class ModerationController
{
    private const ACTIONS = ['dismiss', 'no_action', 'hide_content', 'remove_content', 'suspend_user'];

    public function reports(Request $r): array
    {
        $status = in_array($r->query('status'), ['open', 'reviewing', 'resolved', 'dismissed'], true) ? (string) $r->query('status') : 'open';
        $rows = Database::all(
            'SELECT id, reporter_id, target_type, target_id, reason, details, status, assigned_to, created_at
             FROM user_reports WHERE queue = ? AND status = ? ORDER BY created_at ASC LIMIT 100',
            ['moderation', $status]
        );
        return ['items' => array_map(fn($row) => $row + ['target' => $this->targetSummary($row['target_type'], (int) $row['target_id'])], $rows)];
    }

    public function claimReport(Request $r): array
    {
        Database::exec("UPDATE user_reports SET status = 'reviewing', assigned_to = ? WHERE id = ? AND status = 'open'", [$r->userId(), (int) $r->param('id')]);
        return ['ok' => true];
    }

    /** Applies a moderation outcome. Each action needs its own permission, checked here. */
    public function resolveReport(Request $r): array
    {
        $me = $r->userId();
        $report = Database::one('SELECT * FROM user_reports WHERE id = ?', [(int) $r->param('id')]);
        if ($report === null || !in_array($report['status'], ['open', 'reviewing'], true)) {
            throw new ApiException(409, 'INVALID_STATE', 'This report is not open.');
        }
        $action = Validator::in($r->input('action'), 'action', self::ACTIONS);
        $note = Validator::str($r->input('note'), 'note', 10, 1000);

        $authorId = $this->authorOf($report['target_type'], (int) $report['target_id']);
        match ($action) {
            'hide_content', 'remove_content' => Rbac::require($me, 'community.moderate'),
            'suspend_user' => Rbac::require($me, 'users.suspend'),
            default => Rbac::require($me, 'moderation.review'),
        };

        Database::transaction(function () use ($report, $action, $note, $me, $authorId): void {
            if (in_array($action, ['hide_content', 'remove_content'], true)) {
                $status = $action === 'hide_content' ? 'hidden' : 'removed';
                match ($report['target_type']) {
                    'post' => Database::exec('UPDATE community_posts SET status = ? WHERE id = ?', [$status, $report['target_id']]),
                    'comment' => Database::exec('UPDATE post_comments SET status = ? WHERE id = ?', [$status, $report['target_id']]),
                    'message' => Database::exec('UPDATE messages SET deleted_at = NOW() WHERE id = ?', [$report['target_id']]),
                    default => null,
                };
            }
            if ($action === 'suspend_user' && $authorId !== null) {
                Database::exec("UPDATE users SET status = 'suspended', suspension_reason = ? WHERE id = ? AND account_type = 'member'", [$note, $authorId]);
                Auth::revokeAll($authorId);
                Notifier::notify($authorId, 'account', 'Account suspended', 'Your account has been suspended. Contact support to appeal.');
            }
            $resolution = $action === 'dismiss' ? 'dismissed' : 'resolved';
            Database::exec(
                'UPDATE user_reports SET status = ?, action_taken = ?, resolution_note = ?, resolved_by = ?, resolved_at = NOW(), assigned_to = COALESCE(assigned_to, ?) WHERE id = ?',
                [$resolution, $action, $note, $me, $me, $report['id']]
            );
        });
        Audit::log($me, 'report.resolved', 'report', $report['id'], $note, ['status' => $report['status']], ['action' => $action, 'author_id' => $authorId]);
        return ['ok' => true, 'action' => $action];
    }

    public function users(Request $r): array
    {
        $canPii = Rbac::can($r->userId(), 'users.view_pii');
        $q = $r->query('q') ? Validator::str($r->query('q'), 'q', 2, 190) : null;
        $sql = "SELECT u.id, u.username, u.email, u.phone, u.account_type, u.status, u.created_at, u.last_login_at, p.approval_status
                FROM users u LEFT JOIN profiles p ON p.user_id = u.id
                WHERE u.account_type = 'member' AND u.deleted_at IS NULL";
        $params = [];
        if ($q !== null) {
            $sql .= ' AND (u.username LIKE ? OR u.email = ? OR u.phone = ?)';
            $like = str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $params = [$like, $q, $q];
        }
        $rows = Database::all($sql . ' ORDER BY u.id DESC LIMIT 100', $params);
        // Without users.view_pii, contact details are masked.
        return ['items' => array_map(fn($row) => $canPii ? $row : $row + [
            'email' => $this->mask($row['email']),
            'phone' => $this->mask($row['phone']),
        ], $rows)];
    }

    public function userDetail(Request $r): array
    {
        $id = (int) $r->param('id');
        $canPii = Rbac::can($r->userId(), 'users.view_pii');
        $user = Database::one("SELECT id, username, email, phone, account_type, status, suspension_reason, created_at, last_login_at FROM users WHERE id = ? AND account_type = 'member'", [$id]);
        if ($user === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Member not found.');
        }
        if (!$canPii) {
            $user['email'] = $this->mask($user['email']);
            $user['phone'] = $this->mask($user['phone']);
        }
        return [
            'user' => $user,
            'profile' => Database::one('SELECT display_name, approval_status, rejection_reason, approved_at, reverification_required FROM profiles WHERE user_id = ?', [$id]),
            'verifications' => Database::all('SELECT id, status, submitted_at, decided_at, decision_reason FROM verification_requests WHERE user_id = ? ORDER BY id DESC LIMIT 20', [$id]),
            'reports_against' => (int) Database::value("SELECT COUNT(*) FROM user_reports WHERE target_type = 'user' AND target_id = ?", [$id]),
        ];
    }

    public function suspendUser(Request $r): array
    {
        $id = (int) $r->param('id');
        $reason = Validator::str($r->input('reason'), 'reason', 10, 500);
        $user = Database::one("SELECT id, status FROM users WHERE id = ? AND account_type = 'member' AND deleted_at IS NULL", [$id]);
        if ($user === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Member not found.');
        }
        Database::exec("UPDATE users SET status = 'suspended', suspension_reason = ? WHERE id = ?", [$reason, $id]);
        Auth::revokeAll($id);
        Audit::log($r->userId(), 'user.suspended', 'user', $id, $reason, ['status' => $user['status']], ['status' => 'suspended']);
        return ['ok' => true];
    }

    public function restoreUser(Request $r): array
    {
        $id = (int) $r->param('id');
        $reason = Validator::str($r->input('reason'), 'reason', 10, 500);
        Database::exec("UPDATE users SET status = 'active', suspension_reason = NULL WHERE id = ? AND account_type = 'member' AND status = 'suspended'", [$id]);
        Audit::log($r->userId(), 'user.restored', 'user', $id, $reason);
        return ['ok' => true];
    }

    public function pendingPhotos(Request $r): array
    {
        return ['items' => Database::all(
            "SELECT pp.id, pp.user_id, pp.mime, pp.created_at, u.username FROM profile_photos pp JOIN users u ON u.id = pp.user_id
             WHERE pp.moderation_status = 'pending' AND pp.deleted_at IS NULL ORDER BY pp.id ASC LIMIT 100"
        )];
    }

    public function photoMedia(Request $r): ?array
    {
        $photo = Database::one('SELECT * FROM profile_photos WHERE id = ? AND deleted_at IS NULL', [(int) $r->param('id')]);
        if ($photo === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Photo not found.');
        }
        Audit::log($r->userId(), 'photo.viewed', 'profile_photo', $photo['id']);
        Response::binary(SecureStorage::get('photos', $photo['storage_key']), $photo['mime'], 'inline', 'photo');
        return null;
    }

    public function reviewPhoto(Request $r): array
    {
        $photo = Database::one('SELECT * FROM profile_photos WHERE id = ? AND deleted_at IS NULL', [(int) $r->param('id')]);
        if ($photo === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Photo not found.');
        }
        $decision = Validator::in($r->input('decision'), 'decision', ['approve', 'reject']);
        $reason = $decision === 'reject' ? Validator::str($r->input('reason'), 'reason', 5, 500) : null;
        Database::exec(
            'UPDATE profile_photos SET moderation_status = ?, rejection_reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?',
            [$decision === 'approve' ? 'approved' : 'rejected', $reason, $r->userId(), $photo['id']]
        );
        if ($decision === 'reject') {
            Database::exec('UPDATE profile_photos SET is_primary = 0 WHERE id = ?', [$photo['id']]);
            Notifier::notify((int) $photo['user_id'], 'profile', 'A photo was not approved', $reason);
        }
        Audit::log($r->userId(), 'photo.' . $decision . 'd', 'profile_photo', $photo['id'], $reason);
        return ['ok' => true];
    }

    private function targetSummary(string $type, int $id): array
    {
        return match ($type) {
            'user' => ['username' => Database::value('SELECT username FROM users WHERE id = ?', [$id])],
            'post' => Database::one('SELECT u.username, LEFT(p.body, 280) AS excerpt, p.status FROM community_posts p JOIN users u ON u.id = p.author_id WHERE p.id = ?', [$id]) ?? [],
            'comment' => Database::one('SELECT u.username, LEFT(c.body, 280) AS excerpt, c.status FROM post_comments c JOIN users u ON u.id = c.author_id WHERE c.id = ?', [$id]) ?? [],
            'message' => Database::one('SELECT LEFT(body, 280) AS excerpt, type FROM messages WHERE id = ?', [$id]) ?? [],
            'call' => Database::one('SELECT media, status, duration_seconds FROM calls WHERE id = ?', [$id]) ?? [],
            default => [],
        };
    }

    private function authorOf(string $type, int $id): ?int
    {
        $value = match ($type) {
            'user' => $id,
            'post' => Database::value('SELECT author_id FROM community_posts WHERE id = ?', [$id]),
            'comment' => Database::value('SELECT author_id FROM post_comments WHERE id = ?', [$id]),
            'message' => Database::value('SELECT sender_id FROM messages WHERE id = ?', [$id]),
            default => null,
        };
        return $value === null ? null : (int) $value;
    }

    private function mask(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return strlen($value) <= 6 ? str_repeat('*', strlen($value)) : substr($value, 0, 3) . str_repeat('*', max(3, strlen($value) - 6)) . substr($value, -3);
    }
}
