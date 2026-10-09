<?php
declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;

/**
 * Server-side matchmaking rules. These are the ONLY place that decides whether one
 * member may see, like, message or call another. Controllers must call these helpers;
 * the JavaScript client never decides visibility.
 *
 * Rules:
 *  - both members active, age-eligible and with an APPROVED profile
 *  - male profiles are visible only to eligible female members, and vice versa
 *  - each side's "looking for" gender and age preferences must accept the other
 *  - no block in either direction
 */
final class Eligibility
{
    public static function ageOf(string $dob): int
    {
        return (new DateTimeImmutable($dob))->diff(new DateTimeImmutable('today'))->y;
    }

    public static function assertAdult(DateTimeImmutable $dob): void
    {
        $min = max(18, Settings::getInt('auth.min_age', 18)); // 18 is a hard floor
        $today = new DateTimeImmutable('today');
        if ($dob > $today || $dob->diff($today)->y < $min) {
            throw new ApiException(422, 'AGE_NOT_ELIGIBLE', "You must be at least {$min} years old to use this platform.", ['field' => 'date_of_birth']);
        }
    }

    /** Joined member + profile row used for every eligibility decision. */
    public static function memberRow(int $userId): ?array
    {
        return Database::one(
            "SELECT u.id, u.gender, u.date_of_birth, u.status, u.account_type,
                    p.display_name, p.approval_status, p.looking_for_gender, p.age_pref_min, p.age_pref_max
             FROM users u LEFT JOIN profiles p ON p.user_id = u.id
             WHERE u.id = ? AND u.deleted_at IS NULL",
            [$userId]
        );
    }

    public static function isApproved(?array $row): bool
    {
        return $row !== null
            && ($row['account_type'] ?? '') === 'member'
            && ($row['status'] ?? '') === 'active'
            && ($row['approval_status'] ?? '') === 'approved'
            && in_array($row['gender'] ?? '', ['male', 'female'], true);
    }

    public static function isBlocked(int $a, int $b): bool
    {
        return (bool) Database::value(
            'SELECT 1 FROM blocks WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?) LIMIT 1',
            [$a, $b, $b, $a]
        );
    }

    /** Gender and preference rules only (block and approval are checked separately). */
    public static function matchmakingAllowed(array $viewer, array $target): bool
    {
        if ((int) $viewer['id'] === (int) $target['id']) {
            return false;
        }
        if (!self::isApproved($viewer) || !self::isApproved($target)) {
            return false;
        }
        // Gender visibility: opposite-gender matchmaking only.
        if ($viewer['gender'] === $target['gender']) {
            return false;
        }
        if (!in_array($viewer['looking_for_gender'], ['everyone', $target['gender']], true)) {
            return false;
        }
        if (!in_array($target['looking_for_gender'], ['everyone', $viewer['gender']], true)) {
            return false;
        }
        $viewerAge = self::ageOf((string) $viewer['date_of_birth']);
        $targetAge = self::ageOf((string) $target['date_of_birth']);
        return $targetAge >= (int) $viewer['age_pref_min'] && $targetAge <= (int) $viewer['age_pref_max']
            && $viewerAge >= (int) $target['age_pref_min'] && $viewerAge <= (int) $target['age_pref_max'];
    }

    /** Returns the target row if the viewer may see it, otherwise a 404 (never reveal existence). */
    public static function assertCanView(int $viewerId, int $targetId): array
    {
        $viewer = self::memberRow($viewerId);
        $target = self::memberRow($targetId);
        if (!self::isApproved($viewer) || $target === null || !self::matchmakingAllowed($viewer, $target) || self::isBlocked($viewerId, $targetId)) {
            throw new ApiException(404, 'PROFILE_NOT_FOUND', 'This profile is not available.');
        }
        return $target;
    }

    /** Active match row between two members, or null. */
    public static function activeMatch(int $a, int $b): ?array
    {
        [$low, $high] = $a < $b ? [$a, $b] : [$b, $a];
        return Database::one("SELECT * FROM matches WHERE user_low_id = ? AND user_high_id = ? AND status = 'active'", [$low, $high]);
    }

    /** Messaging and calls require an active match, approved profiles on both sides and no block. */
    public static function canCommunicate(int $a, int $b): ?array
    {
        $match = self::activeMatch($a, $b);
        if ($match === null || self::isBlocked($a, $b)) {
            return null;
        }
        return self::isApproved(self::memberRow($a)) && self::isApproved(self::memberRow($b)) ? $match : null;
    }
}
