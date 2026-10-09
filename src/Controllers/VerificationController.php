<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Audit;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Eligibility;
use App\Core\Features;
use App\Core\Notifier;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Rbac;
use App\Core\SecureStorage;
use App\Core\Settings;
use App\Core\Validator;

/**
 * Video verification workflow.
 * Member submits -> reviewer claims (verification.review) -> reviewer streams media via a
 * short-lived signed link -> approver (verification.approve) or rejecter (verification.reject)
 * records the decision with a reason. Verification proves the platform completed its checks;
 * it does not guarantee a person's identity or intentions.
 */
final class VerificationController
{
    private const MIMES = ['video/mp4', 'video/webm', 'video/quicktime'];
    private const MAX_BYTES = 60 * 1048576;
    private const LINK_TTL = 300;

    // ----------------------------------------------------------- member side
    public function myStatus(Request $r): array
    {
        $uid = $r->userId();
        $rows = Database::all(
            'SELECT id, status, submitted_at, decided_at, decision_reason, media_deleted_at
             FROM verification_requests WHERE user_id = ? ORDER BY id DESC LIMIT 10',
            [$uid]
        );
        $profile = Database::one('SELECT approval_status, rejection_reason FROM profiles WHERE user_id = ?', [$uid]);
        return ['requests' => $rows, 'approval_status' => $profile['approval_status'] ?? 'draft', 'rejection_reason' => $profile['rejection_reason'] ?? null];
    }

    public function submit(Request $r): array
    {
        Features::require('account.verification');
        $uid = $r->userId();
        $approval = Database::value('SELECT approval_status FROM profiles WHERE user_id = ?', [$uid]);
        if ($approval === null || $approval === 'suspended') {
            throw new ApiException(403, 'PROFILE_SUSPENDED', 'Your profile cannot be verified right now. Contact support.');
        }
        if (!Validator::boolean($r->input('consent'))) {
            Validator::fail('consent', 'You must confirm consent to record and review this video.');
        }
        $open = Database::value("SELECT id FROM verification_requests WHERE user_id = ? AND status IN ('submitted','in_review') LIMIT 1", [$uid]);
        if ($open) {
            throw new ApiException(409, 'VERIFICATION_IN_PROGRESS', 'You already have a verification request under review.');
        }
        RateLimiter::hit('verify:user:' . $uid, 3, 86400);

        $file = SecureStorage::inspectUpload($r->files['video'] ?? null, self::MIMES, self::MAX_BYTES, 'video');
        $bytes = (string) file_get_contents($file['tmp']);
        $key = SecureStorage::put('verification', $bytes);
        unset($bytes);

        $id = Database::transaction(static function () use ($uid, $file, $key): int {
            $id = Database::insert(
                "INSERT INTO verification_requests (user_id, status, storage_key, video_mime, video_size_bytes, video_sha256)
                 VALUES (?, 'submitted', ?, ?, ?, ?)",
                [$uid, $key, $file['mime'], $file['size'], $file['sha256']]
            );
            Database::exec("UPDATE profiles SET approval_status = 'pending', reverification_required = 0 WHERE user_id = ?", [$uid]);
            return $id;
        });
        Audit::log($uid, 'verification.submitted', 'verification_request', $id);
        Response::status(201);
        return ['id' => $id, 'status' => 'submitted', 'message' => 'Thanks. Your video is queued for review.'];
    }

    // ----------------------------------------------------------- staff side
    public function queue(Request $r): array
    {
        $status = in_array($r->query('status'), ['submitted', 'in_review', 'approved', 'rejected'], true) ? (string) $r->query('status') : 'submitted';
        // Reviewers see only what they need: display name, gender, age and status. No contact details.
        $rows = Database::all(
            "SELECT vr.id, vr.status, vr.submitted_at, vr.assigned_reviewer_id, vr.decided_at, vr.decision_reason,
                    u.id AS user_id, p.display_name, u.gender, TIMESTAMPDIFF(YEAR, u.date_of_birth, CURDATE()) AS age
             FROM verification_requests vr
             JOIN users u ON u.id = vr.user_id
             JOIN profiles p ON p.user_id = u.id
             WHERE vr.status = ?
             ORDER BY vr.submitted_at ASC LIMIT 100",
            [$status]
        );
        return ['items' => $rows];
    }

