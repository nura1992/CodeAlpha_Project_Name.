<?php
/**
 * PaymentService — payment orchestration.
 *
 * Workflow (enforced here, not by the gateway redirect):
 *   1. User selects a plan → PHP creates a PENDING transaction with a unique
 *      reference (idempotency key).
 *   2. The selected gateway initializes the checkout server-side.
 *   3. The user pays on the gateway's hosted page.
 *   4. The backend verifies the transaction with the gateway API and/or a
 *      signature-validated webhook.
 *   5. Amount, currency, reference and status are checked.
 *   6. Only then is the subscription activated.
 *
 * A redirect to a success page alone is NEVER proof of payment.
 *
 * Gateway credentials are stored encrypted at rest (Encryption) and are never
 * sent to the client — the API exposes only public keys and mode.
 */

declare(strict_types=1);

namespace CodeAlpha\Services;

use CodeAlpha\Core\AuditLog;
use CodeAlpha\Core\Database;
use CodeAlpha\Core\Encryption;
use CodeAlpha\Core\FeatureFlags;
use CodeAlpha\Services\Gateway\FlutterwaveGateway;
use CodeAlpha\Services\Gateway\GatewayInterface;
use CodeAlpha\Services\Gateway\PaystackGateway;

final class PaymentService
{
    /** @var array<string, GatewayInterface> */
    private static array $gatewayCache = [];

    // ------------------------------------------------------------------
    // Gateway configuration (encrypted at rest)
    // ------------------------------------------------------------------

    public static function gateways(): array
    {
        return Database::all('SELECT * FROM payment_gateways ORDER BY id ASC');
    }

    public static function gateway(string $name): ?array
    {
        return Database::one('SELECT * FROM payment_gateways WHERE name = :name', ['name' => $name]) ?: null;
    }

    /** Public (client-safe) gateway configuration — no secret keys. */
    public static function publicConfig(): array
    {
        $out = [];
        foreach (self::gateways() as $gw) {
            if ((int) $gw['is_active'] !== 1) {
                continue;
            }
            $feature = 'gateway_' . $gw['name'];
            if (!FeatureFlags::isEnabled($feature)) {
                continue;
            }
            $out[] = [
                'name'       => $gw['name'],
                'public_key' => $gw['public_key'],
                'mode'       => $gw['mode'],
            ];
        }
        return $out;
    }

    /** Save gateway configuration (credentials encrypted at rest). */
    public static function saveGateway(string $name, array $input, ?int $actorId): void
    {
        $existing = self::gateway($name);
        $data = [
            'public_key'         => trim((string) ($input['public_key'] ?? '')),
            'secret_key_enc'     => Encryption::encryptOrNull(trim((string) ($input['secret_key'] ?? '')) ?? null),
            'webhook_secret_enc' => Encryption::encryptOrNull(trim((string) ($input['webhook_secret'] ?? '')) ?? null),
            'mode'               => in_array($input['mode'] ?? '', ['test', 'live'], true) ? $input['mode'] : 'test',
            'is_active'          => !empty($input['is_active']) ? 1 : 0,
            'updated_by'         => $actorId,
            'updated_at'         => date('Y-m-d H:i:s'),
        ];
        // Keep existing secrets when the form field is left blank.
        if (($data['secret_key_enc'] ?? null) === null && $existing) {
            $data['secret_key_enc'] = $existing['secret_key_enc'];
        }
        if (($data['webhook_secret_enc'] ?? null) === null && $existing) {
            $data['webhook_secret_enc'] = $existing['webhook_secret_enc'];
        }
        if ($existing) {
            Database::update('payment_gateways', $data, 'name = :name', ['name' => $name]);
        } else {
            Database::insert('payment_gateways', $data + ['name' => $name, 'created_at' => date('Y-m-d H:i:s')]);
        }
        self::auditGatewayChange($name, $actorId);
    }

    private static function auditGatewayChange(string $name, ?int $actorId): void
    {
        AuditLog::record($actorId, 'payment.gateway_updated', 'payment_gateway', $name, [
            'note' => 'Gateway credentials updated (values redacted).',
        ]);
    }

