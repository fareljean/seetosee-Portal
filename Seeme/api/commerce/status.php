<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';

use SeeToSee\Auth;
use SeeToSee\CommerceService;
use SeeToSee\Http;

Http::run(static function (): void {
    Http::requireMethod('GET');
    $session = Auth::currentSession();
    Http::success(CommerceService::status((int) $session['user_id']));
});
