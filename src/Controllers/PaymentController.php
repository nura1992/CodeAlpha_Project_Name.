<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Audit;
use App\Core\Database;
use App\Core\Env;
use App\Core\Eligibility;
use App\Core\Features;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Payments\Fulfilment;
use App\Payments\GatewayConfig;

/**
 * Member checkout. The redirect back from a gateway only starts verification; a transaction
 * becomes successful solely after a server-side gateway API check or a validated webhook.
 */
final class PaymentController
{
    private const GATEWAYS = ['paystack', 'flutterwave'];

    public function plans(Request $r): array
    {
        Features::require('business.plans');
        return ['items' => Database::all(
            'SELECT code, name, description, amount_minor, currency, billing_period, entitlements
             FROM subscription_plans WHERE is_active = 1 ORDER BY amount_minor ASC'
        )];
    }

    public function checkout(Request $r): array
    {
        Features::require('business.plans');
        $uid = $r->userId();
        $gateway = Validator::in($r->input('gateway'), 'gateway', self::GATEWAYS);
        Features::require('business.' . $gateway);
        $planCode = Validator::str($r->input('plan_code'), 'plan_code', 2, 40);
        $promo = $r->input('promo_code') ? strtoupper(Validator::str($r->input('promo_code'), 'promo_code', 3, 32, false) ?? '') : '';
        RateLimiter::hit('checkout:' . $uid, 10, 3600);

        $plan = Database::one('SELECT * FROM subscription_plans WHERE code = ? AND is_active = 1', [$planCode]);
        if ($plan === null) {
            throw new ApiException(404, 'NOT_FOUND', 'That plan is not available.');
        }
        $member = Database::one("SELECT u.email, u.phone, p.display_name FROM users u JOIN profiles p ON p.user_id = u.id WHERE u.id = ? AND u.account_type = 'member'", [$uid]);
        if ($member === null) {
            throw new ApiException(403, 'FORBIDDEN', 'Subscriptions are available to member accounts only.');
        }

        $amount = (int) $plan['amount_minor'];
        $campaignId = null;
        if ($promo !== '') {
            $campaign = Database::one(
                "SELECT id, discount_percent FROM campaigns WHERE promo_code = ? AND status = 'active'
                   AND (starts_at IS NULL OR starts_at <= NOW()) AND (ends_at IS NULL OR ends_at > NOW())",
                [$promo]
            );
            if ($campaign === null || empty($campaign['discount_percent'])) {
                throw new ApiException(422, 'INVALID_PROMO', 'That promo code is not valid right now.', ['field' => 'promo_code']);
            }
            $amount = intdiv($amount * (100 - (int) $campaign['discount_percent']), 100);
            $campaignId = (int) $campaign['id'];
        }

        $config = GatewayConfig::load($gateway);
        if (!$config['enabled'] || $config['secret'] === '') {
            throw new ApiException(503, 'GATEWAY_DISABLED', 'This payment method is currently unavailable.');
        }

        $reference = 'CA' . strtoupper(bin2hex(random_bytes(9)));
        $txId = Database::insert(
            "INSERT INTO transactions (reference, user_id, plan_id, campaign_id, gateway, gateway_reference, amount_minor, currency, status, metadata)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)",
            [$reference, $uid, $plan['id'], $campaignId, $gateway, $reference, $amount, $plan['currency'],
             json_encode(['plan' => $planCode, 'mode' => $config['mode']], JSON_THROW_ON_ERROR)]
        );
        Fulfilment::event($txId, 'checkout_created', 'system', $uid, null, 'pending', ['gateway' => $gateway]);

        try {
            $init = Fulfilment::gateway($gateway)->initialize(
                ['reference' => $reference, 'amount_minor' => $amount, 'currency' => $plan['currency']],
                ['email' => $member['email'] ?? '', 'phone' => $member['phone'] ?? '', 'display_name' => $member['display_name']],
                Env::appUrl() . '/billing/return?reference=' . $reference
            );
        } catch (\Throwable $e) {
            Fulfilment::event($txId, 'checkout_failed', 'system', $uid, 'pending', 'pending', ['error' => substr($e->getMessage(), 0, 200)]);
            throw $e instanceof ApiException ? $e : new ApiException(502, 'GATEWAY_ERROR', 'The payment provider could not be reached. Please try again.');
        }
        Database::exec('UPDATE transactions SET checkout_url = ? WHERE id = ?', [$init['checkout_url'], $txId]);
        Response::status(201);
        return ['reference' => $reference, 'checkout_url' => $init['checkout_url'], 'amount_minor' => $amount, 'currency' => $plan['currency'], 'status' => 'pending'];
    }

