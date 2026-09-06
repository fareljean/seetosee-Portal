<?php
declare(strict_types=1);

namespace SeeToSee;

use PDO;

final class RateLimiter
{
    public static function assertAllowed(string $action, string $identifier, int $limit, int $windowSeconds): void
    {
        $pdo = Database::connection();
        $identifierHash = hash_hmac('sha256', strtolower(trim($identifier)), Env::require('APP_KEY'));
        $ipHash = hash_hmac('sha256', Http::clientIp(), Env::require('APP_KEY'));
        $statement = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE action = ? AND created_at >= (UTC_TIMESTAMP() - INTERVAL ? SECOND) AND (identifier_hash = ? OR ip_hash = ?)');
        $statement->execute([$action, $windowSeconds, $identifierHash, $ipHash]);
        if ((int) $statement->fetchColumn() >= $limit) {
            throw new ApiException(429, 'rate_limited', 'Too many attempts. Please wait and try again.');
        }
    }

    public static function record(string $action, string $identifier, bool $success): void
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare('INSERT INTO login_attempts (action, identifier_hash, ip_hash, succeeded, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())');
        $statement->execute([
            $action,
            hash_hmac('sha256', strtolower(trim($identifier)), Env::require('APP_KEY')),
            hash_hmac('sha256', Http::clientIp(), Env::require('APP_KEY')),
            $success ? 1 : 0,
        ]);
        if (random_int(1, 100) === 1) {
            $pdo->exec('DELETE FROM login_attempts WHERE created_at < (UTC_TIMESTAMP() - INTERVAL 30 DAY)');
        }
    }
}

