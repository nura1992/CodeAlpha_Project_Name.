<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers;

use CodeAlpha\Services\MessagingService;

final class MessageController extends Controller
{
    public function apiConversations(): never
    {
        $user = $this->user();
        $this->ok(['conversations'=> MessagingService::conversationsFor((int)$user['id'])]);
    }
    public function apiMessages(array $params): never
    {
        $user = $this->user();
        $cid = (int)$params['id'];
        $since = $this->request->int('since_id', 0) ?: null;
        $msgs = MessagingService::messagesFor((int)$user['id'], $cid, $since);
        $this->ok(['messages'=>$msgs]);
    }
    public function apiSend(array $params): never
    {
        $user = $this->user();
        $cid = (int)$params['id'];
        $type = (string)($this->request->input('type') ?? 'text');
        $payload = ['type'=>$type];
        if ($type==='text') $payload['body'] = (string)($this->request->input('body') ?? $this->request->input('message') ?? '');
        else {
            $file = $this->request->file('media') ?? $this->request->file('file') ?? $this->request->file('image') ?? $this->request->file('voice');
            if ($file) { $payload['file']=$file; if ($this->request->has('duration')) $payload['duration']=$this->request->int('duration'); }
        }
        // Also handle multipart body with file
        if (!$this->request->file('media') && !$this->request->file('file') && $this->request->file('image')) $payload['file']=$this->request->file('image');
        try { $msg = MessagingService::send((int)$user['id'], $cid, $payload); } catch (\InvalidArgumentException $e) { $this->error($e->getMessage(),422); }
        $this->ok(['message'=>$msg],201);
    }
    public function apiRead(array $params): never
    {
        $user = $this->user();
        MessagingService::markRead((int)$user['id'], (int)$params['id']);
        $this->ok(['read'=>true]);
    }
    public function apiPoll(): never
    {
        $user = $this->user();
        $conversations = MessagingService::conversationsFor((int)$user['id']);
        $totalUnread = array_sum(array_column($conversations, 'unread_count'));
        $this->ok(['unread'=>$totalUnread, 'conversations'=>$conversations]);
    }
}
