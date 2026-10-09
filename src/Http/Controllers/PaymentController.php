<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers;

use CodeAlpha\Core\Database;
use CodeAlpha\Services\PaymentService;

final class PaymentController extends Controller
{
    public function apiPlans(): never
    {
        $this->ok(['plans'=> PaymentService::plans(), 'gateways'=> PaymentService::publicConfig()]);
    }
    public function apiConfig(): never
    {
        $this->ok(['gateways'=> PaymentService::publicConfig()]);
    }
    public function apiInitialize(): never
    {
        $user = $this->user();
        $planId = (int)($this->request->input('plan_id') ?? 0);
        $gateway = (string)($this->request->input('gateway') ?? '');
        $campaignId = $this->request->int('campaign_id') ?: null;
        if (!$planId || !$gateway) $this->error('plan_id and gateway required',422);
        try { $res = PaymentService::initializePayment((int)$user['id'], $planId, $gateway, $campaignId); } catch (\InvalidArgumentException $e) { $this->error($e->getMessage(),422); }
        $this->ok($res,201);
    }
    public function apiVerify(): never
    {
        $ref = (string)($this->request->query('reference') ?? $this->request->input('reference') ?? '');
        if (!$ref) $this->error('reference required',422);
        try { $res = PaymentService::verifyAndActivate($ref); } catch (\InvalidArgumentException $e) { $this->error($e->getMessage(),404); }
        $this->ok($res);
    }
    public function apiHistory(): never
    {
        $user = $this->user();
        $this->ok(['transactions'=> PaymentService::history((int)$user['id'])]);
    }
    public function apiSubscription(): never
    {
        $user = $this->user();
        $sub = PaymentService::activeSubscription((int)$user['id']);
        $this->ok(['subscription'=>$sub, 'history'=> PaymentService::subscriptionHistory((int)$user['id'])]);
    }
    public function apiCancelSubscription(): never
    {
        $user = $this->user();
        $ok = PaymentService::cancelSubscription((int)$user['id']);
        if (!$ok) $this->error('No active subscription',404);
        $this->ok(['cancelled'=>true]);
    }
    public function mockCheckout(): never
    {
        $ref = (string)($this->request->query('ref') ?? '');
        $txn = $ref ? Database::one('SELECT * FROM transactions WHERE reference=:r', ['r'=>$ref]) : null;
        if (!$txn) $this->view('pages/mock-checkout-error', ['message'=>'Transaction not found'], 'guest');
        $this->view('pages/mock-checkout', ['transaction'=>$txn], 'guest');
    }
}
