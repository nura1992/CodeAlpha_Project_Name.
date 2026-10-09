<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers\Staff;

use CodeAlpha\Core\Auth;
use CodeAlpha\Core\AuditLog;
use CodeAlpha\Core\Database;
use CodeAlpha\Core\Response;
use CodeAlpha\Http\Controllers\Controller;
use CodeAlpha\Services\RbacService;

final class SuperStaffController extends Controller
{
    public function index(): never
    {
        $staff = Database::all("SELECT u.*, GROUP_CONCAT(r.slug) as roles FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id WHERE u.is_staff=1 AND u.deleted_at IS NULL GROUP BY u.id ORDER BY u.id DESC");
        $this->view('staff/super/staff-index', ['staff'=>$staff]);
    }
    public function create(): never { $this->view('staff/super/staff-form', ['roles'=>Database::all("SELECT * FROM roles ORDER BY name"), 'editing'=>null]); }
    public function store(): never
    {
        $data = $this->request->all();
        if (!filter_var($data['email']??'', FILTER_VALIDATE_EMAIL)) { flash('error','Valid email required'); Response::redirect('/staff/super/staff/create'); }
        if (Database::scalar('SELECT COUNT(*) FROM users WHERE email=:e', ['e'=>$data['email']])) { flash('error','Email taken'); Response::redirect('/staff/super/staff/create'); }
        $id = (int)Database::insert('users', ['uuid'=>uuid(),'email'=>$data['email'],'phone'=>null,'password_hash'=>Auth::hashPassword($data['password']??bin2hex(random_bytes(8))),'gender'=>null,'birthdate'=>null,'status'=>'active','is_staff'=>1,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
        AuditLog::record(Auth::id(),'staff.create','user',(string)$id,['email'=>$data['email']],$this->request);
        if (!empty($data['roles']) && is_array($data['roles'])) foreach ($data['roles'] as $rid) RbacService::assignRole($id,(int)$rid,Auth::id());
        Response::redirect('/staff/super/staff');
    }
    public function edit(array $p): never
    {
        $user = Database::one('SELECT * FROM users WHERE id=:id AND is_staff=1', ['id'=>$p['id']]);
        if (!$user) Response::notFound();
        $assigned = array_column(Database::all('SELECT role_id FROM user_roles WHERE user_id=:id',['id'=>$user['id']]), 'role_id');
        $this->view('staff/super/staff-form', ['editing'=>$user,'assigned'=>$assigned,'roles'=>Database::all("SELECT * FROM roles ORDER BY name")]);
    }
    public function update(array $p): never
    {
        $id=(int)$p['id'];
        $data=$this->request->all();
        $fields=[];
        if (!empty($data['email']) && filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $fields['email']=$data['email'];
        if (!empty($data['password'])) $fields['password_hash']=Auth::hashPassword($data['password']);
        if ($fields) { $fields['updated_at']=date('Y-m-d H:i:s'); Database::update('users',$fields,'id=:id',['id'=>$id]); }
        AuditLog::record(Auth::id(),'staff.update','user',(string)$id,$fields,$this->request);
        Response::redirect('/staff/super/staff');
    }
    public function suspend(array $p): never { Database::update('users',['status'=>'suspended','updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$p['id']]); AuditLog::record(Auth::id(),'staff.suspend','user',(string)$p['id'],[],$this->request); Response::redirect('/staff/super/staff'); }
    public function activate(array $p): never { Database::update('users',['status'=>'active','updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$p['id']]); AuditLog::record(Auth::id(),'staff.activate','user',(string)$p['id'],[],$this->request); Response::redirect('/staff/super/staff'); }
    public function delete(array $p): never { Database::update('users',['deleted_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$p['id']]); AuditLog::record(Auth::id(),'staff.delete','user',(string)$p['id'],[],$this->request); Response::redirect('/staff/super/staff'); }
    public function assignRoles(array $p): never
    {
        $id=(int)$p['id'];
        $roles = $this->request->array('roles') ?: $this->request->array('role_ids');
        // roles may be slugs or ids
        $roleIds = [];
        foreach ((array)$roles as $r) {
            if (is_numeric($r)) $roleIds[]=(int)$r;
            else { $row=Database::one('SELECT id FROM roles WHERE slug=:s',['s'=>$r]); if($row) $roleIds[]=(int)$row['id']; }
        }
        Database::delete('user_roles','user_id=:id',['id'=>$id]);
        foreach ($roleIds as $rid) RbacService::assignRole($id,$rid,Auth::id());
        AuditLog::record(Auth::id(),'staff.roles_assign','user',(string)$id,['roles'=>$roleIds],$this->request);
        Response::redirect('/staff/super/staff');
    }
}
