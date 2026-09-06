<?php
declare(strict_types=1);

/**
 * Booth admission-pass verification (shared by create/join only).
 * Secret loaded from __DIR__/.env — never fail open.
 */

function booth_load_secret(): ?string
{
    $path = __DIR__ . '/.env';
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return null;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        if (trim($key) !== 'BOOTH_SECRET') {
            continue;
        }
        $value = trim($value);
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        return $value !== '' ? $value : null;
    }
    return null;
}

function booth_b64url_decode(string $data): string|false
{
    $pad = strlen($data) % 4;
    if ($pad > 0) {
        $data .= str_repeat('=', 4 - $pad);
    }
    return base64_decode(strtr($data, '-_', '+/'), true);
}

function booth_collect_pass(?array $body): string
{
    if (is_array($body) && isset($body['pass']) && is_string($body['pass']) && $body['pass'] !== '') {
        return $body['pass'];
    }
    if (isset($_GET['pass']) && is_string($_GET['pass']) && $_GET['pass'] !== '') {
        return $_GET['pass'];
    }
    $header = $_SERVER['HTTP_X_BOOTH_PASS'] ?? '';
    if (is_string($header) && trim($header) !== '') {
        return trim($header);
    }
    return '';
}

/**
 * Verify a Seeme-minted booth pass. On failure: HTTP 403 + JSON and exit.
 *
 * @param string $expectedBooth ipv1|ipv2
 * @param array|null $body Already-parsed JSON body (php://input may be consumed)
 */
function require_booth_pass(string $expectedBooth, ?array $body = null): array
{
    $fail = static function (string $message): never {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES);
        exit;
    };

    $secret = booth_load_secret();
    if ($secret === null) {
        $fail('Booth pass required');
    }

    $pass = booth_collect_pass($body);
    if ($pass === '' || !str_contains($pass, '.')) {
        $fail('Booth pass required');
    }

    [$payloadB64, $sigHex] = explode('.', $pass, 2);
    if ($payloadB64 === '' || $sigHex === '' || !preg_match('/^[0-9a-fA-F]+$/', $sigHex)) {
        $fail('Booth pass invalid');
    }

    $expectedSig = hash_hmac('sha256', $payloadB64, $secret);
    if (!hash_equals($expectedSig, strtolower($sigHex))) {
        $fail('Booth pass invalid');
    }

    $raw = booth_b64url_decode($payloadB64);
    if ($raw === false || $raw === '') {
        $fail('Booth pass invalid');
    }

    try {
        $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        $fail('Booth pass invalid');
    }
    if (!is_array($payload)) {
        $fail('Booth pass invalid');
    }

    $booth = (string) ($payload['booth'] ?? '');
    if ($booth !== $expectedBooth) {
        $fail('Wrong booth');
    }

    $expectedSeats = $expectedBooth === 'ipv2' ? 4 : 2;
    if ((int) ($payload['seats'] ?? 0) !== $expectedSeats) {
        $fail('Booth pass invalid');
    }

    $exp = (int) ($payload['exp'] ?? 0);
    if ($exp <= time()) {
        $fail('Booth pass expired');
    }

    $product = (string) ($payload['product'] ?? '');
    if (!in_array($product, ['portal.active', 'consultation.personal'], true)) {
        $fail('Booth pass invalid');
    }

    $jti = $payload['jti'] ?? null;
    if (!is_string($jti) || $jti === '') {
        $fail('Booth pass invalid');
    }

    if (!array_key_exists('sub', $payload) || $payload['sub'] === null || $payload['sub'] === '') {
        $fail('Booth pass invalid');
    }

    return $payload;
}
