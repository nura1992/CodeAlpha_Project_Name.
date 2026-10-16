<?php

namespace MedCore\Core;

use PDO;
use PDOException;
use Throwable;

/**
 * PDO data-access layer.
 *
 * - Prepared statements only (never interpolate user input into SQL).
 * - Supports MySQL (production / XAMPP) and SQLite (zero-config demo).
 * - Exposes a shared connection plus small query helpers.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static string $driver = 'mysql';

    public static function boot(): void
    {
        if (self::$pdo instanceof PDO) {
            return;
        }

        self::$driver = (string) Config::get('database.driver', 'mysql');

        if (self::$driver === 'sqlite') {
            $path = (string) Config::get('database.sqlite.path');
            if ($path[0] !== '/') {
                $path = BASE_PATH . '/' . $path;
            }
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $dsn = 'sqlite:' . $path;
            self::$pdo = new PDO($dsn, null, null, self::options());
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::$pdo->exec('PRAGMA journal_mode = WAL');
        } else {
            $c = Config::get('database.mysql');
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $c['host'],
                $c['port'],
                $c['name'],
                $c['charset']
            );
            self::$pdo = new PDO($dsn, $c['user'], $c['pass'], self::options());
        }
    }

    private static function options(): array
    {
        return [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
    }

    public static function pdo(): PDO
    {
        if (!self::$pdo instanceof PDO) {
            self::boot();
        }
        return self::$pdo;
    }

    public static function driver(): string
    {
        return self::$driver;
    }

    /** Run a SELECT and return all rows. */
    public static function select(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Run a SELECT and return the first row (or null). */
    public static function first(string $sql, array $params = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Run an INSERT/UPDATE/DELETE and return affected row count. */
    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** INSERT helper: accepts column => value pairs, returns last insert id. */
    public static function insert(string $table, array $data): string
    {
        $quote = self::$driver === 'sqlite' ? '"' : '`';
        $columns = array_map(static fn($c) => $quote . $c . $quote, array_keys($data));
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?'))
        );
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute(array_values($data));
        return self::pdo()->lastInsertId();
    }

    /** Nested transactions share the outermost PDO transaction. */
    private static int $txDepth = 0;

    /**
     * Transaction wrapper with automatic rollback on error.
     * Safe to nest: inner calls join the outer transaction; any failure
     * bubbles to the outermost level and rolls everything back.
     */
    public static function transaction(callable $callback)
    {
        $pdo = self::pdo();

        if (self::$txDepth > 0) {
            self::$txDepth++;
            try {
                return $callback($pdo);
            } finally {
                self::$txDepth--;
            }
        }

        $pdo->beginTransaction();
        self::$txDepth = 1;
        try {
            $result = $callback($pdo);
            self::$txDepth = 0;
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            self::$txDepth = 0;
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Driver-portable SQL fragment matching rows from the last N minutes.
     * Usage: WHERE success = 0 AND attempted_at >= {fragment}
     */
    public static function minutesAgo(int $minutes): string
    {
        $minutes = max(1, (int) $minutes);
        if (self::$driver === 'sqlite') {
            return "datetime('now', '-{$minutes} minutes')";
        }
        return "DATE_SUB(NOW(), INTERVAL {$minutes} MINUTE)";
    }

    /** Driver-portable current timestamp expression (UTC of the server). */
    public static function nowExpr(): string
    {
        return self::$driver === 'sqlite' ? "datetime('now')" : 'NOW()';
    }

    /** Driver-portable "timestamp N seconds/hours in the future" expression. */
    public static function plusMinutes(int $minutes): string
    {
        $minutes = (int) $minutes;
        if (self::$driver === 'sqlite') {
            return "datetime('now', '+{$minutes} minutes')";
        }
        return "DATE_ADD(NOW(), INTERVAL {$minutes} MINUTE)";
    }

    public static function tableExists(string $table): bool
    {
        try {
            if (self::$driver === 'sqlite') {
                return (bool) self::first(
                    "SELECT name FROM sqlite_master WHERE type='table' AND name = ?",
                    [$table]
                );
            }
            return (bool) self::first('SHOW TABLES LIKE ?', [$table]);
        } catch (PDOException $e) {
            return false;
        }
    }
}
