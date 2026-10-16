<?php

namespace MedCore\Modules\Admin;

use MedCore\Core\Authorization;
use MedCore\Core\Request;
use MedCore\Core\Response;
use MedCore\Repositories\AuditRepository;

/**
 * Read-only audit log viewer (super admin / auditor).
 */
final class AuditController
{
    public function index(Request $request): void
    {
        Authorization::authorize('audits.view');

        $page = max(1, (int) $request->input('page', 1));
        $action = trim((string) $request->input('action', ''));
        $search = trim((string) $request->input('q', ''));
        $date = trim((string) $request->input('date', ''));

        Response::view('admin/audit/index', [
            'title'   => 'Audit log',
            'result'  => AuditRepository::paginate($page, 20, $action, $search, $date),
            'action'  => $action,
            'search'  => $search,
            'date'    => $date,
            'actions' => AuditRepository::distinctActions(),
        ]);
    }
}
