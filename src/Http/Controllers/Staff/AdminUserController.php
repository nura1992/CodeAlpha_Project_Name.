<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers\Staff;

use CodeAlpha\Core\Auth;
use CodeAlpha\Core\AuditLog;
use CodeAlpha\Core\Database;
use CodeAlpha\Core\Response;
use CodeAlpha\Http\Controllers\Controller;

final class AdminUserController extends Controller
{
    public function index(): never
    {
        $q=trim((string)$this->request->query('q',''));
        $where='u.deleted_at IS NULL'; $params=[];
        if($q!==''){ $where.=' AND (u.email LIKE :q OR p.display_name LIKE :q2)'; $params['q']='%'.$q.'%'; $params['q2']='%'.$q.'%'; }
        $users=Database::all("SELECT u.*, p.display_name, p.verification_status FROM users u LEFT JOIN profiles p ON p.user_id=u.id WHERE $where ORDER BY u.id DESC LIMIT 100", $params);
        $this->view('staff/admin/users-index', ['users'=>$users,'q'=>$q]);
    }
    public function show(array $p): never
    {
        $user=Database::one('SELECT u.*, p.display_name, p.bio, p.verification_status, p.discoverable FROM users u LEFT JOIN profiles p ON p.user_id=u.id WHERE u.id=:id', ['id'=>$p['id']]);
        if(!$user) Response::notFound();
        $photos=Database::all('SELECT * FROM profile_photos WHERE user_id=:id ORDER BY position ASC', ['id'=>$user['id']]);
        $this->view('staff/admin/user-show', ['user'=>$user,'photos'=>$photos]);
    }
    public function suspend(array $p): never
    {
        Database::update('users',['status'=>'suspended','updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$p['id']]);
        AuditLog::record(Auth::id(),'user.suspend','user',(string)$p['id'],[],$this->request);
        Response::redirect('/staff/admin/users/'.$p['id']);
    }
    public function activate(array $p): never
    {
        Database::update('users',['status'=>'active','updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$p['id']]);
        AuditLog::record(Auth::id(),'user.activate','user',(string)$p['id'],[],$this->request);
        Response::redirect('/staff/admin/users/'.$p['id']);
    }
}
