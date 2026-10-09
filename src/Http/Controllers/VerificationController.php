<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers;

use CodeAlpha\Core\Auth;
use CodeAlpha\Core\Response;
use CodeAlpha\Services\VerificationService;

final class VerificationController extends Controller
{
    public function apiStatus(): never
    {
        $user = $this->user();
        $profile = \CodeAlpha\Core\Database::one('SELECT verification_status, verified_at FROM profiles WHERE user_id = :id', ['id'=>$user['id']]);
        $requests = \CodeAlpha\Core\Database::all('SELECT id, status, review_reason, submitted_at, reviewed_at FROM verification_requests WHERE user_id = :id ORDER BY id DESC LIMIT 10', ['id'=>$user['id']]);
        $this->ok([
            'verification_status' => $profile['verification_status'] ?? 'unverified',
            'verified_at' => $profile['verified_at'] ?? null,
            'requests' => $requests,
        ]);
    }

    public function apiSubmit(): never
    {
        $user = $this->user();
        $profile = \CodeAlpha\Core\Database::one('SELECT verification_status FROM profiles WHERE user_id = :id', ['id'=>$user['id']]);
        if ($profile && $profile['verification_status']==='pending') {
            $this->error('Your verification is already under review.',409,['code'=>'already_pending']);
        }
        $file = $this->request->file('video');
        if (!$file) $file = $this->request->file('verification_video');
        if (!$file) $this->error('Verification video is required.',422);
        try {
            $result = VerificationService::submit((int)$user['id'], $file);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(),422);
        }
        $this->ok($result,201);
    }
}
