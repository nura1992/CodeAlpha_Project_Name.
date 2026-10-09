<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers\Staff;

use CodeAlpha\Core\Auth;
use CodeAlpha\Core\AuditLog;
use CodeAlpha\Core\Database;
use CodeAlpha\Core\Response;
use CodeAlpha\Http\Controllers\Controller;

final class AdminModerationController extends Controller
{
    public function index(): never
    {
        $status=$this->request->query('status')?:'open';
        $reports=Database::all("SELECT r.*, u.email as reporter_email, p.display_name as reporter_name FROM user_reports r JOIN users u ON u.id=r.reporter_id LEFT JOIN profiles p ON p.user_id=r.reporter_id WHERE r.status=:s ORDER BY r.created_at DESC LIMIT 100", ['s'=>$status]);
        $this->view('staff/admin/moderation-index', ['reports'=>$reports,'status'=>$status]);
    }
    public function resolve(array $p): never
    {
        Database::update('user_reports',['status'=>'resolved','handled_by'=>Auth::id(),'resolved_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$p['id']]);
        AuditLog::record(Auth::id(),'moderation.resolve','user_report',(string)$p['id'],[],$this->request);
        Response::redirect('/staff/admin/moderation');
    }
    public function dismiss(array $p): never
    {
        Database::update('user_reports',['status'=>'dismissed','handled_by'=>Auth::id(),'resolved_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$p['id']]);
        AuditLog::record(Auth::id(),'moderation.dismiss','user_report',(string)$p['id'],[],$this->request);
        Response::redirect('/staff/admin/moderation');
    }
    public function action(array $p): never
    {
        $action=(string)$this->request->input('action');
        $report=Database::one('SELECT * FROM user_reports WHERE id=:id',['id'=>$p['id']]);
        if(!$report) Response::notFound();
        if($action==='remove_post' && $report['post_id']) Database::update('community_posts',['status'=>'removed','updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$report['post_id']]);
        if($action==='remove_comment' && $report['comment_id']) Database::update('post_comments',['status'=>'removed','updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$report['comment_id']]);
        if($action==='suspend_user' && $report['reported_user_id']) Database::update('users',['status'=>'suspended','updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$report['reported_user_id']]);
        Database::update('user_reports',['status'=>'resolved','handled_by'=>Auth::id(),'resolution_notes'=>substr((string)$this->request->input('notes'),0,1000)?:null,'resolved_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$p['id']]);
        AuditLog::record(Auth::id(),'moderation.action','user_report',(string)$p['id'],['action'=>$action],$this->request);
        Response::redirect('/staff/admin/moderation');
    }
}
