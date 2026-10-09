<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers\Staff;

use CodeAlpha\Core\Auth;
use CodeAlpha\Core\AuditLog;
use CodeAlpha\Core\Database;
use CodeAlpha\Core\Response;
use CodeAlpha\Core\Settings;
use CodeAlpha\Http\Controllers\Controller;

final class AdminCommunityController extends Controller
{
    public function index(): never
    {
        $posts=Database::all("SELECT p.*, pr.display_name FROM community_posts p LEFT JOIN profiles pr ON pr.user_id=p.user_id ORDER BY p.created_at DESC LIMIT 100");
        $this->view('staff/admin/community-index', ['posts'=>$posts,'guidelines'=>Settings::get('community_guidelines','')]);
    }
    public function removePost(array $p): never { Database::update('community_posts',['status'=>'removed','updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$p['id']]); AuditLog::record(Auth::id(),'community.remove_post','community_post',(string)$p['id'],[],$this->request); Response::redirect('/staff/admin/community'); }
    public function restorePost(array $p): never { Database::update('community_posts',['status'=>'published','updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$p['id']]); AuditLog::record(Auth::id(),'community.restore_post','community_post',(string)$p['id'],[],$this->request); Response::redirect('/staff/admin/community'); }
    public function pinPost(array $p): never { $row=Database::one('SELECT is_pinned FROM community_posts WHERE id=:id',['id'=>$p['id']]); Database::update('community_posts',['is_pinned'=> $row && (int)$row['is_pinned']?0:1],'id=:id',['id'=>$p['id']]); Response::redirect('/staff/admin/community'); }
    public function announcement(): never
    {
        $body=trim((string)$this->request->input('body')); if($body===''){ flash('error','Body required'); Response::redirect('/staff/admin/community'); }
        Database::insert('community_posts',['user_id'=>Auth::id(),'type'=>'text','body'=>$body,'visibility'=>'public','is_announcement'=>1,'is_pinned'=>0,'status'=>'published','created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
        AuditLog::record(Auth::id(),'community.announcement','community_post','new',[],$this->request);
        Response::redirect('/staff/admin/community');
    }
    public function guidelines(): never { Settings::set('community_guidelines', (string)$this->request->input('guidelines'), Auth::id()); AuditLog::record(Auth::id(),'community.guidelines','setting','community_guidelines',[],$this->request); flash('success','Guidelines saved.'); Response::redirect('/staff/admin/community'); }
}
