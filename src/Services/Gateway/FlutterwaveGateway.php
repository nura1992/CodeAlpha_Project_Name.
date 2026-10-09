<?php
/**
 * FlutterwaveGateway — Flutterwave integration (NGN and multi-currency).
 *
 * Docs: https://developer.flutterwave.com/docs
 *   - Initialize: POST https://api.flutterwave.com/v3/payments
 *   - Verify:     GET  https://api.flutterwave.com/v3/transactions/{id}/verify
 *   - Webhook:    'verif-hash' header must equal the configured secret hash
 *
 * The secret key never leaves the server. In mock mode (PAYMENT_MOCK=1) the
 * gateway simulates Flutterwave responses so the full flow can be tested
 * offline.
 */

declare(strict_types=1);

namespace CodeAlpha\Services\Gateway;

final class FlutterwaveGateway implements GatewayInterface
{
    private string $secretKey;
    private string $publicKey;
    private string $secretHash;
    private string $mode;

    public function __construct(array $config)
    {
        $this->secretKey = (string) ($config['secret_key'] ?? '');
        $this->publicKey = (string) ($config['public_key'] ?? '');
        $this->secretHash = (string) ($config['webhook_secret'] ?? '');
        $this->mode = (string) ($config['mode'] ?? 'test');
    }

    public function name(): string
    {
        return 'flutterwave';
    }

    private function baseUrl(): string
    {
        return $this->mode === 'live'
            ? 'https://api.flutterwave.com/v3'
            : 'https://api.flutterwave.com/v3';
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
            'tx_ref'          => $transaction['reference'],
            'amount'          => (float) $transaction['amount'],
            'currency'        => $transaction['currency'],
            'redirect_url'    => $callbackUrl,
            'customer'        => ['email' => $transaction['email'], 'user_id' => $transaction['user_id']],
            'meta'            => ['plan_id' => $transaction['plan_id'], 'user_id' => $transaction['user_id']],
            'payment_options' => 'card',
        ];
        $response = $this->request('POST', '/payments', $payload);
        if (($response['status'] ?? '') !== 'success' || empty($response['data']['link'])) {
            throw new \RuntimeException('Flutterwave initialization failed: ' . ($response['message'] ?? 'unknown error'));
        }
        return [
            'authorization_url' => (string) $response['data']['link'],
            'gateway_reference' => (string) $transaction['reference'],
        ];
    }

    public function verify(string $gatewayReference): array
    {
        if ($this->isMock()) {
            $status = \CodeAlpha\Services\PaymentService::mockLookup($gatewayReference);
            return [
                'status'            => $status ?? 'pending',
                'amount'            => 0.0,
                'currency'          => 'NGN',
                'gateway_reference' => $gatewayReference,
                'raw'               => ['mock' => true],
            ];
        }
        // Flutterwave verifies by tx_ref via the transactions/verify endpoint.
        $response = $this->request('GET', '/transactions/verify_by_reference?tx_ref=' . rawurlencode($gatewayReference));
        $data = $response['data'] ?? [];
        $status = ($data['status'] ?? '') === 'successful' ? 'success' : (($data['status'] ?? '') === 'pending' ? 'pending' : 'failed');
        return [
            'status'            => $status,
            'amount'            => (float) ($data['amount'] ?? 0),
            'currency'          => (string) ($data['currency'] ?? 'NGN'),
            'gateway_reference' => (string) ($data['tx_ref'] ?? $gatewayReference),
            'raw'               => $response,
        ];
    }

    public function validateWebhook(string $rawBody, array $headers): bool
    {
        if ($this->isMock()) {
            return true;
        }
        // Flutterwave sends the configured secret hash in the verif-hash header.
        $hash = (string) ($headers['verif-hash'] ?? '');
        if ($hash === '' || $this->secretHash === '') {
            return false;
        }
        return hash_equals($this->secretHash, $hash);
    }

    public function parseWebhook(string $rawBody): ?array
    {
        $event = json_decode($rawBody, true);
        if (!is_array($event)) {
            return null;
        }
        $data = $event['data'] ?? [];
        $status = ($data['status'] ?? '') === 'successful' ? 'success' : 'failed';
        if (($data['status'] ?? '') !== 'successful') {
            return null; // Only successful charges activate subscriptions.
        }
        return [
            'reference'         => (string) ($data['tx_ref'] ?? ''),
            'gateway_reference' => (string) ($data['tx_ref'] ?? ''),
            'status'            => $status,
            'amount'            => (float) ($data['amount'] ?? 0),
            'currency'          => (string) ($data['currency'] ?? 'NGN'),
            'raw'               => $event,
        ];
    }

    public function refund(string $gatewayReference, float $amount, string $reason): array
    {
        if ($this->isMock()) {
            return ['success' => true, 'gateway_reference' => 'mock_refund_' . $gatewayReference, 'raw' => ['mock' => true]];
        }
        // Look up the transaction id first, then refund.
        $verify = $this->request('GET', '/transactions/verify_by_reference?tx_ref=' . rawurlencode($gatewayReference));
        $txId = (string) ($verify['data']['id'] ?? '');
        if ($txId === '') {
            return ['success' => false, 'gateway_reference' => null, 'raw' => $verify];
        }
        $response = $this->request('POST', '/transactions/' . $txId . '/refunds', ['amount' => $amount]);
        return [
            'success'           => ($response['status'] ?? '') === 'success',
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

    private function request(string $method, string $path, ?array $payload = null): array
    {
        $ch = curl_init($this->baseUrl() . $path);
        $headers = [
            'Authorization: Bearer ' . $this->secretKey,
            'Content-Type: application/json',
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
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new \RuntimeException('Flutterwave request failed: ' . $error);
        }
        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Flutterwave returned an invalid response.');
        }
        return $decoded;
    }
}
