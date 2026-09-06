<?php
declare(strict_types=1);

namespace SeeToSee;

use PDO;

final class CommerceService
{
    public static function createCheckout(int $userId, string $productId): array
    {
        $member = Auth::memberData($userId);
        if ($productId === 'subscription.prefix.monthly' && EntitlementService::hasActive($userId, 'prefix.active')) {
            throw new ApiException(409, 'already_subscribed', 'You already have an active PreFix subscription.');
        }
        $order = Database::transaction(function (PDO $pdo) use ($userId, $productId): array {
            $productStatement = $pdo->prepare("SELECT * FROM product_catalog WHERE product_id = ? AND status = 'active' FOR UPDATE");
            $productStatement->execute([$productId]);
            $product = $productStatement->fetch();
            if (!is_array($product)) {
                throw new ApiException(404, 'product_unavailable', 'That product is not currently available.');
            }
            $product['amount_cents'] = (int) $product['amount_cents'];
            $priceId = ProductCatalog::stripePriceId($product);

            $publicId = strtoupper(bin2hex(random_bytes(13)));
            $pdo->prepare("INSERT INTO commerce_orders (public_id, user_id, product_id, expected_stripe_price_id, expected_amount_cents, expected_currency, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))")
                ->execute([$publicId, $userId, $productId, $priceId, $product['amount_cents'], strtolower((string) $product['currency'])]);
            $orderId = (int) $pdo->lastInsertId();
            return ['id' => $orderId, 'public_id' => $publicId, 'product' => $product, 'price_id' => $priceId];
        });

        try {
            $session = StripeClient::commerceCheckout($member, $order['public_id'], $order['product'], $order['price_id']);
            Database::connection()->prepare('UPDATE commerce_orders SET stripe_checkout_session_id = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ? AND status = ?')
                ->execute([$session['checkout_session_id'], $order['id'], 'pending']);
            Audit::log($userId, 'commerce.checkout_created', ['order_public_id' => $order['public_id'], 'product_id' => $productId, 'session_id' => $session['checkout_session_id']]);
            return $session + ['order_public_id' => $order['public_id'], 'product_id' => $productId];
        } catch (\Throwable $throwable) {
            self::cancelOrder((int) $order['id'], 'checkout_creation_failed');
            throw $throwable;
        }
    }

