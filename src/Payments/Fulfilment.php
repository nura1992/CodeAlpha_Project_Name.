<?php
declare(strict_types=1);

namespace App\Payments;

use App\Core\Database;
use App\Core\Notifier;
use DateTimeImmutable;

/**
 * The ONLY code path that marks a transaction successful and activates a subscription.
 * Called after a gateway API verification or a validated webhook. Idempotent: replaying the
 * same verification never double-activates. Runs under a row lock inside a transaction.
 */
final class Fulfilment
{
    /** @param array $verified output of PaymentGateway::verify() */
    public static function apply(int $txId, array $verified, string $source, ?int $actorId = null): string
    {
        return Database::transaction(static function () use ($txId, $verified, $source, $actorId): string {
            $tx = Database::one('SELECT * FROM transactions WHERE id = ? FOR UPDATE', [$txId]);
            if ($tx === null) {
                return 'not_found';
            }
            $before = $tx['status'];
            if (in_array($before, ['successful', 'refunded', 'partially_refunded'], true)) {
                self::event($txId, 'duplicate_ignored', $source, $actorId, $before, $before, ['status' => $verified['status'] ?? null]);
                return 'already_processed';
            }
            if (($verified['reference'] ?? null) !== $tx['reference']) {
                self::event($txId, 'reference_mismatch', $source, $actorId, $before, $before, []);
                return 'mismatch';
            }
            $gatewayStatus = (string) ($verified['status'] ?? '');
            if (!($verified['ok'] ?? false) || !in_array($gatewayStatus, ['success', 'successful'], true)) {
                if (in_array($verified['status'] ?? '', ['failed', 'abandoned', 'reversed'], true) && $before === 'pending') {
                    Database::exec("UPDATE transactions SET status = 'failed' WHERE id = ?", [$txId]);
                    self::event($txId, 'payment_failed', $source, $actorId, $before, 'failed', ['gateway_status' => $verified['status']]);
                    return 'failed';
                }
                return 'pending';
            }
            if ((int) $verified['amount_minor'] !== (int) $tx['amount_minor'] || strtoupper((string) $verified['currency']) !== $tx['currency']) {
                // Amount or currency mismatch: never activate. Flag for finance review.
                self::event($txId, 'amount_mismatch', $source, $actorId, $before, $before, [
                    'expected_minor' => (int) $tx['amount_minor'], 'received_minor' => (int) $verified['amount_minor'],
                    'expected_currency' => $tx['currency'], 'received_currency' => $verified['currency'],
                ]);
                return 'mismatch';
            }

            Database::exec(
                "UPDATE transactions SET status = 'successful', verified_at = NOW(),
                 gateway_reference = ?, gateway_transaction_id = ? WHERE id = ?",
                [$tx['reference'], $verified['gateway_transaction_id'] ?? null, $txId]
            );
            self::event($txId, 'payment_verified', $source, $actorId, $before, 'successful', [
                'gateway_transaction_id' => $verified['gateway_transaction_id'] ?? null,
                'amount_minor' => (int) $verified['amount_minor'],
                'currency' => $verified['currency'],
            ]);

            if (!empty($tx['plan_id'])) {
                self::activateSubscription($tx);
            }
            Notifier::notify((int) $tx['user_id'], 'subscription', 'Payment received', 'Your subscription is now active.', ['reference' => $tx['reference']]);
            return 'activated';
        });
    }

    private static function activateSubscription(array $tx): void
    {
        $plan = Database::one('SELECT * FROM subscription_plans WHERE id = ?', [$tx['plan_id']]);
        if ($plan === null) {
            return;
        }
        $currentEnd = Database::value("SELECT MAX(ends_at) FROM subscriptions WHERE user_id = ? AND status = 'active'", [$tx['user_id']]);
        // Renewals extend from the current end date so no paid time is lost.
        $start = max(time(), $currentEnd ? strtotime($currentEnd . ' UTC') : 0);
        $startAt = (new DateTimeImmutable('@' . $start));
        $interval = ['monthly' => 'P1M', 'quarterly' => 'P3M', 'yearly' => 'P1Y'][$plan['billing_period']] ?? 'P1M';
        $endAt = $startAt->add(new \DateInterval($interval));
        Database::exec(
            "INSERT INTO subscriptions (user_id, plan_id, transaction_id, status, starts_at, ends_at) VALUES (?, ?, ?, 'active', ?, ?)",
            [$tx['user_id'], $tx['plan_id'], $tx['id'], $startAt->format('Y-m-d H:i:s'), $endAt->format('Y-m-d H:i:s')]
        );
    }

    /** Append-only transaction event (the database blocks UPDATE and DELETE on this table). */
    public static function event(int $txId, string $type, string $source, ?int $actorId, ?string $before, ?string $after, array $payload): void
    {
        Database::exec(
            'INSERT INTO transaction_events (transaction_id, event_type, source, actor_id, status_before, status_after, payload)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$txId, $type, $source, $actorId, $before, $after, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]
        );
    }

    public static function gateway(string $code): PaymentGateway
    {
        $config = GatewayConfig::load($code);
        return match ($code) {
            'paystack' => new PaystackGateway($config),
            'flutterwave' => new FlutterwaveGateway($config),
            default => throw new \InvalidArgumentException('Unknown gateway'),
        };
    }
}
