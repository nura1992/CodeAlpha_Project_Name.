<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Audit;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/** Blocking, reporting and the safety actions that cut across modules. */
final class SafetyController
{
    public function blocksIndex(Request $r): array
    {
        $rows = Database::all(
            "SELECT b.blocked_id AS user_id, b.created_at, u.username FROM blocks b JOIN users u ON u.id = b.blocked_id
             WHERE b.blocker_id = ? ORDER BY b.created_at DESC",
            [$r->userId()]
        );
        return ['items' => $rows];
    }

    /** Blocking is immediate and bidirectional: it ends any active match, call and follow relationship. */
    public function block(Request $r): array
    {
        $uid = $r->userId();
        $target = Validator::int($r->input('user_id'), 'user_id', 1, PHP_INT_MAX);
        if ($target === $uid) {
            Validator::fail('user_id', 'You cannot block yourself.');
        }
        if (!Database::value("SELECT 1 FROM users WHERE id = ? AND deleted_at IS NULL", [$target])) {
            throw new ApiException(404, 'NOT_FOUND', 'Member not found.');
        }
        Database::transaction(static function () use ($uid, $target): void {
            Database::exec('INSERT IGNORE INTO blocks (blocker_id, blocked_id) VALUES (?, ?)', [$uid, $target]);
            Database::exec(
                "UPDATE matches SET status = 'blocked', ended_by = ?, ended_at = NOW()
                 WHERE user_low_id = LEAST(?, ?) AND user_high_id = GREATEST(?, ?) AND status = 'active'",
                [$uid, $uid, $target, $uid, $target]
            );
            Database::exec(
                "UPDATE calls SET status = 'ended', end_reason = 'blocked', ended_at = NOW()
                 WHERE ((caller_id = ? AND callee_id = ?) OR (caller_id = ? AND callee_id = ?)) AND status IN ('ringing','accepted')",
                [$uid, $target, $target, $uid]
            );
            Database::exec('DELETE FROM favourites WHERE (user_id = ? AND favourite_user_id = ?) OR (user_id = ? AND favourite_user_id = ?)', [$uid, $target, $target, $uid]);
            Database::exec('DELETE FROM follows WHERE (follower_id = ? AND followee_id = ?) OR (follower_id = ? AND followee_id = ?)', [$uid, $target, $target, $uid]);
        });
        Audit::log($uid, 'user.blocked', 'user', $target);
        return ['ok' => true];
    }

    public function unblock(Request $r): array
    {
        Database::exec('DELETE FROM blocks WHERE blocker_id = ? AND blocked_id = ?', [$r->userId(), (int) $r->param('id')]);
        return ['ok' => true];
    }

    /** Reports content, comments, messages, calls or members. Duplicate open reports are merged. */
    public function report(Request $r): array
    {
        $uid = $r->userId();
        $type = Validator::in($r->input('target_type'), 'target_type', ['user', 'post', 'comment', 'message', 'call']);
        $targetId = Validator::int($r->input('target_id'), 'target_id', 1, PHP_INT_MAX);
        $reason = Validator::in($r->input('reason'), 'reason',
            ['harassment', 'fake_profile', 'scam', 'nudity', 'hate', 'spam', 'underage', 'threat', 'other']);
        $details = Validator::str($r->input('details'), 'details', 0, 2000, false);

        $exists = match ($type) {
            'user' => $targetId !== $uid && Database::value('SELECT 1 FROM users WHERE id = ?', [$targetId]),
            'post' => Database::value('SELECT 1 FROM community_posts WHERE id = ?', [$targetId]),
            'comment' => Database::value('SELECT 1 FROM post_comments WHERE id = ?', [$targetId]),
            'message' => Database::value(
                'SELECT 1 FROM messages m JOIN conversation_members cm ON cm.conversation_id = m.conversation_id AND cm.user_id = ? WHERE m.id = ?',
                [$uid, $targetId]),
            'call' => Database::value('SELECT 1 FROM calls WHERE id = ? AND (caller_id = ? OR callee_id = ?)', [$targetId, $uid, $uid]),
        };
        if (!$exists) {
            throw new ApiException(404, 'NOT_FOUND', 'The item you are reporting could not be found.');
        }

        $existing = Database::value(
            "SELECT id FROM user_reports WHERE reporter_id = ? AND target_type = ? AND target_id = ? AND status IN ('open','reviewing')",
            [$uid, $type, $targetId]
        );
        if ($existing) {
            return ['id' => (int) $existing, 'message' => 'You have already reported this. Our team is reviewing it.'];
        }
        $id = Database::insert(
            'INSERT INTO user_reports (reporter_id, target_type, target_id, reason, details) VALUES (?, ?, ?, ?, ?)',
            [$uid, $type, $targetId, $reason, $details]
        );
        Response::status(201);
        return ['id' => $id, 'message' => 'Thank you. Our team will review this report.'];
    }
}
