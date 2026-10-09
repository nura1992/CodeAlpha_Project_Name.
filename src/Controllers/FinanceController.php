<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Audit;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Rbac;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Payments\Fulfilment;
use App\Payments\GatewayConfig;

/**
 * Finance back office. Every money-moving action is permission-checked, audited, and
 * (for refunds) requires a second person to approve. Staff never see card data or secrets.
 */
final class FinanceController
{
    // ------------------------------------------------------------ transactions
    public function transactions(Request $r): array
    {
        $where = ['1=1'];
        $params = [];
        if ($r->query('q')) {
            $q = Validator::str($r->query('q'), 'q', 2, 64, false);
            $where[] = '(t.reference = ? OR t.gateway_transaction_id = ? OR u.username = ?)';
            array_push($params, $q, $q, strtolower((string) $q));
        }
        if ($r->query('status')) {
            $where[] = 't.status = ?';
            $params[] = Validator::in($r->query('status'), 'status', ['pending', 'successful', 'failed', 'abandoned', 'refunded', 'partially_refunded']);
        }
        if ($r->query('gateway')) {
            $where[] = 't.gateway = ?';
            $params[] = Validator::in($r->query('gateway'), 'gateway', ['paystack', 'flutterwave']);
        }
        if ($r->query('from')) {
            $where[] = 't.created_at >= ?';
            $params[] = Validator::date($r->query('from'), 'from')->format('Y-m-d') . ' 00:00:00';
        }
        if ($r->query('to')) {
            $where[] = 't.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = Validator::date($r->query('to'), 'to')->format('Y-m-d');
        }
        $rows = Database::all(
            'SELECT t.id, t.reference, t.gateway, t.amount_minor, t.refunded_minor, t.currency, t.status, t.created_at, t.verified_at,
                    u.username, sp.name AS plan_name
             FROM transactions t JOIN users u ON u.id = t.user_id LEFT JOIN subscription_plans sp ON sp.id = t.plan_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY t.id DESC LIMIT 100',
            $params
        );
        return ['items' => $rows];
    }

    public function transaction(Request $r): array
    {
        $tx = $this->tx((int) $r->param('id'));
        $user = Database::one('SELECT username, status FROM users WHERE id = ?', [$tx['user_id']]);
        return [
            'transaction' => $tx + ['username' => $user['username'] ?? null, 'user_status' => $user['status'] ?? null],
            'events' => Database::all('SELECT event_type, source, actor_id, status_before, status_after, payload, created_at FROM transaction_events WHERE transaction_id = ? ORDER BY id ASC', [$tx['id']]),
            'refunds' => Database::all('SELECT id, amount_minor, reason, status, requested_by, decided_by, created_at, decided_at FROM refunds WHERE transaction_id = ? ORDER BY id DESC', [$tx['id']]),
            'subscriptions' => Database::all('SELECT id, status, starts_at, ends_at FROM subscriptions WHERE transaction_id = ?', [$tx['id']]),
        ];
    }

    /** Re-verifies a pending transaction directly with the gateway (reconciliation). */
    public function reverify(Request $r): array
    {
        $tx = $this->tx((int) $r->param('id'));
        if ($tx['status'] !== 'pending') {
            return ['status' => $tx['status'], 'changed' => false];
        }
        $verified = Fulfilment::gateway($tx['gateway'])->verify($tx['reference']);
        $result = Fulfilment::apply((int) $tx['id'], $verified, 'admin', $r->userId());
        Audit::log($r->userId(), 'payment.reverified', 'transaction', $tx['id'], null, ['status' => 'pending'], ['result' => $result]);
        return ['status' => $this->tx((int) $tx['id'])['status'], 'changed' => $result === 'activated', 'result' => $result];
    }

