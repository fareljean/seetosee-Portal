<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';

use SeeToSee\Http;
use SeeToSee\ProductCatalog;

Http::run(static function (): void {
    Http::requireMethod('GET');
    Http::success(['products' => ProductCatalog::publicCatalog()]);
});
