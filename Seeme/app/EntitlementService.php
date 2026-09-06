<?php
declare(strict_types=1);

namespace SeeToSee;

final class EntitlementService
{
    public static function grant(int $userId, string $key, string $sourceType, string $sourceId, ?string $endsAt = null): void
    {
        if ($key === '' || $sourceType === '' || $sourceId === '') {
            throw new \InvalidArgumentException('Entitlement source is incomplete.');
        }
        Database::connection()->prepare('INSERT INTO entitlements (user_id, entitlement_key, source_type, source_id, status, starts_at, ends_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6), ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE status = VALUES(status), starts_at = LEAST(starts_at, VALUES(starts_at)), ends_at = VALUES(ends_at), updated_at = UTC_TIMESTAMP(6)')
            ->execute([$userId, $key, $sourceType, $sourceId, 'active', $endsAt]);
    }

    public static function deactivateSource(int $userId, string $sourceType, string $sourceId): void
    {
        Database::connection()->prepare("UPDATE entitlements SET status = 'inactive', updated_at = UTC_TIMESTAMP(6) WHERE user_id = ? AND source_type = ? AND source_id = ? AND status = 'active'")
            ->execute([$userId, $sourceType, $sourceId]);
    }

    public static function hasActive(int $userId, string $key): bool
    {
        $statement = Database::connection()->prepare("SELECT 1 FROM entitlements WHERE user_id = ? AND entitlement_key = ? AND status = 'active' AND (ends_at IS NULL OR ends_at > UTC_TIMESTAMP(6)) LIMIT 1");
        $statement->execute([$userId, $key]);
        return (bool) $statement->fetchColumn();
    }

    public static function activeForUser(int $userId): array
    {
        $statement = Database::connection()->prepare("SELECT entitlement_key, source_type, source_id, starts_at, ends_at FROM entitlements WHERE user_id = ? AND status = 'active' AND (ends_at IS NULL OR ends_at > UTC_TIMESTAMP(6)) ORDER BY entitlement_key, starts_at");
        $statement->execute([$userId]);
        return $statement->fetchAll();
    }
}