    public function claim(Request $r): array
    {
        $req = $this->loadRequest((int) $r->param('id'));
        $me = $r->userId();
        Database::transaction(function () use ($req, $me): void {
            $row = Database::one('SELECT * FROM verification_requests WHERE id = ? FOR UPDATE', [$req['id']]);
            if (!in_array($row['status'], ['submitted', 'in_review'], true)) {
                throw new ApiException(409, 'INVALID_STATE', 'This request is already decided.');
            }
            if ($row['assigned_reviewer_id'] !== null && (int) $row['assigned_reviewer_id'] !== $me && !Rbac::isSuperAdmin($me)) {
                throw new ApiException(409, 'ALREADY_CLAIMED', 'Another reviewer has this case.');
            }
            Database::exec(
                "UPDATE verification_requests SET status = 'in_review', assigned_reviewer_id = ?, assigned_at = NOW() WHERE id = ?",
                [$me, $req['id']]
            );
            $this->decisionLog((int) $req['id'], $me, 'claim', $row['status'], 'in_review', null, $r->ip);
        });
        return ['ok' => true, 'status' => 'in_review'];
    }

    /** Issues a link that expires in five minutes and is bound to this reviewer. */
    public function mediaLink(Request $r): array
    {
        $req = $this->loadRequest((int) $r->param('id'));
        $me = $r->userId();
        $this->assertAssigned($req, $me);
        $exp = time() + self::LINK_TTL;
        $sig = Crypto::hmac($req['id'] . '|' . $me . '|' . $exp, 'media-link');
        return ['url' => '/api/staff/verifications/' . $req['id'] . '/media?exp=' . $exp . '&sig=' . $sig, 'expires_at' => date('c', $exp)];
    }

    /** Streams the decrypted video. Requires an assigned reviewer, a valid signature and an unexpired link. */
    public function media(Request $r): ?array
    {
        $req = $this->loadRequest((int) $r->param('id'));
        $me = $r->userId();
        $this->assertAssigned($req, $me);
        $exp = (int) $r->query('exp', 0);
        $sig = (string) $r->query('sig', '');
        $expected = Crypto::hmac($req['id'] . '|' . $me . '|' . $exp, 'media-link');
        if ($exp < time() || !hash_equals($expected, $sig)) {
            throw new ApiException(403, 'LINK_EXPIRED', 'This media link has expired. Request a new one.');
        }
        if ($req['media_deleted_at'] !== null || $req['storage_key'] === null) {
            throw new ApiException(410, 'MEDIA_DELETED', 'This video has been deleted under the retention policy.');
        }
        $this->decisionLog((int) $req['id'], $me, 'view_media', null, null, null, $r->ip);
        Response::binary(SecureStorage::get('verification', $req['storage_key']), $req['video_mime'], 'inline', 'verification-' . $req['id'] . '.mp4');
        return null;
    }

    public function approve(Request $r): array
    {
        $req = $this->loadRequest((int) $r->param('id'));
        $me = $r->userId();
        $this->assertAssigned($req, $me);
        $retention = Settings::getInt('verification.retention_days', 30);
        Database::transaction(function () use ($req, $me, $retention): void {
            $row = Database::one('SELECT status FROM verification_requests WHERE id = ? FOR UPDATE', [$req['id']]);
            if ($row['status'] !== 'in_review') {
                throw new ApiException(409, 'INVALID_STATE', 'Claim the case before making a decision.');
            }
            Database::exec(
                "UPDATE verification_requests SET status = 'approved', decided_by = ?, decided_at = NOW(), decision_reason = NULL,
                 retention_until = NOW() + INTERVAL ? DAY WHERE id = ?",
                [$me, $retention, $req['id']]
            );
            Database::exec(
                "UPDATE profiles SET approval_status = 'approved', rejection_reason = NULL, approved_at = NOW(), approved_by = ?, reverification_required = 0
                 WHERE user_id = ?",
                [$me, $req['user_id']]
            );
            $this->decisionLog((int) $req['id'], $me, 'approve', 'in_review', 'approved', null, null);
        });
        Audit::log($me, 'verification.approved', 'verification_request', $req['id'], null, ['status' => 'in_review'], ['status' => 'approved']);
        Notifier::notify((int) $req['user_id'], 'verification', 'Your profile is approved', 'You can now explore matches.');
        return ['ok' => true, 'status' => 'approved'];
    }

