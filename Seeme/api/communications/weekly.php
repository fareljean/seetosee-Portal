<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';

use SeeToSee\Auth;
use SeeToSee\Communications;
use SeeToSee\Http;
use SeeToSee\Security;

Http::run(static function (): void {
    Http::requireMethod('POST');
    $session = Auth::currentSession(true);
    Communications::requireOperator($session);
    Security::ensureCsrf($session);
    $input = Http::jsonInput(4096);
    Http::success(['weekly' => Communications::setWeekly((int) $session['user_id'], (string) ($input['action'] ?? ''))]);
});
