<?php
/**
 * PaystackGateway — Paystack integration (NGN).
 *
 * Docs: https://paystack.com/docs/
 *   - Initialize: POST https://api.paystack.co/transaction/initialize
 *   - Verify:     GET  https://api.paystack.co/transaction/verify/{reference}
 *   - Webhook:    x-paystack-signature header = HMAC-SHA512(body, secretKey)
 *
 * The secret key never leaves the server. In mock mode (PAYMENT_MOCK=1) the
 * gateway simulates Paystack responses so the full flow can be tested
 * offline.
 */

declare(strict_types=1);

namespace CodeAlpha\Services\Gateway;

final class PaystackGateway implements GatewayInterface
{
    private string $secretKey;
    private string $publicKey;
    private string $mode;

    public function __construct(array $config)
    {
        $this->secretKey = (string) ($config['secret_key'] ?? '');
        $this->publicKey = (string) ($config['public_key'] ?? '');
        $this->mode = (string) ($config['mode'] ?? 'test');
    }

    public function name(): string
    {
        return 'paystack';
    }

    private function baseUrl(): string
    {
        return $this->mode === 'live' ? 'https://api.paystack.co' : 'https://api.paystack.co';
    }

    public function initialize(array $transaction, array $plan, string $callbackUrl): array
    {
        if ($this->isMock()) {
            return [
                'authorization_url' => url('/mock-checkout?ref=' . urlencode((string) $transaction['reference'])),
                'gateway_reference' => (string) $transaction['reference'],
            ];
        }
        $payload = [
            'email'        => $transaction['email'],
            'amount'       => (int) round(((float) $transaction['amount']) * 100), // kobo
            'currency'     => $transaction['currency'],
            'reference'    => $transaction['reference'],
            'callback_url' => $callbackUrl,
            'metadata'     => ['plan_id' => $transaction['plan_id'], 'user_id' => $transaction['user_id']],
        ];
        $response = $this->request('POST', '/transaction/initialize', $payload);
        if (empty($response['status']) || empty($response['data']['authorization_url'])) {
            throw new \RuntimeException('Paystack initialization failed: ' . ($response['message'] ?? 'unknown error'));
        }
        return [
            'authorization_url' => (string) $response['data']['authorization_url'],
            'gateway_reference' => (string) ($response['data']['reference'] ?? $transaction['reference']),
        ];
    }

    public function verify(string $gatewayReference): array
    {
        if ($this->isMock()) {
            // Mock verification reads the simulated outcome recorded by the
            // mock checkout page.
            $status = \CodeAlpha\Services\PaymentService::mockLookup($gatewayReference);
            return [
                'status'            => $status ?? 'pending',
                'amount'            => 0.0,
                'currency'          => 'NGN',
                'gateway_reference' => $gatewayReference,
                'raw'               => ['mock' => true],
            ];
        }
        $response = $this->request('GET', '/transaction/verify/' . rawurlencode($gatewayReference));
        $data = $response['data'] ?? [];
        $status = ($data['status'] ?? '') === 'success' ? 'success' : (($data['status'] ?? '') === 'pending' ? 'pending' : 'failed');
        return [
            'status'            => $status,
            'amount'            => ((int) ($data['amount'] ?? 0)) / 100,
            'currency'          => (string) ($data['currency'] ?? 'NGN'),
            'gateway_reference' => (string) ($data['reference'] ?? $gatewayReference),
            'raw'               => $response,
        ];
    }

    public function validateWebhook(string $rawBody, array $headers): bool
    {
        if ($this->isMock()) {
            return true; // Mock webhooks carry no signature.
        }
        $signature = (string) ($headers['x-paystack-signature'] ?? '');
        if ($signature === '' || $this->secretKey === '') {
            return false;
        }
        $expected = hash_hmac('sha512', $rawBody, $this->secretKey);
        return hash_equals($expected, $signature);
    }

    public function parseWebhook(string $rawBody): ?array
    {
        $event = json_decode($rawBody, true);
        if (!is_array($event) || ($event['event'] ?? '') !== 'charge.success') {
            return null;
        }
        $data = $event['data'] ?? [];
        return [
            'reference'         => (string) ($data['reference'] ?? ''),
            'gateway_reference' => (string) ($data['reference'] ?? ''),
            'status'            => 'success',
            'amount'            => ((int) ($data['amount'] ?? 0)) / 100,
            'currency'          => (string) ($data['currency'] ?? 'NGN'),
            'raw'               => $event,
        ];
    }

    public function refund(string $gatewayReference, float $amount, string $reason): array
    {
        if ($this->isMock()) {
            return ['success' => true, 'gateway_reference' => 'mock_refund_' . $gatewayReference, 'raw' => ['mock' => true]];
        }
        $response = $this->request('POST', '/refund', [
            'transaction' => $gatewayReference,
            'amount'      => (int) round($amount * 100),
            'customer_note' => $reason,
        ]);
        return [
            'success'           => !empty($response['status']),
            'gateway_reference' => (string) ($response['data']['id'] ?? ''),
            'raw'               => $response,
        ];
    }

    public function publicKey(): string
    {
        return $this->publicKey;
    }

    private function isMock(): bool
    {
        return (bool) config('payments.mock', false) || $this->secretKey === '';
    }

    /** Authenticated API call. */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        $url = $this->baseUrl() . $path;
        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $this->secretKey,
            'Content-Type: application/json',
            'Cache-Control: no-cache',
        ];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new \RuntimeException('Paystack request failed: ' . $error);
        }
        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Paystack returned an invalid response (HTTP ' . $status . ').');
        }
        return $decoded;
    }
}