    public function reject(Request $r): array
    {
        $req = $this->loadRequest((int) $r->param('id'));
        $me = $r->userId();
        $this->assertAssigned($req, $me);
        $reason = Validator::str($r->input('reason'), 'reason', 10, 500);
        $retention = Settings::getInt('verification.retention_days', 30);
        Database::transaction(function () use ($req, $me, $reason, $retention): void {
            $row = Database::one('SELECT status FROM verification_requests WHERE id = ? FOR UPDATE', [$req['id']]);
            if ($row['status'] !== 'in_review') {
                throw new ApiException(409, 'INVALID_STATE', 'Claim the case before making a decision.');
            }
            Database::exec(
                "UPDATE verification_requests SET status = 'rejected', decided_by = ?, decided_at = NOW(), decision_reason = ?,
                 retention_until = NOW() + INTERVAL ? DAY WHERE id = ?",
                [$me, $reason, $retention, $req['id']]
            );
            Database::exec(
                "UPDATE profiles SET approval_status = 'rejected', rejection_reason = ?, approved_at = NULL WHERE user_id = ?",
                [$reason, $req['user_id']]
            );
            $this->decisionLog((int) $req['id'], $me, 'reject', 'in_review', 'rejected', $reason, null);
        });
        Audit::log($me, 'verification.rejected', 'verification_request', $req['id'], $reason, ['status' => 'in_review'], ['status' => 'rejected']);
        Notifier::notify((int) $req['user_id'], 'verification', 'Verification needs attention', $reason . ' You can correct this and submit a new video.');
        return ['ok' => true, 'status' => 'rejected'];
    }

    /** Securely deletes verification media past its retention date. Run from bin/purge.php. */
    public static function purgeExpired(): int
    {
        $rows = Database::all(
            "SELECT id, storage_key FROM verification_requests
             WHERE storage_key IS NOT NULL AND media_deleted_at IS NULL AND retention_until IS NOT NULL AND retention_until < NOW()"
        );
        foreach ($rows as $row) {
            SecureStorage::secureDelete('verification', $row['storage_key']);
            Database::exec('UPDATE verification_requests SET storage_key = NULL, media_deleted_at = NOW() WHERE id = ?', [$row['id']]);
            Audit::log(null, 'verification.media_purged', 'verification_request', $row['id']);
        }
        return count($rows);
    }

    // --------------------------------------------------------------- helpers
    private function loadRequest(int $id): array
    {
        $row = Database::one('SELECT * FROM verification_requests WHERE id = ?', [$id]);
        if ($row === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Verification request not found.');
        }
        return $row;
    }

    private function assertAssigned(array $req, int $me): void
    {
        if (in_array($req['status'], ['approved', 'rejected', 'superseded'], true) && !Rbac::isSuperAdmin($me)) {
            throw new ApiException(409, 'INVALID_STATE', 'This case is already decided.');
        }
        if ((int) $req['assigned_reviewer_id'] !== $me && !Rbac::isSuperAdmin($me)) {
            throw new ApiException(403, 'NOT_ASSIGNED', 'Claim this case before accessing its media or deciding it.');
        }
    }

    private function decisionLog(int $requestId, int $reviewer, string $action, ?string $from, ?string $to, ?string $reason, ?string $ip): void
    {
        Database::exec(
            'INSERT INTO verification_decisions (verification_request_id, reviewer_id, action, from_status, to_status, reason, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$requestId, $reviewer, $action, $from, $to, $reason, $ip ?? ($_SERVER['REMOTE_ADDR'] ?? null)]
        );
    }
}
