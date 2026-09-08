<?php
declare(strict_types=1);

namespace SeeToSee;

use PDO;
use PDOException;

/**
 * One-time tryout pack credits (tryout.pack.3 → +3).
 * Subscription access uses entitlement tryout.active via CommerceService::syncSubscription.
 */
final class TryoutCredits
{
    public const PACK_PRODUCT_ID = 'tryout.pack.3';
    public const PACK_CREDIT_AMOUNT = 3;

    public static function balance(int $userId): int
    {
        try {
            $statement = Database::connection()->prepare('SELECT balance FROM tryout_credits WHERE user_id = ? LIMIT 1');
            $statement->execute([$userId]);
            $balance = $statement->fetchColumn();
            return $balance === false ? 0 : max(0, (int) $balance);
        } catch (PDOException $exception) {
            // Table missing until sql/tryout_paywall.sql is applied.
            error_log('tryout_credits balance read failed: ' . $exception->getMessage());
            return 0;
        }
    }

    public static function grantFromPaidOrder(int $userId, string $orderPublicId, string $productId): void
    {
        if ($productId !== self::PACK_PRODUCT_ID) {
            return;
        }
        self::grant($userId, self::PACK_CREDIT_AMOUNT, 'commerce_order', $orderPublicId);
    }

    public static function grant(int $userId, int $amount, string $sourceType, string $sourceId): void
    {
        if ($userId <= 0 || $amount <= 0 || $sourceType === '' || $sourceId === '') {
            return;
        }
        try {
            Database::transaction(function (PDO $pdo) use ($userId, $amount, $sourceType, $sourceId): void {
                try {
                    $pdo->prepare('INSERT INTO tryout_credit_grants (user_id, amount, source_type, source_id, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6))')
                        ->execute([$userId, $amount, $sourceType, $sourceId]);
                } catch (PDOException $exception) {
                    if ((string) $exception->getCode() === '23000') {
                        return; // already granted for this source
                    }
                    throw $exception;
                }
                $pdo->prepare('INSERT INTO tryout_credits (user_id, balance, updated_at) VALUES (?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE balance = balance + VALUES(balance), updated_at = UTC_TIMESTAMP(6)')
                    ->execute([$userId, $amount]);
            });
            Audit::log($userId, 'tryout.credits_granted', [
                'amount' => $amount,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ]);
        } catch (PDOException $exception) {
            error_log('tryout_credits grant failed: ' . $exception->getMessage());
            throw new ApiException(500, 'tryout_credits_unavailable', 'Tryout credits storage is not ready. Apply sql/tryout_paywall.sql.');
        }
    }

    public static function hasAccess(int $userId): bool
    {
        if (EntitlementService::hasActive($userId, 'tryout.active')) {
            return true;
        }
        return self::balance($userId) > 0;
    }
}
