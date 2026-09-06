<?php
declare(strict_types=1);

namespace SeeToSee;

use RuntimeException;

final class Env
{
    private static array $values = [];

    public static function load(string $root): void
    {
        $explicit = getenv('SEE_ENV_FILE');
        $paths = $explicit !== false && $explicit !== ''
            ? [$explicit]
            : [dirname($root) . '/.env', $root . '/.env'];

        foreach ($paths as $path) {
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }
            $lines = file($path, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                throw new RuntimeException('Unable to read environment configuration.');
            }
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (str_starts_with($line, 'export ')) {
                    $line = trim(substr($line, 7));
                }
                $position = strpos($line, '=');
                if ($position === false) {
                    continue;
                }
                $key = trim(substr($line, 0, $position));
                $value = trim(substr($line, $position + 1));
                if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                    continue;
                }
                if (strlen($value) >= 2) {
                    $quote = $value[0];
                    if (($quote === '"' || $quote === "'") && $value[strlen($value) - 1] === $quote) {
                        $value = substr($value, 1, -1);
                        if ($quote === '"') {
                            $value = stripcslashes($value);
                        }
                    }
                }
                self::$values[$key] = $value;
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $process = getenv($key);
        if ($process !== false) {
            return $process;
        }
        return array_key_exists($key, self::$values) ? self::$values[$key] : $default;
    }

    public static function require(string $key): string
    {
        $value = self::get($key);
        if ($value === null || trim($value) === '') {
            throw new RuntimeException('Missing required environment value: ' . $key);
        }
        return $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);
        return $value !== null && preg_match('/^-?\d+$/', $value) ? (int) $value : $default;
    }

    public static function csv(string $key, array $default = []): array
    {
        $value = self::get($key);
        if ($value === null || trim($value) === '') {
            return $default;
        }
        return array_values(array_filter(array_map(static fn(string $item): string => trim($item), explode(',', $value))));
    }
}

