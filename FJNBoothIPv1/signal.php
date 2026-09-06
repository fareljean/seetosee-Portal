<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

const MAX_BODY = 262144;
const MAX_SIGNALS = 200;
const ROOM_TTL = 21600;
const RATE_WINDOW = 60;
const RATE_LIMIT = 240;

$dataDir = __DIR__ . '/booth-data';
$rateDir = $dataDir . '/rate';
if (!is_dir($dataDir) || !is_writable($dataDir)) respond(500, ['error' => 'Booth storage is unavailable']);
if (!is_dir($rateDir) && !@mkdir($rateDir, 0750, true) && !is_dir($rateDir)) respond(500, ['error' => 'Rate storage is unavailable']);

function respond(int $status, array $payload): never {
    http_response_code($status);
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    echo $json === false ? '{"error":"Response encoding failed"}' : $json;
    exit;
}

function body(): array {
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > MAX_BODY) respond(413, ['error' => 'Request too large']);
    $raw = file_get_contents('php://input', false, null, 0, MAX_BODY + 1);
    if ($raw === false || strlen($raw) > MAX_BODY) respond(413, ['error' => 'Request too large']);
    if ($raw === '') return [];
    try { $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException) { respond(400, ['error' => 'Malformed JSON']); }
    if (!is_array($value)) respond(400, ['error' => 'JSON object required']);
    return $value;
}

function room_code(mixed $value): ?string {
    $code = strtoupper((string)$value);
    return preg_match('/^[A-Z2-9]{6}$/', $code) === 1 ? $code : null;
}

function new_code(): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 6; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return $code;
}

function new_token(): string { return bin2hex(random_bytes(32)); }
function token_hash(string $token): string { return hash('sha256', $token); }
function supplied_token(): string { return trim((string)($_SERVER['HTTP_X_BOOTH_TOKEN'] ?? '')); }

function authorize(array $room, string $token): ?string {
    if ($token === '') return null;
    $hash = token_hash($token);
    if (isset($room['host_token']) && hash_equals((string)$room['host_token'], $hash)) return 'host';
    if (isset($room['guest_token']) && is_string($room['guest_token']) && hash_equals($room['guest_token'], $hash)) return 'guest';
    return null;
}

function rate_limit(string $dir): void {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $key = hash('sha256', $ip);
    $path = $dir . '/' . $key . '.json';
    $lock = fopen($path . '.lock', 'c+');
    if ($lock === false || !flock($lock, LOCK_EX)) respond(503, ['error' => 'Please retry']);
    $now = time();
    $entry = ['start' => $now, 'count' => 0];
    if (is_file($path)) {
        $decoded = json_decode((string)@file_get_contents($path), true);
        if (is_array($decoded)) $entry = $decoded;
    }
    if ($now - (int)$entry['start'] >= RATE_WINDOW) $entry = ['start' => $now, 'count' => 0];
    $entry['count'] = (int)$entry['count'] + 1;
    @file_put_contents($path, json_encode($entry), LOCK_EX);
    flock($lock, LOCK_UN);
    fclose($lock);
    if ($entry['count'] > RATE_LIMIT) respond(429, ['error' => 'Too many requests']);
}

