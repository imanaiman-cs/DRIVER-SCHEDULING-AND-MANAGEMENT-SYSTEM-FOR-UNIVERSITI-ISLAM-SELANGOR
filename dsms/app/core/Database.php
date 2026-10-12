<?php
/**
 * Database - one shared PDO connection.
 *
 * WHY PDO + prepared statements: user input is sent to MySQL separately
 * from the SQL text, so it can never be interpreted as SQL. That is the
 * defence against SQL injection, and the whole project uses it for EVERY query.
 */
class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo === null) {
            $cfg = require dirname(__DIR__) . '/config/database.php';
            $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']};charset={$cfg['charset']}";

            self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // errors become exceptions we can catch + log
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,                    // real prepared statements
            ]);
            // Keep MySQL's NOW()/CURDATE() in step with PHP (Malaysia time, UTC+8).
            self::$pdo->exec("SET time_zone = '+08:00'");
        }
        return self::$pdo;
    }
}
