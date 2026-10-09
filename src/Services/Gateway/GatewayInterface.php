<?php
/**
 * GatewayInterface — a payment gateway (Paystack, Flutterwave, mock).
 *
 * Implementations must:
 *   - never expose the secret key to the client (only public_key + mode are
 *     returned by PaymentService::publicConfig),
 *   - initialize a checkout server-side and return an authorization URL,
 *   - verify a transaction server-side (the redirect alone is never proof),
 *   - validate webhook signatures before any state change.
 */

declare(strict_types=1);

namespace CodeAlpha\Services\Gateway;

interface GatewayInterface
{
    public function name(): string;

    /**
     * Initialize a checkout for a pending transaction.
     *
     * @return array{authorization_url:string, gateway_reference:string}
     */
    public function initialize(array $transaction, array $plan, string $callbackUrl): array;

    /**
     * Verify a transaction server-side with the gateway.
     *
     * @return array{status:string, amount:float, currency:string, gateway_reference:string, raw:array}
     *         status is one of: success, failed, pending
     */
    public function verify(string $gatewayReference): array;

    /**
     * Validate an incoming webhook request.
     *
     * @param string $rawBody raw request body
     * @param array  $headers lowercase header map
     */
    public function validateWebhook(string $rawBody, array $headers): bool;

    /**
     * Decode a webhook payload into a normalized event.
     *
     * @return array{reference:string, gateway_reference:string, status:string, amount:float, currency:string, raw:array}|null
     */
    public function parseWebhook(string $rawBody): ?array;

    /**
     * Refund a transaction (full or partial).
     *
     * @return array{success:bool, gateway_reference:?string, raw:array}
     */
    public function refund(string $gatewayReference, float $amount, string $reason): array;
}
