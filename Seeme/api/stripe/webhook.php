<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';

use SeeToSee\ApiException;
use SeeToSee\Http;
use SeeToSee\StripeWebhook;

Http::run(static function (): void {
    Http::requireMethod('POST');
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > 2097152) {
        throw new ApiException(413, 'request_too_large', 'Webhook payload is too large.');
    }
    $payload = file_get_contents('php://input', false, null, 0, 2097153);
    if (!is_string($payload) || strlen($payload) > 2097152) {
        throw new ApiException(413, 'request_too_large', 'Webhook payload is too large.');
    }
    Http::success(StripeWebhook::handle($payload, Http::header('Stripe-Signature')));
});

