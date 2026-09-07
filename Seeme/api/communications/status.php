<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';

use SeeToSee\Auth;
use SeeToSee\Communications;
use SeeToSee\Http;

Http::run(static function (): void {
    Http::requireMethod('GET');
    $session = Auth::currentSession(true);
    Communications::requireOperator($session);
    Http::success(Communications::dashboard());
});
