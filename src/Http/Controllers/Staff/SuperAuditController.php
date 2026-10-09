<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers\Staff;

use CodeAlpha\Core\Database;
use CodeAlpha\Http\Controllers\Controller;

final class SuperAuditController extends Controller
{
    public function index(): never
    {
        $page=max(1,$this->request->int('page',1));
        $q=trim((string)$this->request->query('q',''));
        $where='1=1'; $params=[];
        if($q!==''){ $where.=' AND (action LIKE :q OR target_type LIKE :q2)'; $params['q']='%'.$q.'%'; $params['q2']='%'.$q.'%'; }
        $logs=Database::all("SELECT l.*, u.email as actor_email FROM audit_logs l LEFT JOIN users u ON u.id=l.actor_id WHERE $where ORDER BY l.id DESC LIMIT 200", $params);
        $this->view('staff/super/audit', ['logs'=>$logs,'q'=>$q]);
    }
}
