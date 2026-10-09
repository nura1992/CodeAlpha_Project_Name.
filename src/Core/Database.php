<?php
/**
 * Database — PDO singleton with a small query helper API.
 *
 * Production uses MySQL (utf8mb4). A SQLite driver is included so the
 * installer and the bundled dev server can run the full application without
 * a MySQL server. All access goes through prepared statements.
 */

declare(strict_types=1);

namespace CodeAlpha\Core;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;
    private static ?string $driver = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $driver = (string) config('db.driver', 'mysql');
        self::$driver = $driver;

        if ($driver === 'sqlite') {
            $path = (string) config('db.sqlite_path');
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0770, true);
            }
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Emulated prepares are required for SQLite to support
                // reusing the same named placeholder multiple times in one
                // query (e.g. :viewer_id used 5 times in MatchingService).
                PDO::ATTR_EMULATE_PREPARES   => true,
            ]);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                config('db.host', '127.0.0.1'),
                (int) config('db.port', 3306),
                config('db.name', 'codealpha'),
                config('db.charset', 'utf8mb4')
            );
            $pdo = new PDO($dsn, (string) config('db.user', 'root'), (string) config('db.password', ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ]);
        }

        self::$pdo = $pdo;
        return $pdo;
    }

    public static function driver(): string
    {
        if (self::$driver === null) {
            self::pdo();
        }
        return self::$driver;
    }

    public static function isSqlite(): bool
    {
        return self::driver() === 'sqlite';
    }

    /** Quote an identifier for the active driver. */
    public static function quoteIdentifier(string $name): string
    {
        return self::isSqlite() ? '"' . $name . '"' : '`' . $name . '`';
    }

    /**
     * Portable SQL expression computing age in completed years from a
     * Y-m-d birthdate column.
     */
    public static function ageSql(string $column): string
    {
        if (self::isSqlite()) {
            return "(CAST(strftime('%Y', 'now') AS INTEGER) - CAST(strftime('%Y', $column) AS INTEGER)"
                . " - (strftime('%m-%d', 'now') < strftime('%m-%d', $column)))";
        }
        return "TIMESTAMPDIFF(YEAR, $column, CURDATE())";
    }

    /** Portable "now" expression. */
    public static function nowSql(): string
    {
        return self::isSqlite() ? "datetime('now')" : 'NOW()';
    }

    /** Run a statement and return the PDOStatement. */
    public static function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** Fetch a single row (or null). */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** Fetch all rows. */
    public static function all(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /** Fetch a single scalar value. */
    public static function scalar(string $sql, array $params = [])
    {
        $value = self::query($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** Insert a row and return the new id (or true for composite-key tables). */
    public static function insert(string $table, array $data): string
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn ($c) => ':' . $c, $columns);
        $sql = 'INSERT INTO ' . self::quoteIdentifier($table)
            . ' (' . implode(', ', array_map([self::class, 'quoteIdentifier'], $columns)) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')';
        self::query($sql, $data);
        return (string) self::pdo()->lastInsertId();
    }

    /** Update rows; returns affected row count. */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $assignments = [];
        foreach (array_keys($data) as $column) {
            $assignments[] = self::quoteIdentifier($column) . ' = :' . $column;
        }
        $sql = 'UPDATE ' . self::quoteIdentifier($table) . ' SET ' . implode(', ', $assignments) . ' WHERE ' . $where;
        return self::query($sql, array_merge($data, $whereParams))->rowCount();
    }

    /** Delete rows; returns affected row count. */
    public static function delete(string $table, string $where, array $whereParams = []): int
    {
        return self::query('DELETE FROM ' . self::quoteIdentifier($table) . ' WHERE ' . $where, $whereParams)->rowCount();
    }

    /** Run a callback inside a transaction. */
    public static function transaction(callable $callback)
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $callback();
        }
        $pdo->beginTransaction();
        try {
            $result = $callback();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Test the connection. */
    public static function ping(): bool
    {
        try {
            self::pdo()->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
}