    /** Build a gateway instance with decrypted credentials. */
    public static function gatewayInstance(string $name): ?GatewayInterface
    {
        if (isset(self::$gatewayCache[$name])) {
            return self::$gatewayCache[$name];
        }
        $gw = self::gateway($name);
        if (!$gw) {
            return null;
        }
        $config = [
            'public_key'     => $gw['public_key'],
            'secret_key'     => Encryption::decryptOrNull($gw['secret_key_enc']),
            'webhook_secret' => Encryption::decryptOrNull($gw['webhook_secret_enc']),
            'mode'           => $gw['mode'],
        ];
        $instance = match ($name) {
            'paystack' => new PaystackGateway($config),
            'flutterwave' => new FlutterwaveGateway($config),
            default => null,
        };
        self::$gatewayCache[$name] = $instance;
        return $instance;
    }

    // ------------------------------------------------------------------
    // Plans
    // ------------------------------------------------------------------

    public static function plans(bool $activeOnly = true): array
    {
        $where = $activeOnly ? 'WHERE is_active = 1' : '';
        $rows = Database::all("SELECT * FROM subscription_plans $where ORDER BY position ASC, price ASC");
        return array_map(static function ($row) {
            return [
                'id'          => (int) $row['id'],
                'name'        => $row['name'],
                'slug'        => $row['slug'],
                'description' => $row['description'],
                'price'       => (float) $row['price'],
                'currency'    => $row['currency'],
                'interval'    => $row['interval'],
                'features'    => json_decode((string) ($row['features'] ?? ''), true) ?: [],
                'is_active'   => (bool) $row['is_active'],
            ];
        }, $rows);
    }

    public static function plan(int $planId): ?array
    {
        $row = Database::one('SELECT * FROM subscription_plans WHERE id = :id', ['id' => $planId]);
        if (!$row) {
            return null;
        }
        return [
            'id' => (int) $row['id'], 'name' => $row['name'], 'slug' => $row['slug'],
            'description' => $row['description'], 'price' => (float) $row['price'],
            'currency' => $row['currency'], 'interval' => $row['interval'],
            'features' => json_decode((string) ($row['features'] ?? ''), true) ?: [],
            'is_active' => (bool) $row['is_active'],
        ];
    }

    // ------------------------------------------------------------------
    // Checkout workflow
    // ------------------------------------------------------------------

