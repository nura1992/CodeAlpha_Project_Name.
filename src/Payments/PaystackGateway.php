<?php
declare(strict_types=1);

namespace App\Payments;

use App\Core\ApiException;
use App\Core\HttpClient;

final class PaystackGateway implements PaymentGateway
{
    private const BASE = 'https://api.paystack.co';

    public function __construct(private readonly array $config)
    {
    }

    public function code(): string
    {
        return 'paystack';
    }

    public function initialize(array $tx, array $user, string $callbackUrl): array
    {
        $res = HttpClient::request('POST', self::BASE . '/transaction/initialize', $this->auth(), [
            'email' => $user['email'],
            'amount' => (int) $tx['amount_minor'],
            'currency' => $tx['currency'],
            'reference' => $tx['reference'],
            'callback_url' => $callbackUrl,
            'metadata' => ['transaction_reference' => $tx['reference']],
        ]);
        if ($res['status'] >= 300 || empty($res['json']['status']) || empty($res['json']['data']['authorization_url'])) {
            throw new ApiException(502, 'GATEWAY_ERROR', 'Paystack could not start the checkout. Please try again.');
        }
        return ['checkout_url' => (string) $res['json']['data']['authorization_url']];
    }

    public function verify(string $reference): array
    {
        $res = HttpClient::request('GET', self::BASE . '/transaction/verify/' . rawurlencode($reference), $this->auth());
        $d = $res['json']['data'] ?? [];
        return [
            'ok' => $res['status'] < 300 && !empty($res['json']['status']),
            'status' => (string) ($d['status'] ?? 'unknown'),
            'reference' => $d['reference'] ?? null,
            'amount_minor' => (int) ($d['amount'] ?? 0), // Paystack amounts are already in kobo
            'currency' => (string) ($d['currency'] ?? ''),
            'gateway_transaction_id' => isset($d['id']) ? (string) $d['id'] : null,
            'raw' => ['status' => $d['status'] ?? null, 'id' => $d['id'] ?? null],
        ];
    }

    public function refund(array $tx, int $amountMinor, string $reason): array
    {
        $res = HttpClient::request('POST', self::BASE . '/refund', $this->auth(), [
            'transaction' => $tx['gateway_reference'],
            'amount' => $amountMinor,
        ]);
        return [
            'ok' => $res['status'] < 300 && !empty($res['json']['status']),
            'reference' => isset($res['json']['data']['id']) ? (string) $res['json']['data']['id'] : null,
            'raw' => ['status' => $res['json']['status'] ?? null, 'message' => $res['json']['message'] ?? null],
        ];
    }

    /** Paystack signs the raw body with HMAC-SHA512 using the secret key. */
    public function verifyWebhookSignature(string $rawBody, array $headers): bool
    {
        $sent = strtolower((string) ($headers['x-paystack-signature'] ?? ''));
        return $sent !== '' && hash_equals(hash_hmac('sha512', $rawBody, $this->config['secret']), $sent);
    }

    public function parseWebhook(array $payload): array
    {
        return [
            'event' => (string) ($payload['event'] ?? ''),
            'reference' => $payload['data']['reference'] ?? null,
            'gateway_transaction_id' => isset($payload['data']['id']) ? (string) $payload['data']['id'] : null,
        ];
    }

    private function auth(): array
    {
        return ['Authorization: Bearer ' . $this->config['secret']];
    }
}
