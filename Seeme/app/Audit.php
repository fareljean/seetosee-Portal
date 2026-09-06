<?php
declare(strict_types=1);

namespace SeeToSee;

final class Audit
{
    public static function log(?int $userId, string $event, array $metadata = []): void
    {
        $statement = Database::connection()->prepare('INSERT INTO audit_events (user_id, event_type, ip_hash, request_id, metadata_json, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())');
        $statement->execute([
            $userId,
            $event,
            hash_hmac('sha256', Http::clientIp(), Env::require('APP_KEY')),
            Http::requestId(),
            $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
    }
}

