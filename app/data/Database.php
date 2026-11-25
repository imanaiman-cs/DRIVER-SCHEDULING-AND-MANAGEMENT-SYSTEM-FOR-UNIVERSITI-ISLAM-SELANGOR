<?php
// ============================================================
// SDD_CLASS_301 - Database (Data Access Layer, SDD_PKG_300)
// Opens and keeps the connection to dsms_db and runs prepared statements.
// Every query of the system goes through this class: user input is always bound, never put in the SQL.
// ============================================================

class Database
{
    /** @var PDO|null Open connection to MySQL */
    private static ?PDO $connection = null;

    /** Returns the open connection (opens it the first time). */
    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            self::$connection = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            self::$connection->exec("SET time_zone = '+08:00'");   // NOW() follows Malaysia time, like PHP
        }
        return self::$connection;
    }

    /** Runs a SELECT with bound parameters and returns all rows. */
    public static function query(string $sql, array $params = []): array
    {
        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute(array_values($params));
        return $stmt->fetchAll();
    }

    /** Runs a SELECT and returns the first row, or null. */
    public static function queryOne(string $sql, array $params = []): ?array
    {
        $rows = self::query($sql, $params);
        return $rows[0] ?? null;
    }

    /** Runs a SELECT and returns the first column of the first row, or null. */
    public static function value(string $sql, array $params = [])
    {
        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute(array_values($params));
        $v = $stmt->fetchColumn();
        return $v === false ? null : $v;
    }

    /** Runs an INSERT, UPDATE or DELETE with bound parameters and returns the number of rows changed. */
    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute(array_values($params));
        return $stmt->rowCount();
    }

    /** ID of the last inserted row. */
    public static function lastInsertId(): int
    {
        return (int)self::getConnection()->lastInsertId();
    }

    /** Starts a transaction (nested calls join the open one). */
    public static function beginTransaction(): void
    {
        if (!self::getConnection()->inTransaction()) { self::getConnection()->beginTransaction(); }
    }

    /** Saves the transaction. */
    public static function commit(): void
    {
        if (self::getConnection()->inTransaction()) { self::getConnection()->commit(); }
    }

    /** Cancels the transaction. */
    public static function rollback(): void
    {
        if (self::getConnection()->inTransaction()) { self::getConnection()->rollBack(); }
    }

    /** "?, ?, ?" for an IN (...) list. */
    public static function placeholders(array $values): string
    {
        return implode(',', array_fill(0, max(1, count($values)), '?'));
    }
}
