<?php
declare(strict_types=1);

/**
 * Securely deletes verification videos past their retention date (verification.retention_days).
 * Schedule daily from cron, for example:
 *   15 3 * * *  cd /var/www/app && php bin/purge_verification.php >> storage/private/logs/purge.log 2>&1
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Controllers\VerificationController;

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$deleted = VerificationController::purgeExpired();
echo '[' . date('c') . "] verification media purged: {$deleted}\n";
