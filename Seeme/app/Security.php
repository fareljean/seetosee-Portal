<?php
declare(strict_types=1);

namespace SeeToSee;

final class Security
{
    public static function randomToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function passwordHash(string $password): string
    {
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        $hash = password_hash($password, $algorithm);
        if (!is_string($hash)) {
            throw new ApiException(500, 'password_error', 'Unable to secure the password.');
        }
        return $hash;
    }

    public static function ensureCsrf(?array $session = null): void
    {
        $header = Http::header('X-CSRF-Token');
        $cookie = (string) ($_COOKIE['sts_csrf'] ?? '');
        if ($header === '' || $cookie === '' || !hash_equals($cookie, $header)) {
            throw new ApiException(403, 'csrf_rejected', 'Security token is missing or expired. Refresh and try again.');
        }
        if ($session !== null && (!isset($session['csrf_token']) || !hash_equals((string) $session['csrf_token'], $header))) {
            throw new ApiException(403, 'csrf_rejected', 'Security token is missing or expired. Refresh and try again.');
        }
    }

    public static function issueCsrf(?string $token = null): string
    {
        $token ??= self::randomToken(32);
        self::setCookie('sts_csrf', $token, time() + 86400, false);
        return $token;
    }

    public static function setCookie(string $name, string $value, int $expires, bool $httpOnly = true): void
    {
        setcookie($name, $value, [
            'expires' => $expires,
            'path' => '/',
            'secure' => Env::bool('SESSION_SECURE', true),
            'httponly' => $httpOnly,
            'samesite' => 'Lax',
        ]);
    }

    public static function clearCookie(string $name, bool $httpOnly = true): void
    {
        self::setCookie($name, '', time() - 3600, $httpOnly);
        unset($_COOKIE[$name]);
    }

    public static function safeDestination(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new ApiException(422, 'invalid_destination', 'Enter a valid destination URL.');
        }
        $parts = parse_url($value);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new ApiException(422, 'invalid_destination', 'Enter a complete destination URL including https://.');
        }
        $scheme = strtolower((string) $parts['scheme']);
        $allowHttp = Env::bool('ALLOW_HTTP_DESTINATIONS', false);
        if ($scheme !== 'https' && !($allowHttp && $scheme === 'http')) {
            throw new ApiException(422, 'invalid_destination', 'Destination URLs must use HTTPS.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ApiException(422, 'invalid_destination', 'Destination URLs cannot contain credentials.');
        }
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if ($host === 'localhost' || str_ends_with($host, '.local') || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false && filter_var($host, FILTER_VALIDATE_IP)) {
            throw new ApiException(422, 'invalid_destination', 'Private or local destinations are not allowed.');
        }
        return $value;
    }

}

