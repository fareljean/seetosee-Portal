<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';

use SeeToSee\Auth;
use SeeToSee\CommerceService;
use SeeToSee\Http;
use SeeToSee\Security;
use SeeToSee\Validation;

Http::run(static function (): void {
    Http::requireMethod('POST');
    $session = Auth::currentSession();
    Security::ensureCsrf($session);
    $input = Http::jsonInput(32768);
    $productId = Validation::string($input, 'product_id', 3, 128);
    Http::success(CommerceService::createCheckout((int) $session['user_id'], $productId), 201);
});
