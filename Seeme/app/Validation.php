<?php
declare(strict_types=1);

namespace SeeToSee;

final class Validation
{
    public static function string(array $input, string $key, int $min, int $max, bool $required = true): ?string
    {
        $value = $input[$key] ?? null;
        if ($value === null && !$required) {
            return null;
        }
        if (!is_string($value)) {
            throw new ApiException(422, 'validation_failed', 'Please correct the highlighted fields.', [$key => 'Must be text.']);
        }
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length < $min || $length > $max) {
            throw new ApiException(422, 'validation_failed', 'Please correct the highlighted fields.', [$key => sprintf('Must be between %d and %d characters.', $min, $max)]);
        }
        return $value;
    }

    public static function email(array $input, string $key, bool $required = true): ?string
    {
        $value = self::string($input, $key, 3, 254, $required);
        if ($value === null) {
            return null;
        }
        $value = strtolower($value);
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $value)) {
            throw new ApiException(422, 'validation_failed', 'Please correct the highlighted fields.', [$key => 'Enter a valid email address.']);
        }
        return $value;
    }

    public static function password(array $input, string $key = 'password'): string
    {
        $password = $input[$key] ?? null;
        if (!is_string($password) || strlen($password) < 12 || strlen($password) > 1024) {
            throw new ApiException(422, 'validation_failed', 'Please correct the highlighted fields.', [$key => 'Use at least 12 characters.']);
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            throw new ApiException(422, 'validation_failed', 'Please correct the highlighted fields.', [$key => 'Include at least one letter and one number.']);
        }
        return $password;
    }

    public static function bool(array $input, string $key): bool
    {
        if (!array_key_exists($key, $input) || !is_bool($input[$key])) {
            throw new ApiException(422, 'validation_failed', 'Please correct the highlighted fields.', [$key => 'Must be true or false.']);
        }
        return $input[$key];
    }
}

