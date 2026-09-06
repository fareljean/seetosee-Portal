<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';

use SeeToSee\Auth;
use SeeToSee\Http;
use SeeToSee\Security;

Http::run(static function (): void {
    Http::requireMethod('GET');
    $session = Auth::currentSession(false);
    if ($session === null) {
        $csrf = Security::issueCsrf();
        Http::success(['authenticated' => false, 'member' => null, 'csrf_token' => $csrf]);
    }
    Security::issueCsrf((string) $session['csrf_token']);
    Http::success(['authenticated' => true, 'member' => Auth::memberData((int) $session['user_id']), 'csrf_token' => $session['csrf_token']]);
});