function with_room_lock(string $dir, string $code, callable $callback): mixed {
    $path = $dir . '/' . $code . '.json';
    $lock = fopen($dir . '/' . $code . '.lock', 'c+');
    if ($lock === false || !flock($lock, LOCK_EX)) respond(503, ['error' => 'Please retry']);
    try {
        $room = null;
        if (is_file($path)) {
            $decoded = json_decode((string)@file_get_contents($path), true);
            if (is_array($decoded)) $room = $decoded;
        }
        return $callback($path, $room);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function save_room(string $path, array $room): void {
    $room['updated'] = time();
    $json = json_encode($room, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || file_put_contents($path, $json, LOCK_EX) === false) respond(500, ['error' => 'Unable to save room']);
}

function add_signal(array &$room, string $type, string $from, mixed $data): int {
    $room['seq'] = (int)($room['seq'] ?? 0) + 1;
    $room['signals'][] = ['id' => $room['seq'], 'type' => $type, 'from' => $from, 'data' => $data, 'ts' => time()];
    if (count($room['signals']) > MAX_SIGNALS) $room['signals'] = array_slice($room['signals'], -MAX_SIGNALS);
    return $room['seq'];
}

function cleanup(string $dir): void {
    if (random_int(1, 100) !== 1) return;
    $cutoff = time() - ROOM_TTL;
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        if ((int)@filemtime($file) < $cutoff) @unlink($file);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') respond(204, []);
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) respond(405, ['error' => 'Method not allowed']);
rate_limit($rateDir);
cleanup($dataDir);
$action = (string)($_GET['action'] ?? '');
$input = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? body() : [];

if ($action === 'ping') respond(200, ['ok' => true, 'build' => 'FJN-Booth-V3-Hostinger-1']);

if ($action === 'create') {
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $code = new_code();
        $token = new_token();
        $created = with_room_lock($dataDir, $code, function (string $path, ?array $room) use ($code, $token): bool {
            if ($room !== null || is_file($path)) return false;
            save_room($path, [
                'room' => $code, 'created' => time(), 'updated' => time(),
                'host' => true, 'guest' => false,
                'host_token' => token_hash($token), 'guest_token' => null,
                'seq' => 0, 'signals' => []
            ]);
            return true;
        });
        if ($created) respond(201, ['ok' => true, 'room' => $code, 'role' => 'host', 'token' => $token]);
    }
    respond(503, ['error' => 'Unable to create room']);
}

$rawRoom = $input['room'] ?? ($_GET['room'] ?? '');
$code = room_code($rawRoom);
if ($code === null) respond(400, ['error' => 'Invalid room code']);

if ($action === 'join') {
    with_room_lock($dataDir, $code, function (string $path, ?array $room) use ($code): never {
        if ($room === null) respond(404, ['error' => 'Room not found']);
        if (!empty($room['guest'])) respond(409, ['error' => 'Room full', 'full' => true]);
        $token = new_token();
        $room['guest'] = true;
        $room['guest_token'] = token_hash($token);
        add_signal($room, 'peer-joined', 'guest', null);
        save_room($path, $room);
        respond(200, ['ok' => true, 'room' => $code, 'role' => 'guest', 'token' => $token]);
    });
}

$token = supplied_token();

if ($action === 'signal') {
    with_room_lock($dataDir, $code, function (string $path, ?array $room) use ($input, $token): never {
        if ($room === null) respond(404, ['error' => 'Room not found']);
        $role = authorize($room, $token);
        if ($role === null) respond(403, ['error' => 'Room access denied']);
        $type = (string)($input['type'] ?? '');
        if (!in_array($type, ['offer', 'answer', 'ice'], true)) respond(400, ['error' => 'Invalid signal type']);
        $id = add_signal($room, $type, $role, $input['data'] ?? null);
        save_room($path, $room);
        respond(200, ['ok' => true, 'id' => $id]);
    });
}

if ($action === 'poll') {
    $after = max(0, (int)($_GET['after'] ?? 0));
    with_room_lock($dataDir, $code, function (string $path, ?array $room) use ($after, $token): never {
        if ($room === null) respond(404, ['error' => 'Room not found']);
        if (authorize($room, $token) === null) respond(403, ['error' => 'Room access denied']);
        $signals = array_values(array_filter($room['signals'], static fn(array $signal): bool => (int)$signal['id'] > $after));
        respond(200, ['ok' => true, 'room' => $room['room'], 'host' => true, 'guest' => !empty($room['guest']), 'signals' => $signals]);
    });
}

if ($action === 'leave') {
    with_room_lock($dataDir, $code, function (string $path, ?array $room) use ($token): never {
        if ($room === null) respond(200, ['ok' => true]);
        $role = authorize($room, $token);
        if ($role === null) respond(403, ['error' => 'Room access denied']);
        if ($role === 'host') {
            @unlink($path);
            respond(200, ['ok' => true, 'closed' => true]);
        }
        add_signal($room, 'peer-left', 'guest', null);
        $room['guest'] = false;
        $room['guest_token'] = null;
        save_room($path, $room);
        respond(200, ['ok' => true]);
    });
}

respond(400, ['error' => 'Unknown action']);
