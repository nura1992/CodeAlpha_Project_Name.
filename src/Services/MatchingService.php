<?php
/**
 * MatchingService — discovery, likes, passes, matches, favourites, blocks.
 *
 * SECURITY INVARIANT (server-side, never JavaScript):
 *   Male profiles are discoverable only by eligible female users, and
 *   female profiles only by eligible male users. The gender filter is
 *   applied inside the SQL query itself, derived from the viewer's
 *   server-stored gender. A client cannot retrieve profiles of the wrong
 *   gender through any API parameter.
 *
 * Profile visibility defaults to DENIED: only profiles with
 * verification_status = 'approved' and discoverable = 1 appear anywhere.
 */

declare(strict_types=1);

namespace CodeAlpha\Services;

use CodeAlpha\Core\Database;
use CodeAlpha\Core\FeatureFlags;

final class MatchingService
{
    // ------------------------------------------------------------------
    // Gender rule helpers
    // ------------------------------------------------------------------

    public static function oppositeGender(string $gender): string
    {
        return $gender === 'male' ? 'female' : 'male';
    }

    public static function isApproved(array $userOrProfile): bool
    {
        return ($userOrProfile['verification_status'] ?? '') === 'approved'
            && (int) ($userOrProfile['discoverable'] ?? 0) === 1;
    }

    /**
     * The core eligibility rule between two members: both approved, opposite
     * genders, and neither has blocked the other.
     */
    public static function canInteract(int $viewerId, array $target): bool
    {
        $viewer = Database::one('SELECT * FROM users WHERE id = :id', ['id' => $viewerId]);
        if (!$viewer || ($viewer['status'] ?? '') !== 'active') {
            return false;
        }
        if ((string) ($viewer['gender'] ?? '') === '' || (string) ($target['gender'] ?? '') === '') {
            return false;
        }
        // Server-side gender rule: only opposite genders may interact.
        if ($viewer['gender'] === $target['gender']) {
            return false;
        }
        if ((string) ($target['status'] ?? '') !== 'active') {
            return false;
        }
        $profile = Database::one('SELECT * FROM profiles WHERE user_id = :id', ['id' => $target['id']]);
        if (!$profile || !self::isApproved($profile)) {
            return false;
        }
        if (self::isBlocked($viewerId, (int) $target['id']) || self::isBlocked((int) $target['id'], $viewerId)) {
            return false;
        }
        return true;
    }

    public static function isBlocked(int $blockerId, int $blockedId): bool
    {
        return (bool) Database::scalar(
            'SELECT COUNT(*) FROM user_blocks WHERE blocker_id = :a AND blocked_id = :b',
            ['a' => $blockerId, 'b' => $blockedId]
        );
    }

    public static function getMatchBetween(int $userA, int $userB): ?array
    {
        [$one, $two] = $userA < $userB ? [$userA, $userB] : [$userB, $userA];
        $match = Database::one(
            'SELECT * FROM matches WHERE user_one_id = :a AND user_two_id = :b AND status = :status',
            ['a' => $one, 'b' => $two, 'status' => 'active']
        );
        return $match ?: null;
    }

    public static function hasActiveMatch(int $userA, int $userB): bool
    {
        return self::getMatchBetween($userA, $userB) !== null;
    }

    public static function conversationBetween(int $userA, int $userB): ?array
    {
        $match = self::getMatchBetween($userA, $userB);
        if (!$match) {
            return null;
        }
        return Database::one(
            'SELECT c.* FROM conversations c
               JOIN conversation_members m1 ON m1.conversation_id = c.id AND m1.user_id = :a AND m1.left_at IS NULL
               JOIN conversation_members m2 ON m2.conversation_id = c.id AND m2.user_id = :b AND m2.left_at IS NULL
              WHERE c.match_id = :m LIMIT 1',
            ['a' => $userA, 'b' => $userB, 'm' => $match['id']]
        );
    }

    // ------------------------------------------------------------------
    // Discovery
    // ------------------------------------------------------------------

