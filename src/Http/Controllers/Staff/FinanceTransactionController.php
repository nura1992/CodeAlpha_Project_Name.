<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers\Staff;

use CodeAlpha\Core\Auth;
use CodeAlpha\Core\Response;
use CodeAlpha\Http\Controllers\Controller;
use CodeAlpha\Services\PaymentService;

final class FinanceTransactionController extends Controller
{
    public function index(): never
    {
        $filters=['q'=>$this->request->query('q'),'status'=>$this->request->query('status'),'gateway'=>$this->request->query('gateway')];
        $txs=PaymentService::searchTransactions(array_filter($filters));
        $this->view('staff/finance/transactions', ['transactions'=>$txs,'filters'=>$filters]);
    }
    public function show(array $p): never
    {
        $tx=PaymentService::transaction((int)$p['id']); if(!$tx) Response::notFound();
        $refunds=\CodeAlpha\Core\Database::all('SELECT * FROM refunds WHERE transaction_id=:id ORDER BY id DESC',['id'=>$tx['id']]);
        $this->view('staff/finance/transaction-show', ['tx'=>$tx,'refunds'=>$refunds]);
    }
    public function refund(array $p): never
    {
        $amount=(float)$this->request->input('amount');
        $reason=trim((string)$this->request->input('reason','Refund'));
        try { $res=PaymentService::refund((int)$p['id'],$amount,$reason,(int)Auth::id()); flash('success','Refund processed.'); } catch (\Throwable $e){ flash('error',$e->getMessage()); }
        Response::redirect('/staff/finance/transactions/'.$p['id']);
    }
}
