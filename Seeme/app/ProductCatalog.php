<?php
declare(strict_types=1);

namespace SeeToSee;

final class ProductCatalog
{
    public static function find(string $productId, bool $requireActive = true): array
    {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,127}$/', $productId)) {
            throw new ApiException(422, 'invalid_product', 'Choose a valid product.');
        }
        $sql = 'SELECT * FROM product_catalog WHERE product_id = ?' . ($requireActive ? " AND status = 'active'" : '') . ' LIMIT 1';
        $statement = Database::connection()->prepare($sql);
        $statement->execute([$productId]);
        $product = $statement->fetch();
        if (!is_array($product)) {
            throw new ApiException(404, 'product_unavailable', 'That product is not currently available.');
        }
        return self::normalize($product);
    }

    public static function stripePriceId(array $product): string
    {
        $price = '';
        $envKey = trim((string) ($product['stripe_price_env_key'] ?? ''));
        if ($envKey !== '') {
            $price = trim((string) Env::get($envKey, ''));
        }
        if ($price === '') {
            $price = trim((string) ($product['stripe_price_id'] ?? ''));
        }
        if ($price === '' || stripos($price, 'replace') !== false || !str_starts_with($price, 'price_')) {
            throw new ApiException(503, 'product_not_configured', 'This product is not ready for checkout yet.');
        }
        return $price;
    }

    public static function findByStripePrice(string $priceId): ?array
    {
        if ($priceId === '') {
            return null;
        }
        $statement = Database::connection()->query("SELECT * FROM product_catalog WHERE status IN ('active','inactive') ORDER BY display_order, product_id");
        foreach ($statement->fetchAll() as $row) {
            $product = self::normalize($row);
            try {
                if (hash_equals(self::stripePriceId($product), $priceId)) {
                    return $product;
                }
            } catch (ApiException) {
                continue;
            }
        }
        return null;
    }

    public static function publicCatalog(): array
    {
        $statement = Database::connection()->query("SELECT * FROM product_catalog p WHERE p.status = 'active' ORDER BY p.display_order, p.product_id");
        $products = [];
        foreach ($statement->fetchAll() as $row) {
            $product = self::normalize($row);
            $products[] = [
                'product_id' => $product['product_id'],
                'name' => $product['name'],
                'product_type' => $product['product_type'],
                'amount_cents' => $product['amount_cents'],
                'currency' => $product['currency'],
                'billing_interval' => $product['billing_interval'],
                'entitlement_key' => $product['entitlement_key'],
                'checkout_configured' => self::checkoutConfigured($product),
            ];
        }
        return $products;
    }

    public static function checkoutConfigured(array $product): bool
    {
        try {
            self::stripePriceId($product);
            return true;
        } catch (ApiException) {
            return false;
        }
    }

    private static function normalize(array $product): array
    {
        $product['amount_cents'] = (int) $product['amount_cents'];
        $product['display_order'] = (int) ($product['display_order'] ?? 0);
        $product['currency'] = strtolower((string) $product['currency']);
        return $product;
    }
}