    /** Server-side verification of a pending transaction, triggered by the return page. */
    public function verify(Request $r): array
    {
        $uid = $r->userId();
        $reference = (string) $r->param('reference');
        $tx = Database::one('SELECT id, gateway, status, reference FROM transactions WHERE reference = ? AND user_id = ?', [$reference, $uid]);
        if ($tx === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Transaction not found.');
        }
        if ($tx['status'] === 'pending') {
            RateLimiter::hit('verify:tx:' . $uid, 30, 3600);
            $verified = Fulfilment::gateway($tx['gateway'])->verify($reference);
            Fulfilment::apply((int) $tx['id'], $verified, 'verify', $uid);
            $tx = Database::one('SELECT id, gateway, status, reference FROM transactions WHERE id = ?', [$tx['id']]);
        }
        return ['reference' => $reference, 'status' => $tx['status'], 'subscription' => $this->activeSubscription($uid)];
    }

    public function history(Request $r): array
    {
        $uid = $r->userId();
        $rows = Database::all(
            'SELECT t.reference, t.gateway, t.amount_minor, t.currency, t.status, t.created_at, t.verified_at, sp.name AS plan_name
             FROM transactions t LEFT JOIN subscription_plans sp ON sp.id = t.plan_id
             WHERE t.user_id = ? ORDER BY t.id DESC LIMIT 50',
            [$uid]
        );
        return ['items' => $rows, 'subscription' => $this->activeSubscription($uid)];
    }

    /**
     * Gateway webhook. Recorded and processed even when the payments module is disabled,
     * so that payments made before a shutdown are never lost.
     */
    public function webhook(Request $r): ?array
    {
        $gateway = (string) $r->param('gateway');
        if (!in_array($gateway, self::GATEWAYS, true)) {
            throw new ApiException(404, 'NOT_FOUND', 'Unknown gateway.');
        }
        $config = GatewayConfig::load($gateway);
        if ($config['secret'] === '') {
            throw new ApiException(503, 'GATEWAY_NOT_CONFIGURED', 'Gateway not configured.');
        }
        $gw = Fulfilment::gateway($gateway);
        $payload = json_decode($r->rawBody, true);
        if (!is_array($payload)) {
            throw new ApiException(400, 'INVALID_JSON', 'Malformed webhook payload.');
        }
        $parsed = $gw->parseWebhook($payload);
        $valid = $gw->verifyWebhookSignature($r->rawBody, $r->headers);
        $hash = hash('sha256', $r->rawBody);
        $minimal = ['event' => $parsed['event'], 'reference' => $parsed['reference'], 'gateway_transaction_id' => $parsed['gateway_transaction_id']];
        $inserted = Database::exec(
            'INSERT IGNORE INTO webhook_events (gateway, event_hash, event_type, signature_valid, payload) VALUES (?, ?, ?, ?, ?)',
            [$gateway, $hash, substr($parsed['event'], 0, 80), $valid ? 1 : 0, json_encode($minimal, JSON_THROW_ON_ERROR)]
        );
        if (!$valid) {
            Audit::log(null, 'webhook.invalid_signature', 'gateway', $gateway);
            throw new ApiException(401, 'INVALID_SIGNATURE', 'Signature verification failed.');
        }
        if ($inserted === 0) {
            return ['received' => true, 'duplicate' => true];
        }

        $success = in_array($parsed['event'], ['charge.success', 'charge.completed'], true);
        if ($success && $parsed['reference']) {
            $tx = Database::one('SELECT id, reference, status FROM transactions WHERE gateway = ? AND gateway_reference = ?', [$gateway, $parsed['reference']]);
            if ($tx !== null && $tx['status'] === 'pending') {
                // Never trust the webhook body alone: re-verify with the gateway API.
                $verified = $gw->verify($tx['reference']);
                Fulfilment::apply((int) $tx['id'], $verified, 'webhook');
            }
        }
        Database::exec('UPDATE webhook_events SET processed_at = NOW() WHERE gateway = ? AND event_hash = ?', [$gateway, $hash]);
        return ['received' => true];
    }

    private function activeSubscription(int $uid): ?array
    {
        return Database::one(
            "SELECT s.starts_at, s.ends_at, sp.name AS plan_name FROM subscriptions s JOIN subscription_plans sp ON sp.id = s.plan_id
             WHERE s.user_id = ? AND s.status = 'active' AND s.ends_at > NOW() ORDER BY s.ends_at DESC LIMIT 1",
            [$uid]
        );
    }
}
