<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers\Staff;

use CodeAlpha\Core\Auth;
use CodeAlpha\Core\Response;
use CodeAlpha\Http\Controllers\Controller;
use CodeAlpha\Services\PaymentService;

final class SuperGatewayController extends Controller
{
    public function index(): never
    {
        $this->view('staff/super/gateways', ['gateways'=>PaymentService::gateways()]);
    }
    public function update(): never
    {
        foreach (['paystack','flutterwave'] as $name) {
            if ($this->request->has($name.'_public_key') || $this->request->has($name.'_is_active')) {
                PaymentService::saveGateway($name, [
                    'public_key'=> (string)$this->request->input($name.'_public_key'),
                    'secret_key'=> (string)$this->request->input($name.'_secret_key'),
                    'webhook_secret'=> (string)$this->request->input($name.'_webhook_secret'),
                    'mode'=> (string)$this->request->input($name.'_mode','test'),
                    'is_active'=> $this->request->bool($name.'_is_active'),
                ], Auth::id());
            }
        }
        // Legacy flat fields
        $gateway = (string)$this->request->input('gateway');
        if ($gateway && in_array($gateway,['paystack','flutterwave'],true)) {
            PaymentService::saveGateway($gateway, [
                'public_key'=> (string)$this->request->input('public_key'),
                'secret_key'=> (string)$this->request->input('secret_key'),
                'webhook_secret'=> (string)$this->request->input('webhook_secret'),
                'mode'=> (string)$this->request->input('mode','test'),
                'is_active'=> $this->request->bool('is_active'),
            ], Auth::id());
        }
        flash('success','Gateway settings saved.');
        if($this->request->wantsJson()) Response::json(['saved'=>true]);
        Response::redirect('/staff/super/gateways');
    }
}
