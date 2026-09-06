<?php
declare(strict_types=1);

use SeeToSee\Env;
use SeeToSee\Http;

const SEETOSEE_ROOT = __DIR__ . '/..';

spl_autoload_register(static function (string $class): void {
    $prefix = 'SeeToSee\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $name = substr($class, strlen($prefix));
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        return;
    }
    $path = __DIR__ . '/' . $name . '.php';
    if (is_file($path)) {
        require $path;
    }
});

Env::load(SEETOSEE_ROOT);
date_default_timezone_set(Env::get('APP_TIMEZONE', 'UTC') ?? 'UTC');
ini_set('display_errors', Env::bool('APP_DEBUG', false) ? '1' : '0');
ini_set('log_errors', '1');
$logPath = SEETOSEE_ROOT . '/storage/logs/php-error.log';
if (is_dir(dirname($logPath)) && is_writable(dirname($logPath))) {
    ini_set('error_log', $logPath);
}

Http::initialize();

if (Env::get('APP_ENV', 'production') === 'production') {
    $applicationKey = (string) Env::get('APP_KEY', '');
    if (strlen($applicationKey) < 32 || stripos($applicationKey, 'replace') !== false || stripos($applicationKey, 'generate') !== false) {
        error_log('APP_KEY is missing, still a placeholder, or too short.');
        if (PHP_SAPI !== 'cli') {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => ['code' => 'configuration_incomplete', 'message' => 'Application configuration is incomplete.'], 'request_id' => Http::requestId()], JSON_UNESCAPED_SLASHES);
        }
        exit;
    }
}
