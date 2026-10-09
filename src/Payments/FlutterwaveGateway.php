<?php
declare(strict_types=1);

namespace App\Payments;

use App\Core\ApiException;
use App\Core\HttpClient;

final class FlutterwaveGateway implements PaymentGateway
{
    private const BASE = 'https://api.flutterwave.com/v3';

    public function __construct(private readonly array $config)
    {
    }

    public function code(): string
    {
        return 'flutterwave';
    }

    public function initialize(array $tx, array $user, string $callbackUrl): array
    {
        $res = HttpClient::request('POST', self::BASE . '/payments', $this->auth(), [
            'tx_ref' => $tx['reference'],
            'amount' => round(((int) $tx['amount_minor']) / 100, 2), // Flutterwave takes major units
            'currency' => $tx['currency'],
            'redirect_url' => $callbackUrl,
            'customer' => ['email' => $user['email'] ?? '', 'name' => $user['display_name'] ?? 'Member', 'phonenumber' => $user['phone'] ?? ''],
            'customizations' => ['title' => 'Subscription', 'description' => 'Platform subscription'],
            'meta' => ['transaction_reference' => $tx['reference']],
        ]);
        if ($res['status'] >= 300 || ($res['json']['status'] ?? '') !== 'success' || empty($res['json']['data']['link'])) {
            throw new ApiException(502, 'GATEWAY_ERROR', 'Flutterwave could not start the checkout. Please try again.');
        }
        return ['checkout_url' => (string) $res['json']['data']['link']];
    }

    public function verify(string $reference): array
    {
        $res = HttpClient::request('GET', self::BASE . '/transactions/verify_by_reference?tx_ref=' . rawurlencode($reference), $this->auth());
        $d = $res['json']['data'] ?? [];
        return [
            'ok' => $res['status'] < 300 && ($res['json']['status'] ?? '') === 'success',
            'status' => (string) ($d['status'] ?? 'unknown'),
            'reference' => $d['tx_ref'] ?? null,
            'amount_minor' => (int) round(((float) ($d['amount'] ?? 0)) * 100),
            'currency' => (string) ($d['currency'] ?? ''),
            'gateway_transaction_id' => isset($d['id']) ? (string) $d['id'] : null,
            'raw' => ['status' => $d['status'] ?? null, 'id' => $d['id'] ?? null],
        ];
    }

    public function refund(array $tx, int $amountMinor, string $reason): array
    {
        if (empty($tx['gateway_transaction_id'])) {
            return ['ok' => false, 'reference' => null, 'raw' => ['error' => 'missing gateway transaction id']];
        }
        $res = HttpClient::request('POST', self::BASE . '/transactions/' . rawurlencode((string) $tx['gateway_transaction_id']) . '/refund', $this->auth(), [
            'amount' => round($amountMinor / 100, 2),
        ]);
        return [
            'ok' => $res['status'] < 300 && ($res['json']['status'] ?? '') === 'success',
            'reference' => isset($res['json']['data']['id']) ? (string) $res['json']['data']['id'] : null,
            'raw' => ['status' => $res['json']['status'] ?? null, 'message' => $res['json']['message'] ?? null],
        ];
    }

    /** Flutterwave sends the secret hash configured in the dashboard in the verif-hash header. */
    public function verifyWebhookSignature(string $rawBody, array $headers): bool
    {
        $sent = (string) ($headers['verif-hash'] ?? '');
        $expected = (string) $this->config['webhook_secret'];
        return $sent !== '' && $expected !== '' && hash_equals($expected, $sent);
    }

    public function parseWebhook(array $payload): array
    {
        return [
            'event' => (string) ($payload['event'] ?? ''),
            'reference' => $payload['data']['tx_ref'] ?? null,
            'gateway_transaction_id' => isset($payload['data']['id']) ? (string) $payload['data']['id'] : null,
        ];
    }

    private function auth(): array
    {
        return ['Authorization: Bearer ' . $this->config['secret']];
    }
}
