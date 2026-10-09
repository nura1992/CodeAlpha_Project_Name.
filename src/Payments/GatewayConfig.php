<?php
declare(strict_types=1);

namespace App\Payments;

use App\Core\ApiException;
use App\Core\Crypto;
use App\Core\Database;

/** Loads gateway credentials. Secrets are decrypted only server-side, just before use. */
final class GatewayConfig
{
    public static function load(string $code): array
    {
        $row = Database::one('SELECT * FROM payment_gateways WHERE code = ?', [$code]);
        if ($row === null) {
            throw new ApiException(503, 'GATEWAY_NOT_CONFIGURED', 'This payment method is not configured yet.');
        }
        return [
            'enabled' => (bool) $row['is_enabled'],
            'mode' => $row['mode'],
            'public_key' => $row['public_key'],
            'secret' => $row['secret_key_encrypted'] ? Crypto::decrypt((string) $row['secret_key_encrypted'], 'gateway|' . $code) : '',
            'webhook_secret' => $row['webhook_secret_encrypted'] ? Crypto::decrypt((string) $row['webhook_secret_encrypted'], 'gateway-webhook|' . $code) : '',
        ];
    }

    /** Metadata safe to show to staff: never includes secret values. */
    public static function publicStatus(string $code): ?array
    {
        $row = Database::one('SELECT code, is_enabled, mode, public_key, secret_key_encrypted IS NOT NULL AS has_secret,
                                     webhook_secret_encrypted IS NOT NULL AS has_webhook_secret, updated_at
                              FROM payment_gateways WHERE code = ?', [$code]);
        return $row ?: null;
    }
}
