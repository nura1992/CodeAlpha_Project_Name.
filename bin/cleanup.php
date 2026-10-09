<?php
/**
 * Cleanup — cron jobs for retention and housekeeping.
 *
 * Run daily: php bin/cleanup.php
 * Or via cron: 0 3 * * * php /path/to/bin/cleanup.php >> storage/logs/cleanup.log 2>&1
 */
declare(strict_types=1);
$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';
require $root . '/src/Core/helpers.php';

use CodeAlpha\Core\Database;
use CodeAlpha\Services\CallService;
use CodeAlpha\Services\MediaService;

echo "[" . date('c') . "] Cleanup started\n";

// Expire ringing calls
try {
    $expired = CallService::expireRinging();
    echo "Expired calls: $expired\n";
} catch (Throwable $e) { echo "Call expiry error: " . $e->getMessage() . "\n"; }

// Enforce verification media retention
try {
    $deleted = MediaService::enforceRetention();
    echo "Verification media expired: $deleted\n";
} catch (Throwable $e) { echo "Retention error: " . $e->getMessage() . "\n"; }

// Purge old rate_limits, old otps, old notifications (optional)
try {
    $cutoff = date('Y-m-d H:i:s', time() - 7 * 86400);
    $n = Database::delete('rate_limits', 'expires_at < :now', ['now'=>date('Y-m-d H:i:s')]);
    echo "Purged rate_limits: $n\n";
    Database::delete('otps', 'expires_at < :now AND consumed_at IS NOT NULL', ['now'=>date('Y-m-d H:i:s', time() - 3600)]);
    echo "Purged old OTPs\n";
} catch (Throwable $e) { echo "Purge error: " . $e->getMessage() . "\n"; }

echo "[" . date('c') . "] Cleanup done\n";
