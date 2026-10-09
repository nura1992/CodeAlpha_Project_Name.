<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers\Staff;

use CodeAlpha\Core\Response;
use CodeAlpha\Http\Controllers\Controller;
use CodeAlpha\Services\PaymentService;

final class FinanceReportController extends Controller
{
    public function index(): never
    {
        $from=(string)($this->request->query('from')??date('Y-m-01'));
        $to=(string)($this->request->query('to')??date('Y-m-d'));
        $this->view('staff/finance/reports', ['summary'=>PaymentService::reconciliationSummary($from,$to),'from'=>$from,'to'=>$to]);
    }
    public function export(): never
    {
        $from=(string)($this->request->query('from')??date('Y-m-01'));
        $to=(string)($this->request->query('to')??date('Y-m-d'));
        $txs=PaymentService::searchTransactions(['from'=>$from,'to'=>$to],10000);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="transactions_'.$from.'_'.$to.'.csv"');
        $out=fopen('php://output','w');
        fputcsv($out,['id','reference','gateway','amount','currency','status','user_email','created_at']);
        foreach($txs as $r){ fputcsv($out,[$r['id'],$r['reference'],$r['gateway'],$r['amount'],$r['currency'],$r['status'],$r['user_email']??'',$r['created_at']]); }
        fclose($out); exit;
    }
}
