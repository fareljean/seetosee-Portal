<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';

use SeeToSee\Communications;
use SeeToSee\Http;

Http::run(static function (): void {
    Http::requireMethod('POST');
    $input = Http::jsonInput(4096);
    $token = is_string($input['token'] ?? null) ? $input['token'] : '';
    Http::success(Communications::confirmReceipt($token));
});
