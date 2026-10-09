<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers;

use CodeAlpha\Core\Database;
use CodeAlpha\Services\NotificationService;

final class NotificationController extends Controller
{
    public function apiList(): never
    {
        $user = $this->user();
        $unread = $this->request->bool('unread');
        $page = $this->request->int('page',1);
        $list = NotificationService::list((int)$user['id'], 30, ($page-1)*30, $unread);
        foreach ($list as &$n) { $n['data']=json_decode((string)($n['data']??''), true); } unset($n);
        $this->ok(['notifications'=>$list, 'unread'=> NotificationService::unreadCount((int)$user['id'])]);
    }
    public function apiUnreadCount(): never
    {
        $user = $this->user();
        $this->ok(['unread'=> NotificationService::unreadCount((int)$user['id'])]);
    }
    public function apiRead(array $params): never
    {
        $user = $this->user();
        NotificationService::markRead((int)$user['id'], (int)$params['id']);
        $this->ok(['read'=>true]);
    }
    public function apiReadAll(): never
    {
        $user = $this->user();
        NotificationService::markAllRead((int)$user['id']);
        $this->ok(['read'=>true]);
    }
    public function apiPushSubscribe(): never
    {
        $user = $this->user();
        $endpoint = (string)($this->request->input('endpoint') ?? '');
        $keys = $this->request->input('keys');
        if (!$endpoint || !is_array($keys)) $this->error('endpoint and keys required',422);
        $exists = Database::one('SELECT id FROM push_subscriptions WHERE user_id=:uid AND endpoint=:ep', ['uid'=>$user['id'],'ep'=>$endpoint]);
        if (!$exists) Database::insert('push_subscriptions',['user_id'=>$user['id'],'endpoint'=>$endpoint,'keys'=>json_encode($keys),'user_agent'=>substr($this->request->userAgent(),0,255),'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
        $this->ok(['subscribed'=>true]);
    }
    public function apiPushUnsubscribe(): never
    {
        $user = $this->user();
        $endpoint = (string)($this->request->input('endpoint') ?? '');
        if ($endpoint) Database::delete('push_subscriptions','user_id=:uid AND endpoint=:ep',['uid'=>$user['id'],'ep'=>$endpoint]);
        else Database::delete('push_subscriptions','user_id=:uid',['uid'=>$user['id']]);
        $this->ok(['unsubscribed'=>true]);
    }
}