    public function reconciliation(Request $r): array
    {
        $date = $r->query('date') ? Validator::date($r->query('date'), 'date')->format('Y-m-d') : date('Y-m-d');
        $summary = Database::all(
            'SELECT gateway, status, COUNT(*) AS count, COALESCE(SUM(amount_minor), 0) AS amount_minor
             FROM transactions WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)
             GROUP BY gateway, status ORDER BY gateway, status',
            [$date, $date]
        );
        $stalePending = Database::all(
            "SELECT id, reference, gateway, amount_minor, created_at FROM transactions
             WHERE status = 'pending' AND created_at < NOW() - INTERVAL 1 HOUR ORDER BY created_at ASC LIMIT 100"
        );
        $paidWithoutSubscription = Database::all(
            "SELECT t.id, t.reference, t.gateway, t.amount_minor, t.verified_at FROM transactions t
             WHERE t.status = 'successful' AND t.plan_id IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM subscriptions s WHERE s.transaction_id = t.id)
             ORDER BY t.id DESC LIMIT 100"
        );
        $mismatches = Database::all(
            "SELECT e.transaction_id, e.payload, e.created_at FROM transaction_events e
             WHERE e.event_type = 'amount_mismatch' AND e.created_at > NOW() - INTERVAL 7 DAY ORDER BY e.id DESC LIMIT 100"
        );
        return ['date' => $date, 'summary' => $summary, 'stale_pending' => $stalePending,
                'paid_without_subscription' => $paidWithoutSubscription, 'amount_mismatches' => $mismatches];
    }

    // ----------------------------------------------------------------- refunds
    /** Maker: requests a refund. Money does not move until a different staff member approves. */
    public function requestRefund(Request $r): array
    {
        $tx = $this->tx((int) $r->param('id'));
        if (!in_array($tx['status'], ['successful', 'partially_refunded'], true)) {
            throw new ApiException(409, 'INVALID_STATE', 'Only successful payments can be refunded.');
        }
        $amount = Validator::int($r->input('amount_minor'), 'amount_minor', 1, PHP_INT_MAX);
        $reason = Validator::str($r->input('reason'), 'reason', 10, 500);
        $pending = (int) Database::value("SELECT COALESCE(SUM(amount_minor), 0) FROM refunds WHERE transaction_id = ? AND status = 'requested'", [$tx['id']]);
        $available = (int) $tx['amount_minor'] - (int) $tx['refunded_minor'] - $pending;
        if ($amount > $available) {
            throw new ApiException(422, 'REFUND_EXCEEDS_BALANCE', 'Refund exceeds the refundable amount.', ['available_minor' => $available]);
        }
        $id = Database::insert(
            "INSERT INTO refunds (transaction_id, amount_minor, reason, status, requested_by) VALUES (?, ?, ?, 'requested', ?)",
            [$tx['id'], $amount, $reason, $r->userId()]
        );
        Audit::log($r->userId(), 'refund.requested', 'refund', $id, $reason, null, ['amount_minor' => $amount, 'transaction_id' => $tx['id']]);
        Response::status(201);
        return ['id' => $id, 'status' => 'requested'];
    }

    /** Checker: a different staff member approves, and the gateway refund is executed. */
    public function approveRefund(Request $r): array
    {
        $refundId = (int) $r->param('id');
        $me = $r->userId();
        $refund = Database::one('SELECT * FROM refunds WHERE id = ?', [$refundId]);
        if ($refund === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Refund not found.');
        }
        if ((int) $refund['requested_by'] === $me) {
            throw new ApiException(403, 'MAKER_CHECKER', 'A second staff member must approve this refund.');
        }
        if ($refund['status'] !== 'requested') {
            throw new ApiException(409, 'INVALID_STATE', 'This refund has already been decided.');
        }

        $outcome = Database::transaction(function () use ($refund, $me): array {
            $tx = Database::one('SELECT * FROM transactions WHERE id = ? FOR UPDATE', [$refund['transaction_id']]);
            $remaining = (int) $tx['amount_minor'] - (int) $tx['refunded_minor'];
            if ((int) $refund['amount_minor'] > $remaining) {
                throw new ApiException(422, 'REFUND_EXCEEDS_BALANCE', 'Refund exceeds the remaining balance.');
            }
            $result = Fulfilment::gateway($tx['gateway'])->refund($tx, (int) $refund['amount_minor'], $refund['reason']);
            if (!$result['ok']) {
                // Returned rather than thrown so the failure event survives (a throw would roll it back).
                return ['failed' => true, 'transaction_id' => (int) $tx['id'], 'status' => $tx['status']];
            }
            $newRefunded = (int) $tx['refunded_minor'] + (int) $refund['amount_minor'];
            $newStatus = $newRefunded >= (int) $tx['amount_minor'] ? 'refunded' : 'partially_refunded';
            Database::exec('UPDATE transactions SET refunded_minor = ?, status = ? WHERE id = ?', [$newRefunded, $newStatus, $tx['id']]);
            if ($newStatus === 'refunded') {
                Database::exec("UPDATE subscriptions SET status = 'refunded' WHERE transaction_id = ?", [$tx['id']]);
            }
            Database::exec(
                "UPDATE refunds SET status = 'processed', decided_by = ?, decided_at = NOW(), gateway_refund_reference = ? WHERE id = ?",
                [$me, $result['reference'], $refund['id']]
            );
            Fulfilment::event((int) $tx['id'], 'refund_processed', 'admin', $me, $tx['status'], $newStatus, ['refund_id' => $refund['id'], 'amount_minor' => (int) $refund['amount_minor']]);
            Audit::log($me, 'refund.processed', 'refund', $refund['id'], $refund['reason'], ['status' => 'requested'], ['status' => 'processed', 'amount_minor' => (int) $refund['amount_minor']]);
            return ['id' => (int) $refund['id'], 'status' => 'processed', 'transaction_status' => $newStatus];
        });
        if (!empty($outcome['failed'])) {
            Fulfilment::event($outcome['transaction_id'], 'refund_failed', 'admin', $me, $outcome['status'], $outcome['status'], ['refund_id' => $refundId]);
            throw new ApiException(502, 'GATEWAY_ERROR', 'The gateway declined the refund. It remains requested for another attempt.');
        }
        return $outcome;
    }

    public function rejectRefund(Request $r): array
    {
        $reason = Validator::str($r->input('reason'), 'reason', 10, 500);
        $refund = Database::one('SELECT * FROM refunds WHERE id = ?', [(int) $r->param('id')]);
        if ($refund === null || $refund['status'] !== 'requested') {
            throw new ApiException(409, 'INVALID_STATE', 'This refund cannot be rejected.');
        }
        if ((int) $refund['requested_by'] === $r->userId()) {
            throw new ApiException(403, 'MAKER_CHECKER', 'Another staff member must decide this refund.');
        }
        Database::exec("UPDATE refunds SET status = 'rejected', decided_by = ?, decided_at = NOW() WHERE id = ?", [$r->userId(), $refund['id']]);
        Audit::log($r->userId(), 'refund.rejected', 'refund', $refund['id'], $reason);
        return ['id' => (int) $refund['id'], 'status' => 'rejected'];
    }

    // -------------------------------------------------------------- reporting
    /** CSV export. Cells beginning with formula characters are neutralised to prevent CSV injection. */
    public function reportCsv(Request $r): ?array
    {
        $from = Validator::date($r->query('from', date('Y-m-01')), 'from')->format('Y-m-d');
        $to = Validator::date($r->query('to', date('Y-m-d')), 'to')->format('Y-m-d');
        $rows = Database::all(
            'SELECT t.reference, t.created_at, t.gateway, u.username, sp.code AS plan_code, t.amount_minor, t.refunded_minor,
                    t.currency, t.status, t.gateway_transaction_id, t.verified_at
             FROM transactions t JOIN users u ON u.id = t.user_id LEFT JOIN subscription_plans sp ON sp.id = t.plan_id
             WHERE t.created_at >= ? AND t.created_at < DATE_ADD(?, INTERVAL 1 DAY) ORDER BY t.id',
            [$from . ' 00:00:00', $to]
        );
        Audit::log($r->userId(), 'report.exported', 'report', 'payments', null, null, ['from' => $from, 'to' => $to, 'rows' => count($rows)]);

        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['reference', 'created_at', 'gateway', 'username', 'plan', 'amount_major', 'refunded_major', 'currency', 'status', 'gateway_transaction_id', 'verified_at']);
        foreach ($rows as $row) {
            fputcsv($out, array_map([self::class, 'csvCell'], [
                $row['reference'], $row['created_at'], $row['gateway'], $row['username'], $row['plan_code'],
                number_format($row['amount_minor'] / 100, 2, '.', ''), number_format($row['refunded_minor'] / 100, 2, '.', ''),
                $row['currency'], $row['status'], $row['gateway_transaction_id'], $row['verified_at'],
            ]));
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="payments-' . $from . '-to-' . $to . '.csv"');
        header('Cache-Control: no-store');
        echo "\xEF\xBB\xBF" . $csv;
        return null;
    }

    public static function csvCell(mixed $value): string
    {
        $s = (string) ($value ?? '');
        return preg_match('/^[=+\-@\t\r]/', $s) ? "'" . $s : $s;
    }

    // -------------------------------------------------------- gateways & plans
    public function gateways(Request $r): array
    {
        $out = [];
        foreach (['paystack', 'flutterwave'] as $code) {
            $out[] = GatewayConfig::publicStatus($code) ?? ['code' => $code, 'is_enabled' => 0, 'mode' => 'test', 'public_key' => null, 'has_secret' => 0, 'has_webhook_secret' => 0];
        }
        return ['items' => $out];
    }

    /** Stores credentials encrypted. Secrets are write-only: they are never returned. */
    public function updateGateway(Request $r): array
    {
        $code = Validator::in($r->param('code'), 'code', ['paystack', 'flutterwave']);
        $enabled = Validator::boolean($r->input('enabled'));
        $mode = Validator::in($r->input('mode', 'test'), 'mode', ['test', 'live']);
        $publicKey = Validator::str($r->input('public_key'), 'public_key', 0, 200, false);
        $secret = $r->input('secret_key') ? Validator::str($r->input('secret_key'), 'secret_key', 10, 200) : null;
        $webhookSecret = $r->input('webhook_secret') ? Validator::str($r->input('webhook_secret'), 'webhook_secret', 8, 200) : null;

        $current = Database::one('SELECT * FROM payment_gateways WHERE code = ?', [$code]) ?? [];
        if ($mode === 'live' && ($current['mode'] ?? 'test') !== 'live') {
            if (($r->input('confirm_live') ?? '') !== 'GO LIVE') {
                throw new ApiException(422, 'CONFIRM_REQUIRED', 'Type GO LIVE to switch this gateway to live mode.', ['field' => 'confirm_live']);
            }
        }
        $hasSecret = $secret !== null || !empty($current['secret_key_encrypted']);
        if ($enabled && !$hasSecret) {
            throw new ApiException(422, 'SECRET_REQUIRED', 'Add a secret key before enabling this gateway.', ['field' => 'secret_key']);
        }

        Database::exec(
            'INSERT INTO payment_gateways (code, is_enabled, mode, public_key, secret_key_encrypted, webhook_secret_encrypted, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), mode = VALUES(mode), public_key = VALUES(public_key),
               secret_key_encrypted = COALESCE(?, secret_key_encrypted), webhook_secret_encrypted = COALESCE(?, webhook_secret_encrypted),
               updated_by = VALUES(updated_by)',
            [
                $code, $enabled ? 1 : 0, $mode, $publicKey,
                $secret !== null ? Crypto::encrypt($secret, 'gateway|' . $code) : null,
                $webhookSecret !== null ? Crypto::encrypt($webhookSecret, 'gateway-webhook|' . $code) : null,
                $r->userId(),
                $secret !== null ? Crypto::encrypt($secret, 'gateway|' . $code) : null,
                $webhookSecret !== null ? Crypto::encrypt($webhookSecret, 'gateway-webhook|' . $code) : null,
            ]
        );
        // Audit records WHICH fields changed, never their values.
        Audit::log($r->userId(), 'gateway.updated', 'gateway', $code, $r->input('reason'), null, [
            'enabled' => $enabled, 'mode' => $mode, 'public_key_set' => $publicKey !== null,
            'secret_rotated' => $secret !== null, 'webhook_secret_rotated' => $webhookSecret !== null,
        ]);
        return ['gateway' => GatewayConfig::publicStatus($code)];
    }

    public function plans(Request $r): array
    {
        return ['items' => Database::all('SELECT id, code, name, description, amount_minor, currency, billing_period, entitlements, is_active FROM subscription_plans ORDER BY id')];
    }

    /** Amounts are entered in Naira and stored as kobo (minor units). */
    public function savePlan(Request $r): array
    {
        $id = $r->param('id') !== '' ? (int) $r->param('id') : null;
        $name = Validator::str($r->input('name'), 'name', 3, 100);
        $amountNaira = Validator::int($r->input('amount_naira'), 'amount_naira', 100, 10000000);
        $period = Validator::in($r->input('billing_period', 'monthly'), 'billing_period', ['monthly', 'quarterly', 'yearly']);
        $active = Validator::boolean($r->input('is_active', true)) ? 1 : 0;
        $description = Validator::str($r->input('description'), 'description', 0, 500, false);
        $entitlements = is_array($r->input('entitlements')) ? $r->input('entitlements') : [];
        $json = json_encode($entitlements, JSON_THROW_ON_ERROR);
        if ($id === null) {
            $code = strtolower(Validator::str($r->input('code'), 'code', 3, 40));
            if (!preg_match('/^[a-z0-9_-]+$/', $code)) {
                Validator::fail('code', 'Plan codes may contain lowercase letters, numbers, dashes and underscores.');
            }
            $newId = Database::insert(
                'INSERT INTO subscription_plans (code, name, description, amount_minor, billing_period, entitlements, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$code, $name, $description, $amountNaira * 100, $period, $json, $active, $r->userId()]
            );
            Audit::log($r->userId(), 'plan.created', 'plan', $newId, null, null, ['code' => $code, 'amount_minor' => $amountNaira * 100]);
            Response::status(201);
            return ['id' => $newId];
        }
        $before = Database::one('SELECT name, amount_minor, billing_period, is_active FROM subscription_plans WHERE id = ?', [$id]);
        if ($before === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Plan not found.');
        }
        // Historical transactions keep their original amounts; only future checkouts change.
        Database::exec(
            'UPDATE subscription_plans SET name = ?, description = ?, amount_minor = ?, billing_period = ?, entitlements = ?, is_active = ? WHERE id = ?',
            [$name, $description, $amountNaira * 100, $period, $json, $active, $id]
        );
        Audit::log($r->userId(), 'plan.updated', 'plan', $id, null, $before, ['name' => $name, 'amount_minor' => $amountNaira * 100, 'billing_period' => $period, 'is_active' => $active]);
        return ['id' => $id];
    }

    private function tx(int $id): array
    {
        $tx = Database::one('SELECT * FROM transactions WHERE id = ?', [$id]);
        if ($tx === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Transaction not found.');
        }
        return $tx;
    }
}
