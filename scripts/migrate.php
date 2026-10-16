<?php

declare(strict_types=1);

/**
 * MedCore migration runner.
 *
 *   php scripts/migrate.php
 *
 * Applies pending SQL migration files from database/migrations/ in order
 * and records them in the migrations table. Safe to re-run.
 */

use MedCore\Core\Database;

require dirname(__DIR__) . '/app/bootstrap.php';

// CLI bootstrap guard
if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}

Database::execute(
    Database::driver() === 'sqlite'
        ? 'CREATE TABLE IF NOT EXISTS migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration TEXT NOT NULL UNIQUE, applied_at TEXT NOT NULL)'
        : 'CREATE TABLE IF NOT EXISTS migrations (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, migration VARCHAR(190) NOT NULL UNIQUE, applied_at DATETIME NOT NULL) ENGINE=InnoDB'
);

$appliedRows = Database::select('SELECT migration FROM migrations');
$applied = array_map(static fn($r) => $r['migration'], $appliedRows);

$files = glob(BASE_PATH . '/database/migrations/*.php') ?: [];
sort($files);

$driver = Database::driver();
$count = 0;

foreach ($files as $file) {
    $name = basename($file, '.php');
    if (in_array($name, $applied, true)) {
        fwrite(STDOUT, "  [skip] {$name} (already applied)\n");
        continue;
    }
    $sets = require $file;
    if (!isset($sets[$driver])) {
        fwrite(STDERR, "  [fail] {$name}: no SQL set for driver {$driver}\n");
        exit(1);
    }
    fwrite(STDOUT, "  [run ] {$name} ... ");
    try {
        Database::transaction(function () use ($sets, $driver, $name) {
            foreach ($sets[$driver] as $sql) {
                Database::execute($sql);
            }
            Database::insert('migrations', [
                'migration'  => $name,
                'applied_at' => date('Y-m-d H:i:s'),
            ]);
        });
    } catch (Throwable $e) {
        fwrite(STDOUT, "FAILED\n    " . $e->getMessage() . "\n");
        exit(1);
    }
    $count++;
    fwrite(STDOUT, "ok\n");
}

fwrite(STDOUT, $count === 0
    ? "Nothing to migrate — schema is up to date (driver: {$driver}).\n"
    : "Applied {$count} migration(s) (driver: {$driver}).\n");
