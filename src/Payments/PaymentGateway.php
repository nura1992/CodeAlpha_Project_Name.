<?php
declare(strict_types=1);

namespace App\Payments;

/** Contract every payment gateway adapter implements. Amounts are always in minor units (kobo). */
interface PaymentGateway
{
    public function code(): string;

    /** @return array{checkout_url:string} */
    public function initialize(array $tx, array $user, string $callbackUrl): array;

    /**
     * Verifies a transaction directly with the gateway API (never trusts the browser redirect).
     * @return array{ok:bool,status:string,reference:?string,amount_minor:int,currency:string,gateway_transaction_id:?string,raw:array}
     */
    public function verify(string $reference): array;

    /** @return array{ok:bool,reference:?string,raw:array} */
    public function refund(array $tx, int $amountMinor, string $reason): array;

    public function verifyWebhookSignature(string $rawBody, array $headers): bool;

    /** @return array{event:string,reference:?string,gateway_transaction_id:?string} */
    public function parseWebhook(array $payload): array;
}