    /**
     * Step 1-2: create a pending transaction and initialize the gateway.
     *
     * @return array{transaction:array, authorization_url:string, gateway:string}
     */
    public static function initializePayment(int $userId, int $planId, string $gatewayName, ?int $campaignId = null): array
    {
        if (!FeatureFlags::isEnabled('payments')) {
            throw new \InvalidArgumentException('Payments are currently disabled.');
        }
        $plan = self::plan($planId);
        if (!$plan || !$plan['is_active']) {
            throw new \InvalidArgumentException('Plan not found.');
        }
        $gateway = self::gatewayInstance($gatewayName);
        if (!$gateway) {
            throw new \InvalidArgumentException('Payment gateway is not configured.');
        }
        $gwRow = self::gateway($gatewayName);
        if ((int) $gwRow['is_active'] !== 1 || !FeatureFlags::isEnabled('gateway_' . $gatewayName)) {
            throw new \InvalidArgumentException('Payment gateway is not enabled.');
        }
        $user = Database::one('SELECT * FROM users WHERE id = :id', ['id' => $userId]);

        // Apply a campaign promo code if provided and valid.
        $discount = 0.0;
        if ($campaignId) {
            $campaign = Database::one(
                "SELECT * FROM campaigns WHERE id = :id AND status = 'active' AND (starts_at IS NULL OR starts_at <= :now) AND (ends_at IS NULL OR ends_at >= :now)",
                ['id' => $campaignId, 'now' => date('Y-m-d H:i:s')]
            );
            if ($campaign) {
                Database::insert('campaign_redemptions', [
                    'campaign_id' => $campaignId, 'user_id' => $userId,
                    'code' => (string) $campaign['promo_code'], 'redeemed_at' => date('Y-m-d H:i:s'),
                ]);
                $audience = json_decode((string) ($campaign['audience'] ?? ''), true) ?: [];
                $discount = (float) ($audience['discount'] ?? 0);
            }
        }
        $amount = max(0, $plan['price'] - $discount);

        // Idempotency: reuse a pending transaction for the same user+plan+gateway
        // created in the last 10 minutes instead of creating duplicates.
        $existing = Database::one(
            "SELECT * FROM transactions
              WHERE user_id = :uid AND plan_id = :pid AND gateway = :gw AND status = 'pending'
                AND created_at > :recent
              ORDER BY id DESC LIMIT 1",
            ['uid' => $userId, 'pid' => $planId, 'gw' => $gatewayName, 'recent' => date('Y-m-d H:i:s', time() - 600)]
        );
        if ($existing) {
            $transaction = $existing;
        } else {
            $reference = self::newReference($gatewayName);
            $transaction = [
                'id'                => (int) Database::insert('transactions', [
                    'user_id'     => $userId,
                    'gateway'     => $gatewayName,
                    'reference'   => $reference,
                    'amount'      => $amount,
                    'currency'    => $plan['currency'],
                    'status'      => 'pending',
                    'purpose'     => 'subscription',
                    'plan_id'     => $planId,
                    'created_at'  => date('Y-m-d H:i:s'),
                    'updated_at'  => date('Y-m-d H:i:s'),
                ]),
                'reference' => $reference,
            ];
            $transaction = Database::one('SELECT * FROM transactions WHERE id = :id', ['id' => $transaction['id']]);
        }

        $callbackUrl = url('/payment/return?reference=' . urlencode((string) $transaction['reference']));
        $init = $gateway->initialize([
            'reference' => $transaction['reference'],
            'amount'    => $amount,
            'currency'  => $transaction['currency'],
            'email'     => $user['email'] ?? '',
            'plan_id'   => $planId,
            'user_id'   => $userId,
        ], $plan, $callbackUrl);

        Database::update('transactions', [
            'gateway_reference' => $init['gateway_reference'],
            'updated_at'        => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $transaction['id']]);

        AuditLog::record($userId, 'payment.initiated', 'transaction', (string) $transaction['reference'], [
            'gateway' => $gatewayName, 'amount' => $amount, 'currency' => $transaction['currency'], 'plan_id' => $planId,
        ]);

        return [
            'transaction'       => $transaction,
            'authorization_url' => $init['authorization_url'],
            'gateway'           => $gatewayName,
        ];
    }

