<?php
declare(strict_types=1);

namespace SeeToSee;

final class StripeClient
{
    public static function commerceCheckout(array $member, string $orderPublicId, array $product, string $priceId): array
    {
        $mode = ($product['product_type'] ?? '') === 'subscription' ? 'subscription' : 'payment';
        $params = [
            'mode' => $mode,
            'success_url' => rtrim(Env::require('APP_URL'), '/') . '/dashboard/?purchase=success&order=' . rawurlencode($orderPublicId),
            'cancel_url' => rtrim(Env::require('APP_URL'), '/') . '/dashboard/?purchase=canceled&order=' . rawurlencode($orderPublicId),
            'client_reference_id' => (string) $member['public_id'],
            'line_items[0][price]' => $priceId,
            'line_items[0][quantity]' => '1',
            'metadata[purpose]' => 'commerce',
            'metadata[user_public_id]' => (string) $member['public_id'],
            'metadata[order_public_id]' => $orderPublicId,
            'metadata[product_id]' => (string) $product['product_id'],
        ];
        $customer = $member['stripe']['stripe_customer_id'] ?? null;
        if (is_string($customer) && $customer !== '') {
            $params['customer'] = $customer;
        } else {
            $params['customer_email'] = (string) $member['email'];
        }
        if ($mode === 'subscription') {
            $params['subscription_data[metadata][user_public_id]'] = (string) $member['public_id'];
            $params['subscription_data[metadata][order_public_id]'] = $orderPublicId;
            $params['subscription_data[metadata][product_id]'] = (string) $product['product_id'];
        }
        if (Env::get('APP_ENV', 'production') === 'testing' && trim((string) Env::get('STRIPE_TEST_FIXTURE_DIR', '')) !== '') {
            $sessionId = 'cs_test_' . strtolower($orderPublicId);
            return ['checkout_url' => 'https://checkout.stripe.test/' . rawurlencode($sessionId), 'checkout_session_id' => $sessionId];
        }
        $session = self::request('POST', '/v1/checkout/sessions', $params, 'commerce-' . strtolower($orderPublicId));
        if (!isset($session['url'], $session['id']) || !is_string($session['url']) || !is_string($session['id'])) {
            throw new ApiException(502, 'stripe_response_invalid', 'Stripe did not return a checkout URL.');
        }
        return ['checkout_url' => $session['url'], 'checkout_session_id' => $session['id']];
    }

    public static function retrieveCheckoutSession(string $sessionId): array
    {
        if (!preg_match('/^cs_[A-Za-z0-9_]+$/', $sessionId)) {
            throw new ApiException(400, 'stripe_session_invalid', 'Stripe checkout session is invalid.');
        }
        if (Env::get('APP_ENV', 'production') === 'testing') {
            $fixtureDir = trim((string) Env::get('STRIPE_TEST_FIXTURE_DIR', ''));
            if ($fixtureDir !== '') {
                $path = rtrim($fixtureDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $sessionId . '.json';
                if (!is_file($path) || !is_readable($path)) {
                    throw new ApiException(500, 'stripe_test_fixture_missing', 'Stripe test fixture is missing.');
                }
                $decoded = json_decode((string) file_get_contents($path), true);
                if (!is_array($decoded)) {
                    throw new ApiException(500, 'stripe_test_fixture_invalid', 'Stripe test fixture is invalid.');
                }
                return $decoded;
            }
        }
        return self::request('GET', '/v1/checkout/sessions/' . rawurlencode($sessionId), [
            'expand' => ['line_items.data.price'],
        ]);
    }

    public static function customerPortal(int $userId): array
    {
        $statement = Database::connection()->prepare('SELECT stripe_customer_id FROM stripe_customers WHERE user_id = ? LIMIT 1');
        $statement->execute([$userId]);
        $customer = $statement->fetchColumn();
        if (!is_string($customer) || $customer === '') {
            throw new ApiException(409, 'stripe_customer_missing', 'No Stripe billing profile exists for this account yet.');
        }
        $portal = self::request('POST', '/v1/billing_portal/sessions', [
            'customer' => $customer,
            'return_url' => rtrim(Env::require('APP_URL'), '/') . '/dashboard/#billing',
        ], 'portal-' . $userId . '-' . bin2hex(random_bytes(6)));
        if (!isset($portal['url'])) {
            throw new ApiException(502, 'stripe_response_invalid', 'Stripe did not return a portal URL.');
        }
        Audit::log($userId, 'stripe.portal_created');
        return ['portal_url' => $portal['url']];
    }

    public static function request(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array
    {
        if (!function_exists('curl_init')) {
            throw new ApiException(500, 'curl_required', 'The PHP cURL extension is required for Stripe.');
        }
        $url = 'https://api.stripe.com' . $path;
        $curl = curl_init();
        $headers = ['Authorization: Bearer ' . Env::require('STRIPE_SECRET_KEY'), 'Content-Type: application/x-www-form-urlencoded'];
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        $method = strtoupper($method);
        if ($method === 'GET' && $params !== []) {
            $url .= '?' . http_build_query($params);
        }
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);
        if ($method !== 'GET') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($params));
        }
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if (!is_string($body)) {
            error_log('Stripe transport error: ' . $error);
            throw new ApiException(502, 'stripe_unavailable', 'Stripe is temporarily unavailable.');
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || $status < 200 || $status >= 300) {
            $stripeMessage = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';
            error_log('Stripe API error HTTP ' . $status . ': ' . substr($stripeMessage, 0, 300));
            throw new ApiException(502, 'stripe_error', 'Stripe could not complete the request.');
        }
        return $decoded;
    }
}

