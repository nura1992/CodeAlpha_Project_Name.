<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Audit;
use App\Core\Database;
use App\Core\Eligibility;
use App\Core\Request;

final class MatchController
{
    public function index(Request $r): array
    {
        $uid = $r->userId();
        $rows = Database::all(
            "SELECT m.id, m.status, m.created_at, c.id AS conversation_id, c.last_message_at,
                    IF(m.user_low_id = ?, m.user_high_id, m.user_low_id) AS other_id
             FROM matches m JOIN conversations c ON c.match_id = m.id
             WHERE (m.user_low_id = ? OR m.user_high_id = ?) AND m.status = 'active'
             ORDER BY COALESCE(c.last_message_at, m.created_at) DESC LIMIT 100",
            [$uid, $uid, $uid]
        );
        $items = [];
        foreach ($rows as $row) {
            $other = Eligibility::memberRow((int) $row['other_id']);
            $photo = Database::value(
                "SELECT id FROM profile_photos WHERE user_id = ? AND moderation_status = 'approved' AND deleted_at IS NULL ORDER BY is_primary DESC, id LIMIT 1",
                [$row['other_id']]
            );
            $items[] = [
                'match_id' => (int) $row['id'],
                'conversation_id' => (int) $row['conversation_id'],
                'user_id' => (int) $row['other_id'],
                'display_name' => $other['display_name'] ?? 'Member',
                'age' => $other ? Eligibility::ageOf((string) $other['date_of_birth']) : null,
                'photo_url' => $photo ? '/api/media/photos/' . $photo : null,
                'last_message_at' => $row['last_message_at'],
                'matched_at' => $row['created_at'],
            ];
        }
        return ['items' => $items];
    }

    /** Ends a match. The conversation history is kept (read-only); new messages and calls stop. */
    public function unmatch(Request $r): array
    {
        $uid = $r->userId();
        $match = Database::one('SELECT * FROM matches WHERE id = ? AND (user_low_id = ? OR user_high_id = ?)', [(int) $r->param('id'), $uid, $uid]);
        if ($match === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Match not found.');
        }
        if ($match['status'] !== 'active') {
            return ['ok' => true, 'status' => $match['status']];
        }
        Database::transaction(static function () use ($match, $uid): void {
            Database::exec("UPDATE matches SET status = 'unmatched', ended_by = ?, ended_at = NOW() WHERE id = ?", [$uid, $match['id']]);
            Database::exec(
                "UPDATE calls SET status = 'ended', end_reason = 'unmatched', ended_at = NOW()
                 WHERE conversation_id = (SELECT id FROM conversations WHERE match_id = ?) AND status IN ('ringing','accepted')",
                [$match['id']]
            );
        });
        Audit::log($uid, 'match.unmatched', 'match', $match['id']);
        return ['ok' => true, 'status' => 'unmatched'];
    }
}
