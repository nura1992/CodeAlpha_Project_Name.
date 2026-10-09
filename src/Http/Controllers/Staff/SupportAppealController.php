<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers\Staff;

use CodeAlpha\Core\Response;
use CodeAlpha\Http\Controllers\Controller;
use CodeAlpha\Services\SupportService;

final class SupportAppealController extends Controller
{
    public function index(): never { $this->view('staff/support/appeals-index', ['appeals'=>SupportService::appealsQueue()]); }
    public function resolve(array $p): never { SupportService::setTicketStatus((int)$p['id'], 'resolved', (int)\CodeAlpha\Core\Auth::id()); \CodeAlpha\Core\AuditLog::record(\CodeAlpha\Core\Auth::id(),'support.appeal_resolve','support_ticket',(string)$p['id'],[],$this->request); Response::redirect('/staff/support/appeals'); }
}
