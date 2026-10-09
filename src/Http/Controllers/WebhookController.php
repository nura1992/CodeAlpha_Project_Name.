<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers;

use CodeAlpha\Core\Database;
use CodeAlpha\Core\Response;
use CodeAlpha\Services\PaymentService;

final class WebhookController extends Controller
{
    public function paystack(): never
    {
        $raw = file_get_contents('php://input') ?: '';
        $headers = array_change_key_case(getallheaders()?:[], CASE_LOWER);
        $gw = PaymentService::gatewayInstance('paystack');
        if (!$gw || !$gw->validateWebhook($raw, $headers)) {
            Response::json(['error'=>'invalid signature'],401);
        }
        $event = $gw->parseWebhook($raw);
        if ($event) PaymentService::handleWebhookEvent($event);
        // Mock helper: if mock mode, also accept simple JSON {reference,status}
        if (config('payments.mock', false)) {
            $j = json_decode($raw, true);
            if (is_array($j) && isset($j['reference'])) PaymentService::handleWebhookEvent(['reference'=>$j['reference'],'status'=>$j['status']??'success','amount'=>0,'currency'=>'NGN','raw'=>$j]);
        }
        Response::json(['received'=>true]);
    }
    public function flutterwave(): never
    {
        $raw = file_get_contents('php://input') ?: '';
        $headers = array_change_key_case(getallheaders()?:[], CASE_LOWER);
        $gw = PaymentService::gatewayInstance('flutterwave');
        if (!$gw || !$gw->validateWebhook($raw, $headers)) {
            Response::json(['error'=>'invalid signature'],401);
        }
        $event = $gw->parseWebhook($raw);
        if ($event) PaymentService::handleWebhookEvent($event);
        if (config('payments.mock', false)) {
            $j = json_decode($raw, true);
            if (is_array($j) && isset($j['reference'])) PaymentService::handleWebhookEvent(['reference'=>$j['reference'],'status'=>$j['status']??'success','amount'=>0,'currency'=>'NGN','raw'=>$j]);
        }
        Response::json(['received'=>true]);
    }
}
