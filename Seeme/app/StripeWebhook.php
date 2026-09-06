<?php
declare(strict_types=1);

namespace SeeToSee;

use PDOException;

final class StripeWebhook
{
    public static function handle(string $payload, string $signatureHeader): array
    {
        self::verifySignature($payload, $signatureHeader);
        $event = json_decode($payload, true);
        if (!is_array($event) || !is_string($event['id'] ?? null) || !is_string($event['type'] ?? null)) {
            throw new ApiException(400, 'invalid_stripe_event', 'Stripe event is malformed.');
        }
        $eventId = $event['id'];
        try {
            Database::connection()->prepare('INSERT INTO stripe_webhook_events (stripe_event_id, event_type, livemode, processing_status, payload_json, received_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6))')->execute([$eventId, $event['type'], !empty($event['livemode']) ? 1 : 0, 'processing', $payload]);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }
            $statement = Database::connection()->prepare('SELECT processing_status FROM stripe_webhook_events WHERE stripe_event_id = ? LIMIT 1');
            $statement->execute([$eventId]);
            $status = (string) $statement->fetchColumn();
            if (self::duplicateDisposition($status) === 'duplicate') {
                return ['duplicate' => true, 'event_id' => $eventId];
            }
            Database::connection()->prepare('UPDATE stripe_webhook_events SET processing_status = ?, error_message = NULL WHERE stripe_event_id = ?')->execute(['processing', $eventId]);
        }

