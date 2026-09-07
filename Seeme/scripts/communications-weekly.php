<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use SeeToSee\Communications;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    echo json_encode(Communications::runWeeklyIfDue(), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
} catch (Throwable $exception) {
    error_log('Communications weekly runner failed: ' . $exception->getMessage());
    fwrite(STDERR, "Weekly communications run failed.\n");
    exit(1);
}
