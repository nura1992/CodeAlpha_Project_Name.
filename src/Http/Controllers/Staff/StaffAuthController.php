<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers\Staff;

use CodeAlpha\Core\Auth;
use CodeAlpha\Core\AuditLog;
use CodeAlpha\Core\Database;
use CodeAlpha\Core\Response;
use CodeAlpha\Http\Controllers\Controller;
use CodeAlpha\Services\RbacService;

final class StaffAuthController extends Controller
{
    public function showLogin(): never
    {
        if (Auth::check() && Auth::isStaff()) {
            Response::redirect('/staff');
        }
        $this->view('staff/login', [], 'guest');
    }
    public function login(): never
    {
        $id = $this->request->string('identifier');
        $pw = (string)$this->request->input('password');
        $user = Auth::attempt($id, $pw, $this->request);
        if (!$user || (int)$user['is_staff']!==1) {
            flash('error','Invalid staff credentials.');
            Response::redirect('/staff/login');
        }
        if ($user['status']==='suspended') { flash('error','Account suspended.'); Response::redirect('/staff/login'); }
        Auth::login($user);
        AuditLog::record((int)$user['id'],'staff.login','user',(string)$user['id'],[],$this->request);
        $redirect = $this->request->query('redirect');
        if (is_string($redirect) && str_starts_with($redirect,'/staff')) Response::redirect($redirect);
        Response::redirect('/staff');
    }
    public function logout(): never
    {
        AuditLog::record(Auth::id(),'staff.logout',null,null,[],$this->request);
        Auth::logout();
        Response::redirect('/staff/login');
    }
    public function home(): never
    {
        $user = Auth::user();
        if (!$user) Response::redirect('/staff/login');
        // Route to highest dashboard they have
        if (RbacService::isSuperAdmin((int)$user['id'])) Response::redirect('/staff/super');
        $dash = RbacService::dashboardsFor((int)$user['id']);
        $map = ['super_admin'=>'/staff/super','admin'=>'/staff/admin','finance'=>'/staff/finance','support'=>'/staff/support'];
        foreach (['admin','finance','support'] as $d) { if (in_array($d,$dash,true)) Response::redirect($map[$d]); }
        $this->view('staff/home', ['user'=>$user]);
    }
}
