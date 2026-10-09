<?php
/**
 * VerificationService — video verification workflow.
 *
 * Rules enforced here (and by middleware on the routes):
 *   - Only staff with verification.review may view verification videos.
 *   - Only staff with verification.approve / verification.reject may decide.
 *   - Every decision records reviewer, timestamp, reason and status change
 *     (verification_reviews history + audit_logs).
 *   - Rejected users can correct the issue and resubmit.
 *   - Videos live outside the web root and are served via expiring signed
 *     URLs through authorised PHP endpoints only.
 *   - Disabling the profile_verification feature does NOT auto-approve
 *     pending profiles; it only closes the submission/review workflow.
 */

declare(strict_types=1);

namespace CodeAlpha\Services;

use CodeAlpha\Core\AuditLog;
use CodeAlpha\Core\Database;
use CodeAlpha\Core\Uploader;

final class VerificationService
{
    public static function submit(int $userId, array $file): array
    {
        $maxMb = (int) config('verification.max_size_mb', 25);
        $stored = Uploader::storeProtected($file, 'video', 'verifications', 'verify');
        // storeProtected applies the generic 100MB video cap; enforce ours.
        if ($stored['size'] > $maxMb * 1024 * 1024) {
            Uploader::secureDelete($stored['fs_path']);
            throw new \InvalidArgumentException('Verification video exceeds the ' . $maxMb . ' MB limit.');
        }

        $now = date('Y-m-d H:i:s');
        $requestId = (int) Database::insert('verification_requests', [
            'user_id'     => $userId,
            'media_path'  => $stored['path'],
            'mime'        => $stored['mime'],
            'size_bytes'  => $stored['size'],
            'status'      => 'submitted',
            'submitted_at' => $now,
            'created_at'  => $now,
        ]);

        // Profile becomes pending; visibility defaults to denied.
        Database::update('profiles', [
            'verification_status' => 'pending',
            'discoverable'        => 0,
            'updated_at'          => $now,
        ], 'user_id = :id', ['id' => $userId]);

        AuditLog::record($userId, 'verification.submit', 'verification_request', (string) $requestId);

        // Notify staff with verification.review permission.
        self::notifyReviewers('A new verification video is awaiting review.', ['request_id' => $requestId]);

        return ['request_id' => $requestId, 'status' => 'submitted'];
    }

    public static function queue(?string $status = null, int $limit = 50): array
    {
        $where = '1=1';
        $params = [];
        if ($status) {
            $where .= ' AND vr.status = :status';
            $params['status'] = $status;
        }
        return Database::all(
            "SELECT vr.*, u.email, u.phone, p.display_name
               FROM verification_requests vr
               JOIN users u ON u.id = vr.user_id
               LEFT JOIN profiles p ON p.user_id = vr.user_id
              WHERE $where
              ORDER BY vr.submitted_at ASC
              LIMIT $limit",
            $params
        );
    }

    public static function find(int $requestId): ?array
    {
        return Database::one(
            'SELECT vr.*, u.email, u.phone, p.display_name
               FROM verification_requests vr
               JOIN users u ON u.id = vr.user_id
               LEFT JOIN profiles p ON p.user_id = vr.user_id
              WHERE vr.id = :id',
            ['id' => $requestId]
        );
    }

    /**
     * Approve a verification request. Requires the caller to hold
     * verification.approve (enforced by PermissionMiddleware on the route;
     * re-checked here for defence in depth).
     */
    public static function approve(int $requestId, int $reviewerId, string $permission = 'verification.approve'): array
    {
        if (!RbacService::can($reviewerId, $permission)) {
            throw new \RuntimeException('Missing permission: ' . $permission);
        }
        $request = self::find($requestId);
        if (!$request) {
            throw new \InvalidArgumentException('Verification request not found.');
        }
        if (!in_array($request['status'], ['submitted', 'under_review'], true)) {
            throw new \InvalidArgumentException('This request has already been decided.');
        }

        $now = date('Y-m-d H:i:s');
        Database::transaction(static function () use ($request, $reviewerId, $now, $requestId) {
            Database::update('verification_requests', [
                'status'      => 'approved',
                'reviewer_id' => $reviewerId,
                'reviewed_at' => $now,
            ], 'id = :id', ['id' => $requestId]);

            Database::insert('verification_reviews', [
                'request_id'  => $requestId,
                'reviewer_id' => $reviewerId,
                'decision'    => 'approved',
                'reason'      => null,
                'created_at'  => $now,
            ]);

            // Approve the profile. Visibility still requires a complete profile.
            $profile = Database::one('SELECT * FROM profiles WHERE user_id = :id', ['id' => $request['user_id']]);
            $discoverable = self::profileIsComplete($profile) ? 1 : 0;
            Database::update('profiles', [
                'verification_status' => 'approved',
                'verified_at'         => $now,
                'discoverable'        => $discoverable,
                'updated_at'          => $now,
            ], 'user_id = :id', ['id' => $request['user_id']]);
        });

        AuditLog::record($reviewerId, 'verification.approve', 'verification_request', (string) $requestId, [
            'user_id' => (int) $request['user_id'],
        ]);

        NotificationService::notify(
            (int) $request['user_id'],
            'verification_approved',
            'Your profile is verified ✓',
            $discoverable ?? false
                ? 'Your profile is now visible in discovery.'
                : 'Your profile is verified. Complete your profile to appear in discovery.',
            ['request_id' => $requestId]
        );

        return ['request_id' => $requestId, 'status' => 'approved'];
    }

