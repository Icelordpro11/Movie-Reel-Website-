<?php
require_once __DIR__ . '/Config.php';

 //Owns the single shared PDO connection to MySQL. Everything that touches the database asks for Database::connection() rather than opening its own handle.
final class Database
{
    private static ?PDO $pdo = null;

    // Get (or open) the shared PDO connection. 
    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $cfg = Config::db();
        $dsn = $cfg['socket'] !== ''
            ? "mysql:unix_socket={$cfg['socket']};dbname={$cfg['name']};charset={$cfg['charset']}"
            : "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']};charset={$cfg['charset']}";

        self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return self::$pdo;
    }

    // Prepare + execute a statement in one call, returning the statement.
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}
