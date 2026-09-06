<?php
declare(strict_types=1);

namespace SeeToSee;

use PDO;
use PDOException;

final class PrefixService
{
    public static function normalizeHandle(string $raw): string
    {
        $raw = trim($raw);
        $withoutSpaces = preg_replace('/\s+/u', '', $raw);
        if (!is_string($withoutSpaces) || strlen($withoutSpaces) < 2 || strlen($withoutSpaces) > 32) {
            throw new ApiException(422, 'invalid_prefix', 'PreFix names must contain 2 to 32 characters.');
        }
        $handle = strtolower($withoutSpaces);
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,30}[a-z0-9])?$/', $handle)) {
            throw new ApiException(422, 'invalid_prefix', 'Use letters, numbers, or a single hyphen between characters.');
        }
        if (str_contains($handle, '--')) {
            throw new ApiException(422, 'invalid_prefix', 'Repeated hyphens are not allowed.');
        }
        return $handle;
    }

    public static function normalizeSuffix(string $raw): string
    {
        $suffix = strtolower(ltrim(trim($raw), '.'));
        $allowed = array_map('strtolower', Env::csv('PREFIX_SUFFIXES', ['iam', 'live', 'sells']));
        if (!in_array($suffix, $allowed, true)) {
            throw new ApiException(422, 'invalid_suffix', 'Choose a supported PreFix ending.');
        }
        return $suffix;
    }

    public static function availability(string $handleRaw, ?string $suffixRaw = null): array
    {
        $handle = self::normalizeHandle($handleRaw);
        $suffixes = $suffixRaw !== null && trim($suffixRaw) !== ''
            ? [self::normalizeSuffix($suffixRaw)]
            : array_map('strtolower', Env::csv('PREFIX_SUFFIXES', ['iam', 'live', 'sells']));
        $identities = array_map(static fn(string $suffix): string => $handle . '.' . $suffix, $suffixes);
        $placeholders = implode(',', array_fill(0, count($identities), '?'));
        $statement = Database::connection()->prepare("SELECT p.normalized_identity
            FROM prefix_identities p
            WHERE p.normalized_identity IN ($placeholders)
              AND (
                p.reserved_until > UTC_TIMESTAMP(6)
                OR EXISTS (
                    SELECT 1 FROM entitlements e
                    WHERE e.user_id = p.user_id AND e.entitlement_key = 'prefix.active'
                      AND e.status = 'active' AND (e.ends_at IS NULL OR e.ends_at > UTC_TIMESTAMP(6))
                )
              )");
        $statement->execute($identities);
        $taken = array_flip(array_column($statement->fetchAll(), 'normalized_identity'));
        $results = [];
        foreach ($suffixes as $index => $suffix) {
            $results[] = ['handle' => $handle, 'suffix' => $suffix, 'identity' => $identities[$index], 'available' => !isset($taken[$identities[$index]])];
        }
        return $results;
    }

    public static function claim(int $userId, array $input): array
    {
        $handle = self::normalizeHandle(Validation::string($input, 'handle', 2, 64));
        $suffix = self::normalizeSuffix(Validation::string($input, 'suffix', 2, 20));
        $destination = Security::safeDestination(Validation::string($input, 'destination_url', 8, 2048));
        $identity = $handle . '.' . $suffix;

        return Database::transaction(function (PDO $pdo) use ($userId, $handle, $suffix, $destination, $identity): array {
            $statement = $pdo->prepare("SELECT p.id, p.user_id, p.reserved_until,
                EXISTS(SELECT 1 FROM entitlements e WHERE e.user_id = p.user_id AND e.entitlement_key = 'prefix.active' AND e.status = 'active' AND (e.ends_at IS NULL OR e.ends_at > UTC_TIMESTAMP(6))) AS paid_active
                FROM prefix_identities p WHERE p.normalized_identity = ? FOR UPDATE");
            $statement->execute([$identity]);
            $existingIdentity = $statement->fetch();
            if (is_array($existingIdentity) && (int) $existingIdentity['user_id'] !== $userId) {
                $reserved = $existingIdentity['reserved_until'] !== null && strtotime((string) $existingIdentity['reserved_until']) > time();
                if ($reserved || (int) $existingIdentity['paid_active'] === 1) {
                    throw new ApiException(409, 'prefix_unavailable', 'That complete PreFix identity is already claimed.');
                }
                $pdo->prepare('DELETE FROM prefix_identities WHERE id = ?')->execute([$existingIdentity['id']]);
                Audit::log((int) $existingIdentity['user_id'], 'prefix.released_after_expiry', ['identity' => $identity]);
            }

            $current = $pdo->prepare("SELECT p.id, p.reserved_until,
                EXISTS(SELECT 1 FROM entitlements e WHERE e.user_id = p.user_id AND e.entitlement_key = 'prefix.active' AND e.status = 'active' AND (e.ends_at IS NULL OR e.ends_at > UTC_TIMESTAMP(6))) AS paid_active
                FROM prefix_identities p WHERE p.user_id = ? FOR UPDATE");
            $current->execute([$userId]);
            $currentRow = $current->fetch();
            if (is_array($currentRow)) {
                $reserved = $currentRow['reserved_until'] !== null && strtotime((string) $currentRow['reserved_until']) > time();
                $paid = (int) $currentRow['paid_active'] === 1;
                if (!$reserved && !$paid) {
                    throw new ApiException(409, 'prefix_reservation_expired', 'Your free PreFix reservation has expired. Activate PreFix for $12/month to continue it.');
                }
            }

            try {
                if (is_array($currentRow)) {
                    $status = (int) $currentRow['paid_active'] === 1 ? 'paid' : 'reserved';
                    $pdo->prepare('UPDATE prefix_identities SET handle = ?, suffix = ?, normalized_identity = ?, display_identity = ?, destination_url = ?, reservation_status = ?, approved_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6) WHERE id = ?')
                        ->execute([$handle, $suffix, $identity, $identity, $destination, $status, $currentRow['id']]);
                } else {
                    $paidNow = EntitlementService::hasActive($userId, 'prefix.active');
                    $pdo->prepare("INSERT INTO prefix_identities (user_id, handle, suffix, normalized_identity, display_identity, destination_url, approved_at, reservation_started_at, reserved_until, reservation_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) + INTERVAL 1 MONTH, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))")
                        ->execute([$userId, $handle, $suffix, $identity, $identity, $destination, $paidNow ? 'paid' : 'reserved']);
                }
            } catch (PDOException $exception) {
                if ((string) $exception->getCode() === '23000') {
                    throw new ApiException(409, 'prefix_unavailable', 'That complete PreFix identity is already claimed.');
                }
                throw $exception;
            }
            $pdo->prepare('UPDATE users SET destination_url = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?')->execute([$destination, $userId]);
            Audit::log($userId, 'prefix.claimed', ['identity' => $identity]);
            return self::statusForUser($userId);
        });
    }

    public static function statusForUser(int $userId): ?array
    {
        $statement = Database::connection()->prepare('SELECT handle, suffix, display_identity, destination_url, approved_at, reservation_started_at, reserved_until, reservation_status FROM prefix_identities WHERE user_id = ? LIMIT 1');
        $statement->execute([$userId]);
        $prefix = $statement->fetch();
        if (!is_array($prefix)) {
            return null;
        }
        $paid = EntitlementService::hasActive($userId, 'prefix.active');
        $reserved = $prefix['reserved_until'] !== null && strtotime((string) $prefix['reserved_until']) > time();
        return $prefix + [
            'paid' => $paid,
            'active' => $paid || $reserved,
            'status' => $paid ? 'paid' : ($reserved ? 'reserved' : 'expired'),
        ];
    }
}
