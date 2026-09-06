<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';

use SeeToSee\ApiException;
use SeeToSee\Audit;
use SeeToSee\Auth;
use SeeToSee\Database;
use SeeToSee\EntitlementService;
use SeeToSee\Env;
use SeeToSee\Http;
use SeeToSee\Security;
use SeeToSee\Validation;

// Admission-control pass issuer for the FJN booth rooms.
// Does not touch TURN/ICE/signaling logic. Only mints a short-lived,
// HMAC-signed pass after confirming the signed-in member is entitled.
Http::run(static function (): void {
    Http::requireMethod('POST');

    $session = Auth::currentSession();
    if ($session === null) {
        throw new ApiException(401, 'unauthorized', 'Sign in required.');
    }
    Security::ensureCsrf($session);

    $userId = (int) $session['user_id'];

    $input = Http::jsonInput(4096);
    $booth = Validation::string($input, 'booth', 3, 8);
    if (!in_array($booth, ['ipv1', 'ipv2'], true)) {
        throw new ApiException(422, 'validation_failed', 'Unknown booth requested.', ['booth' => 'Must be ipv1 or ipv2.']);
    }
    $seats = $booth === 'ipv1' ? 2 : 4;

    $product = null;
    $orderId = null;

    if (EntitlementService::hasActive($userId, 'portal.active')) {
        $product = 'portal.active';
    } else {
        // Optional fallback: a paid, not-yet-used Personal Website Consultation
        // purchase also grants a single booth pass. "Unused" is tracked via
        // audit_events (no schema change) rather than a new orders column.
        $pdo = Database::connection();
        $ordersStatement = $pdo->prepare(
            "SELECT id FROM commerce_orders WHERE user_id = ? AND product_id = 'consultation.personal' AND status = 'paid' ORDER BY paid_at DESC"
        );
        $ordersStatement->execute([$userId]);
        foreach ($ordersStatement->fetchAll() as $orderRow) {
            $candidateId = (int) $orderRow['id'];
            $usedStatement = $pdo->prepare(
                "SELECT id FROM audit_events WHERE event_type = 'booth.consultation_pass_issued' AND JSON_EXTRACT(metadata_json, '$.order_id') = ? LIMIT 1"
            );
            $usedStatement->execute([$candidateId]);
            if ($usedStatement->fetch() === false) {
                $product = 'consultation.personal';
                $orderId = $candidateId;
                break;
            }
        }
    }

    if ($product === null) {
        throw new ApiException(403, 'not_entitled', 'No active booth access for this account.');
    }

    $secret = Env::require('BOOTH_SECRET');

    $now = time();
    $exp = $now + 2700; // 45 minutes
    $jti = bin2hex(random_bytes(16));

    $payload = [
        'sub' => $userId,
        'product' => $product,
        'booth' => $booth,
        'seats' => $seats,
        'exp' => $exp,
        'jti' => $jti,
    ];

    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $payloadB64 = rtrim(strtr(base64_encode((string) $payloadJson), '+/', '-_'), '=');
    $signature = hash_hmac('sha256', $payloadB64, $secret);
    $pass = $payloadB64 . '.' . $signature;

    if ($orderId !== null) {
        Audit::log($userId, 'booth.consultation_pass_issued', ['order_id' => $orderId, 'booth' => $booth, 'jti' => $jti]);
    }

    Http::success([
        'pass' => $pass,
        'booth' => $booth,
        'seats' => $seats,
        'exp' => $exp,
    ], 200, 'Booth pass issued.');
});