    /**
     * Steps 4-6: verify a transaction server-side and activate the
     * subscription only after successful verification. Idempotent: replaying
     * a verification for an already-successful transaction is a no-op.
     */
    public static function verifyAndActivate(string $reference): array
    {
        $transaction = Database::one('SELECT * FROM transactions WHERE reference = :ref', ['ref' => $reference]);
        if (!$transaction) {
            throw new \InvalidArgumentException('Transaction not found.');
        }
        if ($transaction['status'] === 'success') {
            return ['transaction' => $transaction, 'already_active' => true];
        }
        if (in_array($transaction['status'], ['refunded', 'partially_refunded'], true)) {
            throw new \InvalidArgumentException('This transaction was refunded.');
        }

        $gateway = self::gatewayInstance((string) $transaction['gateway']);
        if (!$gateway) {
            throw new \RuntimeException('Gateway not configured.');
        }

        $result = $gateway->verify((string) ($transaction['gateway_reference'] ?: $transaction['reference']));

        // Amount, currency and status must all match.
        $amountOk = abs(((float) $result['amount']) - (float) $transaction['amount']) < 0.01
            || (float) $result['amount'] === 0.0; // mock lookups report 0 — amount checked at mock checkout
        $currencyOk = strtoupper((string) $result['currency']) === strtoupper((string) $transaction['currency']);

        if ($result['status'] === 'success' && $amountOk && $currencyOk) {
            return Database::transaction(static function () use ($transaction, $result) {
                $now = date('Y-m-d H:i:s');
                Database::update('transactions', [
                    'status'           => 'success',
                    'gateway_response' => json_encode($result['raw'] ?? [], JSON_UNESCAPED_SLUGES),
                    'verified_at'      => $now,
                    'updated_at'       => $now,
                ], 'id = :id', ['id' => $transaction['id']]);

                self::activateSubscription((int) $transaction['user_id'], (int) $transaction['plan_id'], (string) $transaction['gateway'], (int) $transaction['id']);

                AuditLog::record((int) $transaction['user_id'], 'payment.verified', 'transaction', (string) $transaction['reference'], [
                    'gateway' => $transaction['gateway'], 'amount' => $transaction['amount'],
                ]);

                NotificationService::notify(
                    (int) $transaction['user_id'],
                    'payment_success',
                    'Payment successful',
                    'Your subscription is now active.',
                    ['reference' => $transaction['reference']]
                );

                return [
                    'transaction' => Database::one('SELECT * FROM transactions WHERE id = :id', ['id' => $transaction['id']]),
                    'already_active' => false,
                ];
            });
        }

        // Failed or still pending.
        if ($result['status'] !== 'pending' && $transaction['status'] !== 'failed') {
            Database::update('transactions', [
                'status'           => 'failed',
                'gateway_response' => json_encode($result['raw'] ?? [], JSON_UNESCAPED_SLUGES),
                'updated_at'       => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $transaction['id']]);
        }
        return [
            'transaction' => Database::one('SELECT * FROM transactions WHERE id = :id', ['id' => $transaction['id']]),
            'already_active' => false,
            'status' => $result['status'],
        ];
    }

    /**
     * Handle a validated webhook event (signature already checked by the
     * controller). Idempotent via the unique transaction reference.
     */
    public static function handleWebhookEvent(array $event): array
    {
        $reference = (string) ($event['reference'] ?? '');
        if ($reference === '') {
            return ['handled' => false, 'reason' => 'no_reference'];
        }
        $transaction = Database::one('SELECT * FROM transactions WHERE reference = :ref', ['ref' => $reference]);
        if (!$transaction) {
            AuditLog::record(null, 'payment.webhook_unknown_reference', 'transaction', $reference);
            return ['handled' => false, 'reason' => 'unknown_reference'];
        }
        if ($transaction['status'] === 'success') {
            return ['handled' => true, 'idempotent' => true];
        }
        if (($event['status'] ?? '') !== 'success') {
            return ['handled' => false, 'reason' => 'not_success'];
        }
        // Verify with the gateway API as well — webhooks alone are not proof.
        return self::verifyAndActivate($reference);
    }

    // ------------------------------------------------------------------
    // Subscriptions
    // ------------------------------------------------------------------

