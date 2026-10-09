<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers\Staff;

use CodeAlpha\Core\Auth;
use CodeAlpha\Core\AuditLog;
use CodeAlpha\Core\Database;
use CodeAlpha\Core\Response;
use CodeAlpha\Http\Controllers\Controller;
use CodeAlpha\Services\RbacService;

final class SuperRoleController extends Controller
{
    public function index(): never
    {
        $roles = Database::all('SELECT r.*, (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id=r.id) as members FROM roles r ORDER BY r.id ASC');
        $perms = RbacService::catalog();
        $this->view('staff/super/roles-index', ['roles'=>$roles,'catalog'=>$perms]);
    }
    public function create(): never { $this->view('staff/super/role-form', ['editing'=>null,'catalog'=>RbacService::catalog(),'assigned'=>[]]); }
    public function store(): never
    {
        $name=trim((string)$this->request->input('name')); $slug=strtolower(preg_replace('/[^a-z0-9_]+/','_', trim((string)($this->request->input('slug')?:$name))));
        if(!$name||!$slug){ flash('error','Name and slug required'); Response::redirect('/staff/super/roles/create'); }
        if(Database::scalar('SELECT COUNT(*) FROM roles WHERE slug=:s',['s'=>$slug])){ flash('error','Slug taken'); Response::redirect('/staff/super/roles/create');}
        $dashboard = $this->request->input('dashboard'); if(!in_array($dashboard,['super_admin','admin','finance','support',''],true)) $dashboard=null;
        $perms = (array)($this->request->input('permissions')??[]);
        $id=(int)Database::insert('roles',['name'=>$name,'slug'=>$slug,'dashboard'=>$dashboard?:null,'is_super'=>0,'description'=>substr((string)$this->request->input('description'),0,255)?:null,'is_system'=>0,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
        RbacService::setRolePermissions($id,$perms);
        AuditLog::record(Auth::id(),'role.create','role',(string)$id,['slug'=>$slug],$this->request);
        Response::redirect('/staff/super/roles');
    }
    public function edit(array $p): never
    {
        $role=Database::one('SELECT * FROM roles WHERE id=:id',['id'=>$p['id']]); if(!$role) Response::notFound();
        $assigned = array_column(Database::all('SELECT p.slug FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=:id',['id'=>$role['id']]),'slug');
        $this->view('staff/super/role-form', ['editing'=>$role,'catalog'=>RbacService::catalog(),'assigned'=>$assigned]);
    }
    public function update(array $p): never
    {
        $role=Database::one('SELECT * FROM roles WHERE id=:id',['id'=>$p['id']]); if(!$role) Response::notFound();
        if((int)$role['is_system']===1 && !RbacService::isSuperAdmin((int)Auth::id())){ flash('error','System role cannot be edited'); Response::redirect('/staff/super/roles');}
        $name=trim((string)$this->request->input('name')); $desc=substr((string)$this->request->input('description'),0,255);
        $dashboard=$this->request->input('dashboard'); if(!in_array($dashboard,['super_admin','admin','finance','support',''],true)) $dashboard=$role['dashboard'];
        $perms=(array)($this->request->input('permissions')??[]);
        Database::update('roles',['name'=>$name?:$role['name'],'dashboard'=>$dashboard?:$role['dashboard'],'description'=>$desc?:null,'updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$role['id']]);
        RbacService::setRolePermissions((int)$role['id'],$perms);
        AuditLog::record(Auth::id(),'role.update','role',(string)$role['id'],['name'=>$name],$this->request);
        Response::redirect('/staff/super/roles');
    }
    public function delete(array $p): never
    {
        $role=Database::one('SELECT * FROM roles WHERE id=:id',['id'=>$p['id']]); if(!$role) Response::notFound();
        if((int)$role['is_system']===1) { $this->error('System role cannot be deleted',403); }
        Database::delete('roles','id=:id',['id'=>$role['id']]);
        AuditLog::record(Auth::id(),'role.delete','role',(string)$role['id'],[],$this->request);
        if($this->request->wantsJson()) Response::json(['deleted'=>true]);
        Response::redirect('/staff/super/roles');
    }
}
