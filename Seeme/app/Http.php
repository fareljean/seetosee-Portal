<?php
declare(strict_types=1);

namespace SeeToSee;

use JsonException;
use Throwable;

final class Http
{
    private static ?string $requestId = null;

    public static function initialize(): void
    {
        self::$requestId = bin2hex(random_bytes(8));
        header('X-Request-ID: ' . self::$requestId);
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store, private');
    }

    public static function requestId(): string
    {
        return self::$requestId ?? 'uninitialized';
    }

    public static function requireMethod(string ...$methods): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $methods = array_map('strtoupper', $methods);
        if (!in_array($method, $methods, true)) {
            header('Allow: ' . implode(', ', $methods));
            throw new ApiException(405, 'method_not_allowed', 'Method not allowed.');
        }
    }

    public static function jsonInput(int $maxBytes = 1048576): array
    {
        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length > $maxBytes) {
            throw new ApiException(413, 'request_too_large', 'Request body is too large.');
        }
        $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
        if ($raw === false || strlen($raw) > $maxBytes) {
            throw new ApiException(413, 'request_too_large', 'Request body is too large.');
        }
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException(400, 'invalid_json', 'Malformed JSON request.');
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ApiException(400, 'invalid_json', 'A JSON object is required.');
        }
        return $decoded;
    }

    public static function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return trim((string) ($_SERVER[$key] ?? ''));
    }

    public static function clientIp(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if (Env::bool('TRUST_PROXY', false)) {
            $candidate = self::header('CF-Connecting-IP');
            if ($candidate === '') {
                $forwarded = self::header('X-Forwarded-For');
                $candidate = trim(explode(',', $forwarded)[0] ?? '');
            }
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                $ip = $candidate;
            }
        }
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    public static function success(array $data = [], int $status = 200, ?string $message = null): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        $payload = ['ok' => true, 'data' => $data, 'request_id' => self::requestId()];
        if ($message !== null) {
            $payload['message'] = $message;
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public static function failure(ApiException $exception): never
    {
        http_response_code($exception->status);
        header('Content-Type: application/json; charset=utf-8');
        $error = ['code' => $exception->errorCode, 'message' => $exception->getMessage()];
        if ($exception->details !== []) {
            $error['details'] = $exception->details;
        }
        echo json_encode(['ok' => false, 'error' => $error, 'request_id' => self::requestId()], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public static function run(callable $handler): never
    {
        try {
            $handler();
            throw new ApiException(500, 'empty_response', 'The endpoint did not return a response.');
        } catch (ApiException $exception) {
            self::failure($exception);
        } catch (Throwable $throwable) {
            error_log(sprintf('[%s] %s in %s:%d', self::requestId(), $throwable->getMessage(), $throwable->getFile(), $throwable->getLine()));
            self::failure(new ApiException(500, 'server_error', 'The request could not be completed.'));
        }
    }
}

