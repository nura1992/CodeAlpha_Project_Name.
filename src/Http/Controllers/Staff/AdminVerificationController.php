<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers\Staff;

use CodeAlpha\Core\Auth;
use CodeAlpha\Core\Response;
use CodeAlpha\Http\Controllers\Controller;
use CodeAlpha\Services\VerificationService;

final class AdminVerificationController extends Controller
{
    public function index(): never
    {
        $status=$this->request->query('status');
        $this->view('staff/admin/verification-index', ['queue'=>VerificationService::queue($status? (string)$status: null),'status'=>$status]);
    }
    public function show(array $p): never
    {
        $req=VerificationService::find((int)$p['id']); if(!$req) Response::notFound();
        $this->view('staff/admin/verification-show', ['req'=>$req]);
    }
    public function approve(array $p): never
    {
        try { VerificationService::approve((int)$p['id'], (int)Auth::id()); flash('success','Verification approved.'); } catch (\Throwable $e){ flash('error',$e->getMessage()); }
        Response::redirect('/staff/admin/verification/'. $p['id']);
    }
    public function reject(array $p): never
    {
        $reason=trim((string)$this->request->input('reason',''));
        try { VerificationService::reject((int)$p['id'], (int)Auth::id(), $reason); flash('success','Verification rejected.'); } catch (\Throwable $e){ flash('error',$e->getMessage()); }
        Response::redirect('/staff/admin/verification/'. $p['id']);
    }
}
