<?php
declare(strict_types=1);

namespace SeeToSee;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $host = Env::require('DB_HOST');
        $port = Env::int('DB_PORT', 3306);
        $name = Env::require('DB_NAME');
        $charset = Env::get('DB_CHARSET', 'utf8mb4');
        if (!preg_match('/^[a-zA-Z0-9_]+$/', (string) $charset)) {
            throw new RuntimeException('Invalid database charset.');
        }
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);
        try {
            self::$connection = new PDO($dsn, Env::require('DB_USER'), Env::require('DB_PASSWORD'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            self::$connection->exec("SET time_zone = '+00:00'");
            return self::$connection;
        } catch (PDOException $exception) {
            error_log('Database connection failed: ' . $exception->getCode());
            throw new RuntimeException('Database is unavailable.');
        }
    }

    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();
        $pdo->beginTransaction();
        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $throwable;
        }
    }
}

