<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers\Staff;

use CodeAlpha\Core\Auth;
use CodeAlpha\Core\AuditLog;
use CodeAlpha\Core\Database;
use CodeAlpha\Core\Response;
use CodeAlpha\Http\Controllers\Controller;

final class SupportReportController extends Controller
{
    public function index(): never
    {
        $reports=Database::all("SELECT r.*, u.email as reporter_email FROM user_reports r JOIN users u ON u.id=r.reporter_id WHERE r.status IN ('open','in_review') ORDER BY r.created_at DESC LIMIT 100");
        $this->view('staff/support/reports-index', ['reports'=>$reports]);
    }
    public function escalate(): never
    {
        $id=(int)$this->request->input('report_id');
        Database::update('user_reports',['status'=>'in_review'],'id=:id',['id'=>$id]);
        AuditLog::record(Auth::id(),'support.escalate','user_report',(string)$id,[],$this->request);
        // Notify moderators
        $mods=Database::all("SELECT DISTINCT ur.user_id FROM user_roles ur JOIN role_permissions rp ON rp.role_id=ur.role_id JOIN permissions p ON p.id=rp.permission_id WHERE p.slug='moderation.review'");
        foreach($mods as $m) \CodeAlpha\Services\NotificationService::notify((int)$m['user_id'],'moderation','Support escalated a report','',['report_id'=>$id]);
        Response::redirect('/staff/support/reports');
    }
}