    public static function checkoutCompleted(array $eventSession): void
    {
        $sessionId = is_string($eventSession['id'] ?? null) ? $eventSession['id'] : '';
        $orderPublicId = (string) ($eventSession['metadata']['order_public_id'] ?? '');
        if ($sessionId === '' || $orderPublicId === '' || ($eventSession['metadata']['purpose'] ?? '') !== 'commerce') {
            return;
        }
        $session = StripeClient::retrieveCheckoutSession($sessionId);
        $line = $session['line_items']['data'][0] ?? null;
        $priceId = is_array($line) ? (string) ($line['price']['id'] ?? '') : '';
        $amount = (int) ($session['amount_total'] ?? -1);
        $currency = strtolower((string) ($session['currency'] ?? ''));
        $paymentStatus = (string) ($session['payment_status'] ?? '');
        $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
        $rawJson = json_encode($session, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        Database::transaction(function (PDO $pdo) use ($session, $sessionId, $orderPublicId, $priceId, $amount, $currency, $paymentStatus, $metadata, $rawJson): void {
            $orderStatement = $pdo->prepare('SELECT o.* FROM commerce_orders o WHERE o.public_id = ? FOR UPDATE');
            $orderStatement->execute([$orderPublicId]);
            $order = $orderStatement->fetch();
            if (!is_array($order)) {
                throw new ApiException(409, 'commerce_order_missing', 'Stripe checkout could not be matched to an order.');
            }
            if ($order['status'] === 'paid') {
                return;
            }
            if ($order['status'] !== 'pending') {
                throw new ApiException(409, 'commerce_order_not_pending', 'This order is no longer payable.');
            }
            $memberStatement = $pdo->prepare('SELECT public_id FROM users WHERE id = ? LIMIT 1');
            $memberStatement->execute([$order['user_id']]);
            $publicId = (string) $memberStatement->fetchColumn();
            $valid = hash_equals((string) $order['stripe_checkout_session_id'], $sessionId)
                && hash_equals((string) $order['expected_stripe_price_id'], $priceId)
                && (int) $order['expected_amount_cents'] === $amount
                && hash_equals(strtolower((string) $order['expected_currency']), $currency)
                && $paymentStatus === 'paid'
                && hash_equals((string) $order['product_id'], (string) ($metadata['product_id'] ?? ''))
                && hash_equals($orderPublicId, (string) ($metadata['order_public_id'] ?? ''))
                && hash_equals($publicId, (string) ($metadata['user_public_id'] ?? ''));
            if (!$valid) {
                throw new ApiException(409, 'commerce_payment_mismatch', 'Stripe payment details did not match the server order.');
            }

            $paymentIntent = is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : null;
            $subscriptionId = is_string($session['subscription'] ?? null) ? $session['subscription'] : null;
            $pdo->prepare("UPDATE commerce_orders SET status = 'paid', stripe_payment_intent_id = ?, stripe_subscription_id = ?, paid_at = UTC_TIMESTAMP(6), raw_json = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?")
                ->execute([$paymentIntent, $subscriptionId, $rawJson, $order['id']]);
        });

        Audit::log(null, 'commerce.payment_confirmed', ['order_public_id' => $orderPublicId, 'session_id' => $sessionId]);
    }

    public static function checkoutFailedOrExpired(array $session, string $reason): void
    {
        $sessionId = is_string($session['id'] ?? null) ? $session['id'] : '';
        if ($sessionId === '' || ($session['metadata']['purpose'] ?? '') !== 'commerce') {
            return;
        }
        $statement = Database::connection()->prepare('SELECT id FROM commerce_orders WHERE stripe_checkout_session_id = ? LIMIT 1');
        $statement->execute([$sessionId]);
        $orderId = $statement->fetchColumn();
        if ($orderId) {
            self::cancelOrder((int) $orderId, $reason);
        }
    }

    public static function syncSubscription(array $subscription): bool
    {
        $priceObject = $subscription['items']['data'][0]['price'] ?? [];
        $priceId = is_array($priceObject) ? (string) ($priceObject['id'] ?? '') : '';
        $product = ProductCatalog::findByStripePrice($priceId);
        if (!is_array($product) || $product['product_type'] !== 'subscription' || empty($product['entitlement_key'])) {
            return false;
        }
        $metadataProduct = (string) ($subscription['metadata']['product_id'] ?? '');
        if ($metadataProduct === '' || !hash_equals((string) $product['product_id'], $metadataProduct)) {
            return false;
        }
        if (is_numeric($priceObject['unit_amount'] ?? null) && (int) $priceObject['unit_amount'] !== (int) $product['amount_cents']) {
            throw new ApiException(409, 'commerce_subscription_amount_mismatch', 'Stripe subscription amount did not match the server product.');
        }
        $priceCurrency = strtolower((string) ($priceObject['currency'] ?? $product['currency']));
        if (!hash_equals((string) $product['currency'], $priceCurrency)) {
            throw new ApiException(409, 'commerce_subscription_currency_mismatch', 'Stripe subscription currency did not match the server product.');
        }
        $userId = self::userIdForStripeObject($subscription);
        $subscriptionId = is_string($subscription['id'] ?? null) ? $subscription['id'] : '';
        if ($userId === null || $subscriptionId === '') {
            throw new ApiException(409, 'stripe_user_unresolved', 'Unable to associate Stripe subscription with a member.');
        }
        $status = (string) ($subscription['status'] ?? 'incomplete');
        $periodEndTs = $subscription['current_period_end'] ?? ($subscription['items']['data'][0]['current_period_end'] ?? null);
        $periodEnd = is_numeric($periodEndTs) && (int) $periodEndTs > 0 ? gmdate('Y-m-d H:i:s', (int) $periodEndTs) : null;
        if (in_array($status, ['active', 'trialing'], true)) {
            EntitlementService::grant($userId, (string) $product['entitlement_key'], 'stripe_subscription', $subscriptionId, $periodEnd);
        } else {
            EntitlementService::deactivateSource($userId, 'stripe_subscription', $subscriptionId);
        }
        if ($product['product_id'] === 'subscription.prefix.monthly') {
            Database::connection()->prepare("UPDATE prefix_identities SET reservation_status = ?, updated_at = UTC_TIMESTAMP(6) WHERE user_id = ?")
                ->execute([in_array($status, ['active', 'trialing'], true) ? 'paid' : 'reserved', $userId]);
        }
        $orderPublicId = (string) ($subscription['metadata']['order_public_id'] ?? '');
        if ($orderPublicId !== '') {
            Database::connection()->prepare('UPDATE commerce_orders SET stripe_subscription_id = ?, updated_at = UTC_TIMESTAMP(6) WHERE public_id = ? AND user_id = ? AND product_id = ?')
                ->execute([$subscriptionId, $orderPublicId, $userId, $product['product_id']]);
        }
        Audit::log($userId, 'commerce.subscription_synced', ['product_id' => $product['product_id'], 'subscription_id' => $subscriptionId, 'status' => $status]);
        return true;
    }

    public static function status(int $userId): array
    {
        $orders = Database::connection()->prepare('SELECT public_id, product_id, expected_amount_cents AS amount_cents, expected_currency AS currency, status, paid_at, created_at FROM commerce_orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 100');
        $orders->execute([$userId]);
        return [
            'prefix' => PrefixService::statusForUser($userId),
            'entitlements' => EntitlementService::activeForUser($userId),
            'products' => ProductCatalog::publicCatalog(),
            'orders' => $orders->fetchAll(),
        ];
    }

    private static function cancelOrder(int $orderId, string $reason): void
    {
        Database::transaction(function (PDO $pdo) use ($orderId, $reason): void {
            $statement = $pdo->prepare('SELECT id, user_id, status FROM commerce_orders WHERE id = ? FOR UPDATE');
            $statement->execute([$orderId]);
            $order = $statement->fetch();
            if (!is_array($order) || $order['status'] !== 'pending') {
                return;
            }
            $pdo->prepare("UPDATE commerce_orders SET status = 'canceled', canceled_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6) WHERE id = ?")->execute([$orderId]);
            Audit::log((int) $order['user_id'], 'commerce.order_canceled', ['order_id' => $orderId, 'reason' => $reason]);
        });
    }

    private static function userIdForStripeObject(array $object): ?int
    {
        $publicId = $object['metadata']['user_public_id'] ?? null;
        if (is_string($publicId) && $publicId !== '') {
            $statement = Database::connection()->prepare("SELECT id FROM users WHERE public_id = ? AND status <> 'deleted' LIMIT 1");
            $statement->execute([$publicId]);
            $id = $statement->fetchColumn();
            if ($id) {
                return (int) $id;
            }
        }
        $customer = $object['customer'] ?? null;
        if (is_string($customer) && $customer !== '') {
            $statement = Database::connection()->prepare('SELECT user_id FROM stripe_customers WHERE stripe_customer_id = ? LIMIT 1');
            $statement->execute([$customer]);
            $id = $statement->fetchColumn();
            return $id ? (int) $id : null;
        }
        return null;
    }
}