    /**
     * Discovery feed for the viewer. The opposite-gender filter is applied
     * in SQL — this is the server-side authorization rule.
     *
     * @return array{items:array, total:int}
     */
    public static function discover(array $viewer, array $filters = []): array
    {
        $viewerId = (int) $viewer['id'];
        $viewerGender = (string) $viewer['gender'];
        $oppGender = self::oppositeGender($viewerGender);

        $profile = Database::one('SELECT * FROM profiles WHERE user_id = :id', ['id' => $viewerId]);
        $minAge = max(18, (int) ($filters['min_age'] ?? ($profile['min_age'] ?? 18)));
        $maxAge = min(99, (int) ($filters['max_age'] ?? ($profile['max_age'] ?? 99)));
        if ($maxAge < $minAge) {
            [$minAge, $maxAge] = [$maxAge, $minAge];
        }
        // The 18+ floor is always enforced when the age_check feature is on.
        if (FeatureFlags::isEnabled('age_check')) {
            $minAge = max(18, $minAge);
        }

        $intention = $filters['relationship_intention'] ?? null;
        $interests = array_filter(array_map('trim', (array) ($filters['interests'] ?? [])));
        $maxDistance = isset($filters['max_distance_km']) ? (float) $filters['max_distance_km'] : null;

        $ageExpr = Database::ageSql('u.birthdate');
        // Use integer literals for age bounds to avoid SQLite PDO binding issues with BETWEEN
        $minAge = (int) $minAge;
        $maxAge = (int) $maxAge;
        $params = [
            'viewer_id' => $viewerId,
            'opp'       => $oppGender,
        ];
        $sql = "SELECT u.id, u.uuid, u.gender, u.birthdate, u.last_login_at,
                       p.display_name, p.bio, p.interests, p.location_city, p.location_country,
                       p.latitude, p.longitude, p.relationship_intention, p.profile_score,
                       p.last_active_at, p.verified_at,
                       ($ageExpr) AS age,
                       (SELECT path FROM profile_photos pp WHERE pp.user_id = u.id AND pp.is_avatar = 1 AND pp.moderation_status = 'approved' ORDER BY pp.position ASC LIMIT 1) AS avatar,
                       (SELECT COUNT(*) FROM profile_photos pp2 WHERE pp2.user_id = u.id AND pp2.moderation_status = 'approved') AS photo_count
                  FROM profiles p
                  JOIN users u ON u.id = p.user_id
                 WHERE p.verification_status = 'approved'
                   AND p.discoverable = 1
                   AND p.hidden = 0
                   AND u.status = 'active'
                   AND u.gender = :opp
                   AND u.birthdate IS NOT NULL
                   AND ($ageExpr) BETWEEN $minAge AND $maxAge
                   AND u.id <> :viewer_id
                   AND u.id NOT IN (SELECT target_user_id FROM likes WHERE user_id = :viewer_id)
                   AND u.id NOT IN (SELECT blocked_id FROM user_blocks WHERE blocker_id = :viewer_id)
                   AND u.id NOT IN (SELECT blocker_id FROM user_blocks WHERE blocked_id = :viewer_id)
                   AND u.id NOT IN (
                       SELECT CASE WHEN user_one_id = :viewer_id THEN user_two_id ELSE user_one_id END
                         FROM matches
                        WHERE (user_one_id = :viewer_id OR user_two_id = :viewer_id) AND status = 'active'
                   )";
        if ($intention) {
            $sql .= ' AND p.relationship_intention = :intention';
            $params['intention'] = $intention;
        }
        $sql .= ' ORDER BY p.last_active_at DESC, p.profile_score DESC, u.id DESC LIMIT 200';

        $rows = Database::all($sql, $params);

        // PHP-side filters: distance (haversine) and shared interests.
        $viewerLat = $profile['latitude'] ?? null;
        $viewerLng = $profile['longitude'] ?? null;
        $items = [];
        foreach ($rows as $row) {
            $distance = null;
            if ($maxDistance !== null && $viewerLat !== null && $viewerLng !== null
                && $row['latitude'] !== null && $row['longitude'] !== null) {
                $distance = self::haversineKm(
                    (float) $viewerLat, (float) $viewerLng,
                    (float) $row['latitude'], (float) $row['longitude']
                );
                if ($distance > $maxDistance) {
                    continue;
                }
            }
            $rowInterests = json_decode((string) ($row['interests'] ?? ''), true) ?: [];
            if ($interests !== []) {
                $shared = array_intersect(array_map('strtolower', $interests), array_map('strtolower', $rowInterests));
                if (!$shared) {
                    continue;
                }
                $row['shared_interests'] = array_values($shared);
            }
            $items[] = self::cardFromRow($row, $distance);
        }

        // Simple page slicing over the filtered list.
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = 20;
        $total = count($items);
        return [
            'items' => array_slice($items, ($page - 1) * $perPage, $perPage),
            'total' => $total,
            'page'  => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * The limited discovery card. Safe for any eligible viewer: no exact
     * coordinates, no verification media, privacy-flagged fields omitted.
     */
    public static function cardFromRow(array $row, ?float $distanceKm = null): array
    {
        $privacy = json_decode((string) ($row['privacy'] ?? ''), true) ?: [];
        $showAge = (bool) ($privacy['show_age'] ?? true);
        return [
            'id'                     => (int) $row['id'],
            'uuid'                   => $row['uuid'],
            'display_name'           => $row['display_name'],
            'age'                    => $showAge && isset($row['age']) ? (int) $row['age'] : null,
            'show_age'               => $showAge,
            'location_city'          => $row['location_city'],
            'location_country'       => $row['location_country'],
            'distance_km'            => $distanceKm !== null && ($privacy['show_distance'] ?? true) ? round($distanceKm, 1) : null,
            'relationship_intention' => $row['relationship_intention'],
            'interests'              => json_decode((string) ($row['interests'] ?? ''), true) ?: [],
            'shared_interests'       => $row['shared_interests'] ?? [],
            'bio'                    => $row['bio'],
            'avatar'                 => $row['avatar'] ? '/uploads/' . $row['avatar'] : null,
            'photo_count'            => (int) ($row['photo_count'] ?? 0),
            'verified'               => !empty($row['verified_at']),
            'profile_score'          => (int) ($row['profile_score'] ?? 0),
            'last_active_at'         => $row['last_active_at'],
            'is_online'              => ($privacy['show_online'] ?? true)
                ? (!empty($row['last_active_at']) && strtotime((string) $row['last_active_at']) > time() - 900)
                : null,
        ];
    }

    /**
     * Full matchmaking profile. Only for: the owner, staff with users.view,
     * or members with an active match / conversation (the allowed matching
     * relationship).
     */
    public static function fullProfile(int $viewerId, array $target, bool $isStaff = false): ?array
    {
        $targetId = (int) $target['id'];
        if ($viewerId === $targetId || $isStaff) {
            // allowed
        } elseif (!self::canInteract($viewerId, $target)) {
            return null;
        } elseif (!self::hasActiveMatch($viewerId, $targetId) && !self::conversationBetween($viewerId, $targetId)) {
            // Eligible viewers get the discovery card only; full details
            // require an active match or conversation.
        }

        $profile = Database::one('SELECT * FROM profiles WHERE user_id = :id', ['id' => $targetId]);
        if (!$profile) {
            return null;
        }
        $photos = Database::all(
            "SELECT id, path, mime, is_avatar, position FROM profile_photos
              WHERE user_id = :id AND moderation_status = 'approved' ORDER BY is_avatar DESC, position ASC",
            ['id' => $targetId]
        );
        foreach ($photos as &$photo) {
            $photo['url'] = '/uploads/' . $photo['path'];
        }
        unset($photo);

        $privacy = json_decode((string) ($profile['privacy'] ?? ''), true) ?: [];
        $age = age_from_birthdate($target['birthdate'] ?? null);

        return [
            'id'                     => $targetId,
            'uuid'                   => $target['uuid'],
            'display_name'           => $profile['display_name'],
            'age'                    => $age,
            'gender'                 => $target['gender'],
            'bio'                    => $profile['bio'],
            'interests'              => json_decode((string) ($profile['interests'] ?? ''), true) ?: [],
            'location_city'          => $profile['location_city'],
            'location_country'       => $profile['location_country'],
            'relationship_intention' => $profile['relationship_intention'],
            'height_cm'              => $profile['height_cm'],
            'education'              => $profile['education'],
            'occupation'             => $profile['occupation'],
            'religion'               => $profile['religion'],
            'smoking'                => $profile['smoking'],
            'drinking'               => $profile['drinking'],
            'photos'                 => $photos,
            'verified'               => !empty($profile['verified_at']),
            'verification_status'    => $profile['verification_status'],
            'profile_score'          => (int) $profile['profile_score'],
            'last_active_at'         => $profile['last_active_at'],
            'is_online'              => ($privacy['show_online'] ?? true)
                ? (!empty($profile['last_active_at']) && strtotime((string) $profile['last_active_at']) > time() - 900)
                : null,
            'matched'                => self::hasActiveMatch($viewerId, $targetId),
            'has_conversation'       => self::conversationBetween($viewerId, $targetId) !== null,
        ];
    }

    // ------------------------------------------------------------------
    // Likes, passes, favourites, matches
    // ------------------------------------------------------------------

    public static function like(int $userId, int $targetId): array
    {
        $target = self::requireInteractable($userId, $targetId);
        self::recordAction($userId, $targetId, 'like');

        // Mutual like → match.
        $reverse = Database::one(
            "SELECT id FROM likes WHERE user_id = :t AND target_user_id = :u AND action = 'like'",
            ['t' => $targetId, 'u' => $userId]
        );
        $matched = false;
        if ($reverse) {
            $matched = (bool) self::createMatch($userId, $targetId);
        }

        NotificationService::notify(
            $targetId,
            'like',
            ($matched ? 'You have a new match! 🎉' : 'Someone liked your profile'),
            $matched ? 'You can now chat and call each other.' : 'Open discovery to like them back.',
            ['user_id' => $userId, 'matched' => $matched]
        );

        return ['matched' => $matched, 'target_id' => $targetId];
    }

    public static function pass(int $userId, int $targetId): array
    {
        self::requireInteractable($userId, $targetId);
        self::recordAction($userId, $targetId, 'pass');
        return ['passed' => true, 'target_id' => $targetId];
    }

    private static function recordAction(int $userId, int $targetId, string $action): void
    {
        $existing = Database::one(
            'SELECT id, action FROM likes WHERE user_id = :u AND target_user_id = :t',
            ['u' => $userId, 't' => $targetId]
        );
        if ($existing) {
            Database::update('likes', ['action' => $action], 'id = :id', ['id' => $existing['id']]);
        } else {
            Database::insert('likes', [
                'user_id' => $userId, 'target_user_id' => $targetId, 'action' => $action, 'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private static function createMatch(int $userA, int $userB): bool
    {
        [$one, $two] = $userA < $userB ? [$userA, $userB] : [$userB, $userA];
        $existing = Database::one(
            'SELECT * FROM matches WHERE user_one_id = :a AND user_two_id = :b',
            ['a' => $one, 'b' => $two]
        );
        if ($existing) {
            if ($existing['status'] !== 'active') {
                Database::update('matches', ['status' => 'active', 'unmatched_by' => null, 'unmatched_at' => null], 'id = :id', ['id' => $existing['id']]);
            }
            return true;
        }
        return Database::transaction(static function () use ($one, $two) {
            $matchId = (int) Database::insert('matches', [
                'user_one_id' => $one, 'user_two_id' => $two, 'status' => 'active', 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $now = date('Y-m-d H:i:s');
            $convId = (int) Database::insert('conversations', [
                'match_id' => $matchId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ([$one, $two] as $uid) {
                Database::insert('conversation_members', [
                    'conversation_id' => $convId, 'user_id' => $uid, 'joined_at' => $now,
                ]);
            }
            return true;
        });
    }

    public static function unmatch(int $userId, int $matchId): bool
    {
        $match = Database::one('SELECT * FROM matches WHERE id = :id', ['id' => $matchId]);
        if (!$match || $match['status'] !== 'active') {
            return false;
        }
        if ((int) $match['user_one_id'] !== $userId && (int) $match['user_two_id'] !== $userId) {
            return false;
        }
        $other = (int) $match['user_one_id'] === $userId ? (int) $match['user_two_id'] : (int) $match['user_one_id'];
        $now = date('Y-m-d H:i:s');
        Database::transaction(static function () use ($match, $userId, $other, $now) {
            Database::update('matches', [
                'status' => 'unmatched', 'unmatched_by' => $userId, 'unmatched_at' => $now,
            ], 'id = :id', ['id' => $match['id']]);
            Database::query(
                'UPDATE conversation_members SET left_at = :now
                  WHERE conversation_id IN (SELECT id FROM conversations WHERE match_id = :mid)
                    AND user_id IN (:a, :b)',
                ['now' => $now, 'mid' => $match['id'], 'a' => $userId, 'b' => $other]
            );
        });
        NotificationService::notify($other, 'unmatch', 'A match was ended', '', ['user_id' => $userId]);
        return true;
    }

    public static function favourite(int $userId, int $targetId): bool
    {
        self::requireInteractable($userId, $targetId);
        $exists = Database::one(
            'SELECT 1 FROM favourites WHERE user_id = :u AND target_user_id = :t',
            ['u' => $userId, 't' => $targetId]
        );
        if (!$exists) {
            Database::insert('favourites', [
                'user_id' => $userId, 'target_user_id' => $targetId, 'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
        return true;
    }

    public static function unfavourite(int $userId, int $targetId): bool
    {
        Database::delete('favourites', 'user_id = :u AND target_user_id = :t', ['u' => $userId, 't' => $targetId]);
        return true;
    }

    public static function matches(int $userId): array
    {
        $rows = Database::all(
            'SELECT m.id AS match_id, m.created_at AS matched_at,
                    CASE WHEN m.user_one_id = :uid THEN m.user_two_id ELSE m.user_one_id END AS other_id
               FROM matches m
              WHERE (m.user_one_id = :uid OR m.user_two_id = :uid) AND m.status = :status
              ORDER BY m.created_at DESC',
            ['uid' => $userId, 'status' => 'active']
        );
        return array_map(static fn ($row) => self::withCard((int) $row['other_id'], $row), $rows);
    }

    public static function favourites(int $userId): array
    {
        $rows = Database::all(
            'SELECT f.target_user_id, f.created_at FROM favourites f WHERE f.user_id = :uid ORDER BY f.created_at DESC',
            ['uid' => $userId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::withCard((int) $row['target_user_id'], ['created_at' => $row['created_at']]);
        }
        return $out;
    }

    private static function withCard(int $userId, array $extra): array
    {
        $user = Database::one('SELECT * FROM users WHERE id = :id', ['id' => $userId]);
        $profile = Database::one('SELECT * FROM profiles WHERE user_id = :id', ['id' => $userId]);
        if (!$user || !$profile) {
            return ['id' => $userId, 'available' => false] + $extra;
        }
        $avatar = Database::scalar(
            "SELECT path FROM profile_photos WHERE user_id = :id AND is_avatar = 1 AND moderation_status = 'approved' ORDER BY position ASC LIMIT 1",
            ['id' => $userId]
        );
        return [
            'id' => $userId,
            'uuid' => $user['uuid'],
            'display_name' => $profile['display_name'],
            'age' => age_from_birthdate($user['birthdate']),
            'location_city' => $profile['location_city'],
            'avatar' => $avatar ? '/uploads/' . $avatar : null,
            'verified' => !empty($profile['verified_at']),
            'available' => true,
        ] + $extra;
    }

    // ------------------------------------------------------------------
    // Blocks
    // ------------------------------------------------------------------

    public static function block(int $userId, int $targetId): bool
    {
        if ($userId === $targetId) {
            return false;
        }
        $exists = Database::one(
            'SELECT id FROM user_blocks WHERE blocker_id = :a AND blocked_id = :b',
            ['a' => $userId, 'b' => $targetId]
        );
        if ($exists) {
            return true;
        }
        Database::transaction(static function () use ($userId, $targetId) {
            Database::insert('user_blocks', [
                'blocker_id' => $userId, 'blocked_id' => $targetId, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            // A block ends any active match and closes the conversation.
            $match = self::getMatchBetween($userId, $targetId);
            if ($match) {
                $now = date('Y-m-d H:i:s');
                Database::update('matches', [
                    'status' => 'unmatched', 'unmatched_by' => $userId, 'unmatched_at' => $now,
                ], 'id = :id', ['id' => $match['id']]);
                Database::query(
                    'UPDATE conversation_members SET left_at = :now
                      WHERE conversation_id IN (SELECT id FROM conversations WHERE match_id = :mid)',
                    ['now' => $now, 'mid' => $match['id']]
                );
            }
        });
        \CodeAlpha\Core\AuditLog::record($userId, 'user.block', 'user', (string) $targetId);
        return true;
    }

    public static function unblock(int $userId, int $targetId): bool
    {
        Database::delete('user_blocks', 'blocker_id = :a AND blocked_id = :b', ['a' => $userId, 'b' => $targetId]);
        return true;
    }

    // ------------------------------------------------------------------
    // Misc
    // ------------------------------------------------------------------

    /** Throttled activity heartbeat. */
    public static function heartbeat(int $userId): void
    {
        $profile = Database::one('SELECT last_active_at FROM profiles WHERE user_id = :id', ['id' => $userId]);
        if ($profile && !empty($profile['last_active_at']) && strtotime((string) $profile['last_active_at']) > time() - 300) {
            return;
        }
        Database::query(
            'UPDATE profiles SET last_active_at = :now WHERE user_id = :id',
            ['now' => date('Y-m-d H:i:s'), 'id' => $userId]
        );
    }

    /** Haversine distance in kilometres. */
    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Load the target and enforce the interaction rules. Throws on failure.
     */
    private static function requireInteractable(int $userId, int $targetId): array
    {
        if ($userId === $targetId) {
            throw new \InvalidArgumentException('You cannot interact with yourself.');
        }
        $target = Database::one('SELECT * FROM users WHERE id = :id AND deleted_at IS NULL', ['id' => $targetId]);
        if (!$target) {
            throw new \InvalidArgumentException('User not found.');
        }
        if (!self::canInteract($userId, $target)) {
            // Do not reveal why — same error shape as "not found".
            throw new \InvalidArgumentException('This profile is not available.');
        }
        return $target;
    }
}
