<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers;

use CodeAlpha\Core\Database;
use CodeAlpha\Services\MatchingService;

final class SafetyController extends Controller
{
    public function apiBlock(array $params): never
    {
        $user = $this->user();
        $targetId = (int)$params['id'];
        if (!MatchingService::block((int)$user['id'], $targetId)) $this->error('Cannot block this user',422);
        $this->ok(['blocked'=>true]);
    }
    public function apiUnblock(array $params): never
    {
        $user = $this->user();
        MatchingService::unblock((int)$user['id'], (int)$params['id']);
        $this->ok(['unblocked'=>true]);
    }
    public function apiReport(array $params): never
    {
        $user = $this->user();
        $targetId = (int)$params['id'];
        $type = (string)($this->request->input('type') ?? 'other');
        $reason = trim((string)($this->request->input('reason') ?? ''));
        if ($reason==='') $this->error('Reason required',422);
        $details = trim((string)($this->request->input('details') ?? ''));
        $id = (int)Database::insert('user_reports', [
            'reporter_id'=>(int)$user['id'],'reported_user_id'=>$targetId,
            'type'=>in_array($type,['spam','harassment','nudity','fake_profile','scam','underage','impersonation','hate_speech','other'],true)?$type:'other',
            'reason'=>substr($reason,0,255),'details'=>$details?substr($details,0,2000):null,'status'=>'open','created_at'=>date('Y-m-d H:i:s')
        ]);
        $this->ok(['report_id'=>$id],201);
    }
}
