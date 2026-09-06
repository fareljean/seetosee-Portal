<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';

use SeeToSee\ApiException;
use SeeToSee\Auth;
use SeeToSee\Env;
use SeeToSee\Http;

Http::run(static function (): void {
    Http::requireMethod('GET', 'POST');
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        // GET is intentionally read-only. Mail providers and security products often prefetch links.
        // The real verification is a POST issued by this page after the member opens it in a browser.
        header('Content-Type: text/html; charset=utf-8');
        $verifiedPage = rtrim(Env::require('APP_URL'), '/') . '/auth/verified.html';
        $verifiedPageJson = json_encode($verifiedPage, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        if (!is_string($verifiedPageJson)) {
            throw new ApiException(500, 'server_error', 'The verification page could not be prepared.');
        }
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow"><title>Verifying · SeeToSee</title>'
            . '<style>*{box-sizing:border-box}body{margin:0;min-height:100svh;display:grid;place-items:center;padding:20px;background:#07080a;color:#f5f5f1;font-family:Inter,system-ui,sans-serif}.card{width:min(560px,100%);padding:38px;border:1px solid #ffffff22;border-radius:24px;background:#101216;text-align:center}.brand{font-weight:950;letter-spacing:.12em}.brand span{color:#e6c77a}h1{font-size:clamp(34px,7vw,58px);line-height:.95;letter-spacing:-.06em}.card p{color:#a7aaad}.btn{display:inline-flex;min-height:48px;align-items:center;padding:0 22px;border:0;border-radius:999px;background:#e6c77a;color:#111;font:inherit;font-weight:900;cursor:pointer}</style></head>'
            . '<body><main class="card"><div class="brand">SEE<span>TO</span>SEE</div><h1 id="title">Verifying your email…</h1><p id="copy">Please keep this page open for a moment.</p><noscript><p>JavaScript is required to finish verification safely.</p></noscript></main>'
            . '<script>(async()=>{const done=' . $verifiedPageJson . ';const q=new URLSearchParams(location.search);const h=new URLSearchParams(location.hash.slice(1));const token=h.get("token")||q.get("token")||"";history.replaceState(null,"",location.pathname);if(!token){location.replace(done+"?status=invalid");return;}try{const r=await fetch(location.pathname,{method:"POST",headers:{"Accept":"application/json","Content-Type":"application/json"},credentials:"same-origin",body:JSON.stringify({token})});const j=await r.json().catch(()=>null);location.replace(done+"?status="+(r.ok&&j&&j.ok?"success":"invalid"));}catch(e){location.replace(done+"?status=invalid");}})();</script></body></html>';
        exit;
    }

    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $input = Http::jsonInput(4096);
        $token = is_string($input['token'] ?? null) ? (string) $input['token'] : '';
    } else {
        $token = is_string($_POST['token'] ?? null) ? (string) $_POST['token'] : '';
    }

    $ok = Auth::verifyEmailToken($token);
    $acceptsJson = str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
    if ($acceptsJson) {
        if ($ok) {
            Http::success(['verified' => true], 200, 'Email verified.');
        }
        throw new ApiException(400, 'invalid_verification_token', 'This verification link is invalid or expired.');
    }

    header('Location: ' . rtrim(Env::require('APP_URL'), '/') . '/auth/verified.html?status=' . ($ok ? 'success' : 'invalid'), true, 303);
    exit;
});