        try {
            self::process($event);
            Database::connection()->prepare('UPDATE stripe_webhook_events SET processing_status = ?, processed_at = UTC_TIMESTAMP(6), error_message = NULL WHERE stripe_event_id = ?')->execute(['processed', $eventId]);
        } catch (\Throwable $throwable) {
            Database::connection()->prepare('UPDATE stripe_webhook_events SET processing_status = ?, error_message = ? WHERE stripe_event_id = ?')->execute(['failed', substr($throwable->getMessage(), 0, 1000), $eventId]);
            throw $throwable;
        }
        return ['duplicate' => false, 'event_id' => $eventId];
    }

    public static function duplicateDisposition(string $existingStatus): string
    {
        return $existingStatus === 'failed' ? 'retry' : 'duplicate';
    }

    public static function verifySignature(string $payload, string $header): void
    {
        $parts = [];
        foreach (explode(',', $header) as $piece) {
            [$key, $value] = array_pad(explode('=', trim($piece), 2), 2, '');
            if ($key !== '' && $value !== '') {
                $parts[$key][] = $value;
            }
        }
        $timestamp = isset($parts['t'][0]) ? (int) $parts['t'][0] : 0;
        $signatures = $parts['v1'] ?? [];
        if ($timestamp <= 0 || abs(time() - $timestamp) > 300 || $signatures === []) {
            throw new ApiException(400, 'invalid_webhook_signature', 'Stripe signature is invalid.');
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, Env::require('STRIPE_WEBHOOK_SECRET'));
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return;
            }
        }
        throw new ApiException(400, 'invalid_webhook_signature', 'Stripe signature is invalid.');
    }

    private static function process(array $event): void
    {
        $type = (string) $event['type'];
        $object = $event['data']['object'] ?? null;
        if (!is_array($object)) {
            throw new ApiException(400, 'invalid_stripe_event', 'Stripe event object is missing.');
        }
        if ($type === 'checkout.session.completed' || $type === 'checkout.session.async_payment_succeeded') {
            self::checkoutCompleted($object);
            return;
        }
        if ($type === 'checkout.session.async_payment_failed') {
            if (($object['metadata']['purpose'] ?? '') === 'commerce') {
                CommerceService::checkoutFailedOrExpired($object, 'async_payment_failed');
            }
            return;
        }
        if ($type === 'checkout.session.expired') {
            CommerceService::checkoutFailedOrExpired($object, 'checkout_expired');
            return;
        }
        if (in_array($type, ['customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted'], true)) {
            self::subscriptionUpdated($object);
            return;
        }
        if ($type === 'invoice.payment_succeeded') {
            self::invoiceSucceeded($object);
            return;
        }
        if ($type === 'invoice.payment_failed') {
            self::invoiceFailed($object);
            return;
        }
    }

    private static function checkoutCompleted(array $object): void
    {
        $userId = self::userIdForObject($object);
        $customer = is_string($object['customer'] ?? null) ? $object['customer'] : null;
        if ($userId !== null && $customer !== null) {
            self::upsertCustomer($userId, $customer);
        }
        $purpose = (string) ($object['metadata']['purpose'] ?? '');
        if ($purpose === 'commerce') {
            CommerceService::checkoutCompleted($object);
        }
    }

    private static function subscriptionUpdated(array $object): void
    {
        $userId = self::userIdForObject($object);
        if ($userId === null || !is_string($object['id'] ?? null) || !is_string($object['customer'] ?? null)) {
            throw new ApiException(409, 'stripe_user_unresolved', 'Unable to associate Stripe subscription with a member.');
        }
        self::upsertCustomer($userId, $object['customer']);
        CommerceService::syncSubscription($object);
        $status = (string) ($object['status'] ?? 'incomplete');
        $priceId = self::subscriptionPriceId($object);
        $trialStart = self::dateValue($object['trial_start'] ?? null);
        $trialEnd = self::dateValue($object['trial_end'] ?? null);
        $periodStart = self::dateValue($object['current_period_start'] ?? ($object['items']['data'][0]['current_period_start'] ?? null));
        $periodEnd = self::dateValue($object['current_period_end'] ?? ($object['items']['data'][0]['current_period_end'] ?? null));
        $canceledAt = self::dateValue($object['canceled_at'] ?? null);
        $raw = json_encode($object, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        Database::connection()->prepare('INSERT INTO stripe_subscriptions (user_id, stripe_customer_id, stripe_subscription_id, stripe_price_id, status, trial_start, trial_end, current_period_start, current_period_end, cancel_at_period_end, canceled_at, raw_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), stripe_customer_id = VALUES(stripe_customer_id), stripe_price_id = VALUES(stripe_price_id), status = VALUES(status), trial_start = VALUES(trial_start), trial_end = VALUES(trial_end), current_period_start = VALUES(current_period_start), current_period_end = VALUES(current_period_end), cancel_at_period_end = VALUES(cancel_at_period_end), canceled_at = VALUES(canceled_at), raw_json = VALUES(raw_json), updated_at = UTC_TIMESTAMP(6)')->execute([$userId, $object['customer'], $object['id'], $priceId, $status, $trialStart, $trialEnd, $periodStart, $periodEnd, !empty($object['cancel_at_period_end']) ? 1 : 0, $canceledAt, $raw]);
        Audit::log($userId, 'stripe.subscription_updated', ['subscription_id' => $object['id'], 'status' => $status]);
    }

    private static function invoiceSucceeded(array $object): void
    {
        $userId = self::userIdForObject($object);
        if ($userId === null) {
            return;
        }
        Audit::log($userId, 'stripe.payment_succeeded', ['invoice_id' => $object['id'] ?? null]);
    }

    private static function invoiceFailed(array $object): void
    {
        $userId = self::userIdForObject($object);
        if ($userId === null) {
            return;
        }
        Audit::log($userId, 'stripe.payment_failed', ['invoice_id' => $object['id'] ?? null]);
    }

    private static function userIdForObject(array $object): ?int
    {
        $publicId = $object['metadata']['user_public_id'] ?? $object['client_reference_id'] ?? null;
        if (is_string($publicId) && $publicId !== '') {
            $statement = Database::connection()->prepare('SELECT id FROM users WHERE public_id = ? AND status <> ? LIMIT 1');
            $statement->execute([$publicId, 'deleted']);
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

    private static function upsertCustomer(int $userId, string $customer): void
    {
        Database::connection()->prepare('INSERT INTO stripe_customers (user_id, stripe_customer_id, created_at, updated_at) VALUES (?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE stripe_customer_id = VALUES(stripe_customer_id), updated_at = UTC_TIMESTAMP(6)')->execute([$userId, $customer]);
    }

    private static function dateValue(mixed $timestamp): ?string
    {
        return is_numeric($timestamp) && (int) $timestamp > 0 ? gmdate('Y-m-d H:i:s', (int) $timestamp) : null;
    }

    private static function subscriptionPriceId(array $object): ?string
    {
        $price = $object['items']['data'][0]['price']['id'] ?? null;
        return is_string($price) && $price !== '' ? $price : null;
    }
}