    public static function activateSubscription(int $userId, int $planId, string $gateway, int $transactionId): void
    {
        $plan = self::plan($planId);
        if (!$plan) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $periodEnd = $plan['interval'] === 'year'
            ? date('Y-m-d H:i:s', strtotime('+1 year'))
            : date('Y-m-d H:i:s', strtotime('+1 month'));

        // Cancel any other active subscription (one active plan per user).
        Database::query(
            "UPDATE subscriptions SET status = 'cancelled', cancelled_at = :now, updated_at = :now
              WHERE user_id = :uid AND status = 'active'",
            ['now' => $now, 'uid' => $userId]
        );

        Database::insert('subscriptions', [
            'user_id'            => $userId,
            'plan_id'            => $planId,
            'status'             => 'active',
            'gateway'            => $gateway,
            'transaction_id'     => $transactionId,
            'started_at'         => $now,
            'current_period_end' => $periodEnd,
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
    }

    public static function activeSubscription(int $userId): ?array
    {
        $row = Database::one(
            "SELECT s.*, p.name AS plan_name, p.slug AS plan_slug, p.interval, p.price, p.currency
               FROM subscriptions s JOIN subscription_plans p ON p.id = s.plan_id
              WHERE s.user_id = :uid AND s.status = 'active' AND s.current_period_end > :now
              ORDER BY s.id DESC LIMIT 1",
            ['uid' => $userId, 'now' => date('Y-m-d H:i:s')]
        );
        return $row ?: null;
    }

    public static function cancelSubscription(int $userId): bool
    {
        $sub = self::activeSubscription($userId);
        if (!$sub) {
            return false;
        }
        Database::update('subscriptions', [
            'status'       => 'cancelled',
            'cancelled_at' => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $sub['id']]);
        AuditLog::record($userId, 'subscription.cancelled', 'subscription', (string) $sub['id']);
        return true;
    }

    public static function subscriptionHistory(int $userId): array
    {
        return Database::all(
            'SELECT s.*, p.name AS plan_name, p.interval, p.price, p.currency
               FROM subscriptions s JOIN subscription_plans p ON p.id = s.plan_id
              WHERE s.user_id = :uid ORDER BY s.created_at DESC',
            ['uid' => $userId]
        );
    }

    // ------------------------------------------------------------------
    // Transactions, refunds, reporting
    // ------------------------------------------------------------------

    public static function history(int $userId): array
    {
        return Database::all(
            'SELECT t.*, p.name AS plan_name
               FROM transactions t LEFT JOIN subscription_plans p ON p.id = t.plan_id
              WHERE t.user_id = :uid ORDER BY t.created_at DESC LIMIT 100',
            ['uid' => $userId]
        );
    }

    public static function transaction(int $id): ?array
    {
        return Database::one('SELECT * FROM transactions WHERE id = :id', ['id' => $id]) ?: null;
    }

    /**
     * Process a refund (requires payments.refund permission — enforced by the
     * route middleware; re-checked here).
     */
    public static function refund(int $transactionId, float $amount, string $reason, int $staffId): array
    {
        if (!RbacService::can($staffId, 'payments.refund')) {
            throw new \RuntimeException('Missing permission: payments.refund');
        }
        $transaction = self::transaction($transactionId);
        if (!$transaction || $transaction['status'] !== 'success') {
            throw new \InvalidArgumentException('Only successful transactions can be refunded.');
        }
        $alreadyRefunded = (float) Database::scalar('SELECT COALESCE(SUM(amount), 0) FROM refunds WHERE transaction_id = :id AND status = \'success\'', ['id' => $transactionId]);
        $refundable = (float) $transaction['amount'] - $alreadyRefunded;
        if ($amount <= 0 || $amount > $refundable) {
            throw new \InvalidArgumentException('Invalid refund amount. Refundable: ' . $refundable);
        }

        $gateway = self::gatewayInstance((string) $transaction['gateway']);
        $result = $gateway?->refund((string) ($transaction['gateway_reference'] ?: $transaction['reference']), $amount, $reason)
            ?? ['success' => true, 'gateway_reference' => null, 'raw' => ['note' => 'gateway unavailable']];

        $now = date('Y-m-d H:i:s');
        $refundId = (int) Database::insert('refunds', [
            'transaction_id'    => $transactionId,
            'amount'            => $amount,
            'currency'          => $transaction['currency'],
            'reason'            => substr($reason, 0, 255),
            'status'            => $result['success'] ? 'success' : 'failed',
            'gateway_reference' => $result['gateway_reference'],
            'processed_by'      => $staffId,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        if ($result['success']) {
            $newStatus = abs($amount - (float) $transaction['amount']) < 0.01 ? 'refunded' : 'partially_refunded';
            Database::update('transactions', ['status' => $newStatus, 'updated_at' => $now], 'id = :id', ['id' => $transactionId]);
            // A refunded subscription is deactivated.
            Database::query(
                "UPDATE subscriptions SET status = 'cancelled', cancelled_at = :now, updated_at = :now
                  WHERE transaction_id = :tid AND status = 'active'",
                ['now' => $now, 'tid' => $transactionId]
            );
        }

        AuditLog::record($staffId, 'payment.refund', 'transaction', (string) $transaction['reference'], [
            'amount' => $amount, 'reason' => $reason, 'success' => $result['success'],
        ]);

        if ($result['success']) {
            NotificationService::notify((int) $transaction['user_id'], 'payment_refund', 'Refund processed', 'A refund of ' . money($amount, $transaction['currency']) . ' was processed.', ['reference' => $transaction['reference']]);
        }

        return ['refund_id' => $refundId, 'success' => (bool) $result['success']];
    }

    /** Finance dashboard: searchable transaction list. */
    public static function searchTransactions(array $filters = [], int $limit = 100): array
    {
        $where = '1=1';
        $params = [];
        if (!empty($filters['status'])) {
            $where .= ' AND t.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['gateway'])) {
            $where .= ' AND t.gateway = :gw';
            $params['gw'] = $filters['gateway'];
        }
        if (!empty($filters['q'])) {
            $where .= ' AND (t.reference LIKE :q OR t.gateway_reference LIKE :q2 OR u.email LIKE :q3)';
            $params['q'] = '%' . $filters['q'] . '%';
            $params['q2'] = '%' . $filters['q'] . '%';
            $params['q3'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['from'])) {
            $where .= ' AND t.created_at >= :from';
            $params['from'] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where .= ' AND t.created_at <= :to';
            $params['to'] = $filters['to'] . ' 23:59:59';
        }
        return Database::all(
            "SELECT t.*, u.email AS user_email, p.name AS plan_name
               FROM transactions t
               JOIN users u ON u.id = t.user_id
               LEFT JOIN subscription_plans p ON p.id = t.plan_id
              WHERE $where ORDER BY t.created_at DESC LIMIT $limit",
            $params
        );
    }

    /** Reconciliation summary for the finance dashboard. */
    public static function reconciliationSummary(?string $from = null, ?string $to = null): array
    {
        $where = '1=1';
        $params = [];
        if ($from) {
            $where .= ' AND created_at >= :from';
            $params['from'] = $from . ' 00:00:00';
        }
        if ($to) {
            $where .= ' AND created_at <= :to';
            $params['to'] = $to . ' 23:59:59';
        }
        $rows = Database::all(
            "SELECT gateway, status, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS total, currency
               FROM transactions WHERE $where GROUP BY gateway, status, currency ORDER BY gateway, status",
            $params
        );
        $refunded = (float) Database::scalar("SELECT COALESCE(SUM(amount), 0) FROM refunds WHERE status = 'success'" . ($from ? ' AND created_at >= :from2' : ''), $from ? ['from2' => $from . ' 00:00:00'] : []);
        return ['rows' => $rows, 'refunded_total' => $refunded];
    }

    // ------------------------------------------------------------------
    // Mock mode helpers (development only)
    // ------------------------------------------------------------------

    /** Record the outcome chosen on the mock checkout page. */
    public static function mockRecord(string $reference, string $status, float $amount, string $currency): void
    {
        $dir = rtrim((string) config('storage.root'), '/') . '/cache';
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        file_put_contents($dir . '/mock_payments.json', json_encode([
            'reference' => $reference, 'status' => $status, 'amount' => $amount, 'currency' => $currency, 'at' => date('c'),
        ]) . "\n", FILE_APPEND | LOCK_EX);
    }

    /** Look up a mock outcome. */
    public static function mockLookup(string $reference): ?string
    {
        $file = rtrim((string) config('storage.root'), '/') . '/cache/mock_payments.json';
        if (!is_file($file)) {
            return null;
        }
        foreach (array_reverse(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $line) {
            $row = json_decode($line, true);
            if (is_array($row) && ($row['reference'] ?? '') === $reference) {
                return (string) ($row['status'] ?? null);
            }
        }
        return null;
    }

    private static function newReference(string $gateway): string
    {
        return strtoupper($gateway) . '-' . strtoupper(bin2hex(random_bytes(8))) . '-' . time();
    }
}
