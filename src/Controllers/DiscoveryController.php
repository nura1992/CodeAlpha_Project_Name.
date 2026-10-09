<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Audit;
use App\Core\Database;
use App\Core\Eligibility;
use App\Core\Notifier;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/**
 * Discovery and mutual matching. The candidate query is built ON THE SERVER with the
 * gender-visibility rule baked into SQL, so a member can never retrieve restricted profiles
 * by changing parameters.
 */
final class DiscoveryController
{
    public function index(Request $r): array
    {
        $uid = $r->userId();
        $viewer = Eligibility::memberRow($uid);
        if (!Eligibility::isApproved($viewer)) {
            throw new ApiException(403, 'PROFILE_NOT_APPROVED',
                'Your profile must be approved before you can discover matches.',
                ['approval_status' => $viewer['approval_status'] ?? 'draft']);
        }

        // Only the opposite gender is ever discoverable through matchmaking.
        $targetGender = $viewer['gender'] === 'male' ? 'female' : 'male';
        $viewerAge = Eligibility::ageOf((string) $viewer['date_of_birth']);

        $minAge = max((int) $viewer['age_pref_min'], (int) $r->query('min_age', 18));
        $maxAge = min((int) $viewer['age_pref_max'], (int) $r->query('max_age', 99));
        $interest = $r->query('interest') ? strtolower(Validator::str($r->query('interest'), 'interest', 2, 40, false) ?? '') : '';
        $city = $r->query('city') ? Validator::str($r->query('city'), 'city', 2, 100, false) : '';
        $intent = $r->query('intent') ? Validator::in($r->query('intent'), 'intent', ['serious', 'marriage', 'friendship', 'dating', 'unsure']) : '';
        $before = max(0, (int) $r->query('before', 0));

        $sql = "SELECT u.id, p.display_name, p.bio, p.city, p.country, p.relationship_intent,
                       TIMESTAMPDIFF(YEAR, u.date_of_birth, CURDATE()) AS age,
                       (SELECT pp.id FROM profile_photos pp
                         WHERE pp.user_id = u.id AND pp.moderation_status = 'approved' AND pp.deleted_at IS NULL
                         ORDER BY pp.is_primary DESC, pp.id ASC LIMIT 1) AS photo_id
                FROM users u
                JOIN profiles p ON p.user_id = u.id
                WHERE u.account_type = 'member' AND u.status = 'active' AND u.deleted_at IS NULL
                  AND u.gender = ?
                  AND p.approval_status = 'approved'
                  AND p.looking_for_gender IN ('everyone', ?)
                  AND TIMESTAMPDIFF(YEAR, u.date_of_birth, CURDATE()) BETWEEN ? AND ?
                  AND ? BETWEEN p.age_pref_min AND p.age_pref_max
                  AND u.id <> ?
                  AND NOT EXISTS (SELECT 1 FROM blocks b WHERE (b.blocker_id = ? AND b.blocked_id = u.id) OR (b.blocker_id = u.id AND b.blocked_id = ?))
                  AND NOT EXISTS (SELECT 1 FROM likes l WHERE l.from_user_id = ? AND l.to_user_id = u.id)
                  AND NOT EXISTS (SELECT 1 FROM matches m WHERE m.user_low_id = LEAST(u.id, ?) AND m.user_high_id = GREATEST(u.id, ?))";
        $params = [$targetGender, $viewer['gender'], $minAge, $maxAge, $viewerAge, $uid, $uid, $uid, $uid, $uid, $uid];

        if ($interest !== '') {
            $sql .= ' AND EXISTS (SELECT 1 FROM profile_interests pi JOIN interests i ON i.id = pi.interest_id WHERE pi.user_id = u.id AND i.name = ?)';
            $params[] = $interest;
        }
        if ($city !== '') {
            $sql .= ' AND p.city = ?';
            $params[] = $city;
        }
        if ($intent !== '') {
            $sql .= ' AND p.relationship_intent = ?';
            $params[] = $intent;
        }
        if ($before > 0) {
            $sql .= ' AND u.id < ?';
            $params[] = $before;
        }
        $sql .= ' ORDER BY u.id DESC LIMIT 20';

        $rows = Database::all($sql, $params);
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'display_name' => $row['display_name'],
                'age' => (int) $row['age'],
                'bio' => $row['bio'],
                'city' => $row['city'],
                'country' => $row['country'],
                'relationship_intent' => $row['relationship_intent'],
                'interests' => array_column(Database::all(
                    'SELECT i.name FROM profile_interests pi JOIN interests i ON i.id = pi.interest_id WHERE pi.user_id = ? ORDER BY i.name',
                    [$row['id']]
                ), 'name'),
                'photo_url' => $row['photo_id'] ? '/api/media/photos/' . $row['photo_id'] : null,
            ];
        }
        return [
            'items' => $items,
            'next_before' => $items === [] ? null : end($items)['id'],
        ];
    }

    /** Like or pass. A mutual like creates an active match and its conversation in one transaction. */
    public function act(Request $r): array
    {
        $uid = $r->userId();
        $targetId = Validator::int($r->input('user_id'), 'user_id', 1, PHP_INT_MAX);
        $action = Validator::in($r->input('action'), 'action', ['like', 'pass']);
        if ($targetId === $uid) {
            Validator::fail('user_id', 'You cannot act on your own profile.');
        }
        Eligibility::assertCanView($uid, $targetId);
        RateLimiter::hit('discover:act:' . $uid, 300, 3600);

        $existing = Database::one(
            'SELECT status FROM matches WHERE user_low_id = LEAST(?, ?) AND user_high_id = GREATEST(?, ?)',
            [$uid, $targetId, $uid, $targetId]
        );
        if ($existing !== null) {
            throw new ApiException(409, 'MATCH_EXISTS', $existing['status'] === 'active'
                ? 'You are already matched with this member.'
                : 'This connection is no longer available.');
        }

        return Database::transaction(function () use ($uid, $targetId, $action): array {
            Database::exec(
                'INSERT INTO likes (from_user_id, to_user_id, action) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE action = VALUES(action), created_at = NOW()',
                [$uid, $targetId, $action]
            );
            if ($action === 'pass') {
                return ['matched' => false];
            }
            $reciprocal = Database::value(
                "SELECT 1 FROM likes WHERE from_user_id = ? AND to_user_id = ? AND action = 'like' FOR UPDATE",
                [$targetId, $uid]
            );
            if (!$reciprocal || Eligibility::isBlocked($uid, $targetId)) {
                return ['matched' => false];
            }
            [$low, $high] = $uid < $targetId ? [$uid, $targetId] : [$targetId, $uid];
            $matchId = Database::insert(
                "INSERT INTO matches (user_low_id, user_high_id, status) VALUES (?, ?, 'active')",
                [$low, $high]
            );
            $convId = Database::insert('INSERT INTO conversations (match_id) VALUES (?)', [$matchId]);
            Database::exec('INSERT INTO conversation_members (conversation_id, user_id) VALUES (?, ?), (?, ?)',
                [$convId, $low, $convId, $high]);
            Audit::log($uid, 'match.created', 'match', $matchId);
            Notifier::notify($uid, 'match', "It's a match!", 'Say hello to start a conversation.', ['match_id' => $matchId]);
            Notifier::notify($targetId, 'match', "It's a match!", 'Say hello to start a conversation.', ['match_id' => $matchId]);
            return ['matched' => true, 'match_id' => $matchId, 'conversation_id' => $convId];
        });
    }

    public function favouritesIndex(Request $r): array
    {
        $rows = Database::all(
            "SELECT f.favourite_user_id AS user_id, p.display_name, u.date_of_birth, f.created_at
             FROM favourites f JOIN users u ON u.id = f.favourite_user_id JOIN profiles p ON p.user_id = u.id
             WHERE f.user_id = ? AND u.status = 'active' ORDER BY f.created_at DESC",
            [$r->userId()]
        );
        return ['items' => array_map(static fn($row) => [
            'user_id' => (int) $row['user_id'],
            'display_name' => $row['display_name'],
            'age' => Eligibility::ageOf((string) $row['date_of_birth']),
            'created_at' => $row['created_at'],
        ], $rows)];
    }

    public function favouriteAdd(Request $r): array
    {
        $uid = $r->userId();
        $targetId = Validator::int($r->input('user_id'), 'user_id', 1, PHP_INT_MAX);
        Eligibility::assertCanView($uid, $targetId);
        Database::exec('INSERT IGNORE INTO favourites (user_id, favourite_user_id) VALUES (?, ?)', [$uid, $targetId]);
        return ['ok' => true];
    }

    public function favouriteRemove(Request $r): array
    {
        Database::exec('DELETE FROM favourites WHERE user_id = ? AND favourite_user_id = ?', [$r->userId(), (int) $r->param('id')]);
        return ['ok' => true];
    }
}