    public static function reject(int $requestId, int $reviewerId, string $reason): array
    {
        if (!RbacService::can($reviewerId, 'verification.reject')) {
            throw new \RuntimeException('Missing permission: verification.reject');
        }
        $request = self::find($requestId);
        if (!$request) {
            throw new \InvalidArgumentException('Verification request not found.');
        }
        if (!in_array($request['status'], ['submitted', 'under_review'], true)) {
            throw new \InvalidArgumentException('This request has already been decided.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('A rejection reason is required.');
        }

        $now = date('Y-m-d H:i:s');
        Database::transaction(static function () use ($request, $reviewerId, $reason, $now, $requestId) {
            Database::update('verification_requests', [
                'status'        => 'rejected',
                'reviewer_id'   => $reviewerId,
                'review_reason' => substr($reason, 0, 255),
                'reviewed_at'   => $now,
            ], 'id = :id', ['id' => $requestId]);

            Database::insert('verification_reviews', [
                'request_id'  => $requestId,
                'reviewer_id' => $reviewerId,
                'decision'    => 'rejected',
                'reason'      => substr($reason, 0, 255),
                'created_at'  => $now,
            ]);

            // Rejected profiles stay invisible; the user may resubmit.
            Database::update('profiles', [
                'verification_status' => 'rejected',
                'discoverable'        => 0,
                'updated_at'          => $now,
            ], 'user_id = :id', ['id' => $request['user_id']]);
        });

        AuditLog::record($reviewerId, 'verification.reject', 'verification_request', (string) $requestId, [
            'user_id' => (int) $request['user_id'], 'reason' => $reason,
        ]);

        NotificationService::notify(
            (int) $request['user_id'],
            'verification_rejected',
            'Verification not approved',
            'Reason: ' . $reason . ' — you can correct the issue and resubmit.',
            ['request_id' => $requestId, 'reason' => $reason]
        );

        return ['request_id' => $requestId, 'status' => 'rejected', 'reason' => $reason];
    }

    /** A profile is discoverable once verified and reasonably complete. */
    public static function profileIsComplete(?array $profile): bool
    {
        if (!$profile) {
            return false;
        }
        $photoCount = (int) Database::scalar(
            "SELECT COUNT(*) FROM profile_photos WHERE user_id = :id AND moderation_status = 'approved'",
            ['id' => $profile['user_id']]
        );
        return $photoCount >= 1
            && !empty($profile['display_name'])
            && !empty($profile['location_city']);
    }

    /** Recompute the profile completeness score (0-100). */
    public static function recomputeScore(int $userId): int
    {
        $profile = Database::one('SELECT * FROM profiles WHERE user_id = :id', ['id' => $userId]);
        if (!$profile) {
            return 0;
        }
        $fields = ['display_name', 'bio', 'location_city', 'location_country', 'relationship_intention'];
        $score = 0;
        foreach ($fields as $field) {
            if (!empty($profile[$field])) {
                $score += 10;
            }
        }
        $interests = json_decode((string) ($profile['interests'] ?? ''), true) ?: [];
        if (count($interests) >= 3) {
            $score += 10;
        }
        $photoCount = (int) Database::scalar(
            "SELECT COUNT(*) FROM profile_photos WHERE user_id = :id AND moderation_status = 'approved'",
            ['id' => $userId]
        );
        if ($photoCount >= 1) {
            $score += 20;
        }
        if ($photoCount >= 3) {
            $score += 10;
        }
        if ($profile['verification_status'] === 'approved') {
            $score += 10;
        }
        $score = min(100, $score);
        Database::update('profiles', ['profile_score' => $score, 'updated_at' => date('Y-m-d H:i:s')], 'user_id = :id', ['id' => $userId]);
        return $score;
    }

    private static function notifyReviewers(string $message, array $data = []): void
    {
        $staff = Database::all(
            'SELECT DISTINCT ur.user_id FROM user_roles ur
               JOIN role_permissions rp ON rp.role_id = ur.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE p.slug = :slug',
            ['slug' => 'verification.review']
        );
        foreach ($staff as $row) {
            NotificationService::notify((int) $row['user_id'], 'verification_queue', $message, '', $data);
        }
    }
}
