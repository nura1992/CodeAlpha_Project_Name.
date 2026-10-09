<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers;

use CodeAlpha\Services\CallService;

final class CallController extends Controller
{
    public function apiHistory(): never
    {
        $user = $this->user();
        $filters = ['status'=>$this->request->query('status'), 'direction'=>$this->request->query('direction'), 'type'=>$this->request->query('type')];
        $this->ok(['calls'=> CallService::history((int)$user['id'], array_filter($filters))]);
    }
    public function apiInitiate(): never
    {
        $user = $this->user();
        $callee = (int)($this->request->input('callee_id') ?? $this->request->input('user_id'));
        $type = (string)($this->request->input('type') ?? 'audio');
        if (!$callee) $this->error('callee_id required',422);
        try { $call = CallService::initiate((int)$user['id'], $callee, $type==='video'?'video':'audio'); } catch (\InvalidArgumentException $e) { $this->error($e->getMessage(),422); }
        $this->ok(['call'=>$call],201);
    }
    public function apiAccept(array $params): never
    {
        $user = $this->user();
        try { $call = CallService::accept((int)$user['id'], (int)$params['id']); } catch (\InvalidArgumentException $e) { $this->error($e->getMessage(),422); }
        $this->ok(['call'=>$call]);
    }
    public function apiDecline(array $params): never
    {
        $user = $this->user();
        try { $call = CallService::decline((int)$user['id'], (int)$params['id']); } catch (\InvalidArgumentException $e) { $this->error($e->getMessage(),422); }
        $this->ok(['call'=>$call]);
    }
    public function apiEnd(array $params): never
    {
        $user = $this->user();
        try { $call = CallService::end((int)$user['id'], (int)$params['id']); } catch (\InvalidArgumentException $e) { $this->error($e->getMessage(),422); }
        $this->ok(['call'=>$call]);
    }
    public function apiSignal(array $params): never
    {
        $user = $this->user();
        $kind = (string)($this->request->input('kind') ?? $this->request->input('type') ?? 'offer');
        $payload = $this->request->input('payload');
        if (is_string($payload)) $payload = json_decode($payload, true);
        CallService::signal((int)$user['id'], (int)$params['id'], $kind, is_array($payload)?$payload:null);
        $this->ok(['sent'=>true]);
    }
    public function apiSignals(array $params): never
    {
        $user = $this->user();
        $since = $this->request->int('since',0);
        $signals = CallService::signalsSince((int)$user['id'], (int)$params['id'], $since);
        $this->ok(['signals'=>$signals]);
    }
    public function apiSignalToken(array $params): never
    {
        $user = $this->user();
        $token = CallService::signalToken((int)$user['id'], (int)$params['id']);
        $this->ok(['token'=>$token, 'signaling_url'=> (string)config('calling.signaling_url')]);
    }
    public function apiBlock(): never
    {
        $user = $this->user();
        $target = (int)($this->request->input('user_id') ?? 0);
        if (!$target) $this->error('user_id required',422);
        CallService::blockCalls((int)$user['id'], $target);
        $this->ok(['blocked'=>true]);
    }
    public function apiUnblock(array $params): never
    {
        $user = $this->user();
        CallService::unblockCalls((int)$user['id'], (int)$params['user_id']);
        $this->ok(['unblocked'=>true]);
    }
    public function apiBlocked(): never
    {
        $user = $this->user();
        $this->ok(['blocked'=> CallService::blockedList((int)$user['id'])]);
    }
}
