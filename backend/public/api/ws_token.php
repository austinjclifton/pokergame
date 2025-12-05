<?php
// backend/public/api/ws_token.php
// -----------------------------------------------------------------------------
// Issues a short-lived WebSocket auth token for the *currently logged-in* user.
// Auth model: custom cookie "session_id" (int) backed by DB "sessions" table.
// Flow:
//   - Method: POST (OPTIONS allowed)
//   - Auth: must already be logged in (same cookie jar as /api/login.php)
//   - Output: { ok:true, token, expiresIn } or { ok:false, error, ...debug? }
// -----------------------------------------------------------------------------

declare(strict_types=1);

// ---------- Bootstrap ---------------------------------------------------------
require_once dirname(__DIR__, 2) . '/bootstrap.php';


// Services (layered separation)
// require_once __DIR__ . '/../app/services/AuthService.php';// auth_require_session(...)
// require_once __DIR__ . '/../app/services/NonceService.php';// nonce_issue_ws_token(...)

// ---------- Small helpers -----------------------------------------------------

// Note: debug_enabled() is now defined in lib/security.php
// This local function is kept for backward compatibility
function dbg_enabled(): bool
{
    return debug_enabled(); // Use centralized function
}

function json_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------- Method guard ------------------------------------------------------
setAllowedMethods('POST, OPTIONS');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    // Preflight
    http_response_code(204);
    exit;
}
if ($method !== 'POST') {
    json_out(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

// Apply rate limiting (100 requests/minute per IP, 200/minute per user after auth)
apply_rate_limiting(null, 100, 200, 60);

// ---------- Main --------------------------------------------------------------
try {
    // 1) Ensure user is authenticated (cookie-based session_id)
    // Prefer AuthService (normalized shape), then fall back once.
    $user = null;
    try {
        $user = auth_require_session($pdo); // ['id','username','email','session_id']
    } catch (RuntimeException $e) {
        // Fallback to legacy helper if AuthService path changes
        $session = requireSession($pdo); // ['user_id','username','email','session_id'] or null
        if (!$session || empty($session['user_id'])) {
            $response = ['ok' => false, 'error' => 'unauthorized'];
            if (dbg_enabled()) {
                $response['hint'] = 'no_valid_session';
            }
            json_out($response, 401);
        }
        $user = [
            'id' => (int) $session['user_id'],
            'username' => $session['username'],
            'email' => $session['email'],
            'session_id' => (int) $session['session_id'],
        ];
    }

    // Sanity checks
    if (empty($user['session_id']) || (int) $user['session_id'] <= 0) {
        json_out(['ok' => false, 'error' => 'invalid_session_context'], 401);
    }

    // Re-apply with user ID for user-based limiting
    apply_rate_limiting($user['id'], 100, 200, 60);

    // 2) Issue short-lived WS token (default 30s)
    $ttl = 30;
    $tokenData = nonce_issue_ws_token($pdo, $ttl); // ['token','expiresIn']
    if (empty($tokenData['token'])) {
        // Defensive: service should either throw or return a token; treat as 500 if not
        json_out(['ok' => false, 'error' => 'token_issue_failed'], 500);
    }

    // 3) Respond
    json_out([
        'ok' => true,
        'token' => $tokenData['token'],
        'expiresIn' => (int) ($tokenData['expiresIn'] ?? $ttl),
    ]);

} catch (\Throwable $e) {
    // 4) Robust error reporting (no secret leakage unless debug)
    error_log('[ws_token] ' . $e->getMessage());

    $resp = ['ok' => false, 'error' => 'server_error'];
    if (dbg_enabled()) {
        $resp['detail'] = $e->getMessage();
        // Include a tiny trace hint, first file:line to speed up local debugging
        $trace = $e->getTrace();
        if (!empty($trace[0]['file'])) {
            $resp['where'] = basename($trace[0]['file']) . ':' . ($trace[0]['line'] ?? '?');
        }
        // Show which cookie the server actually sees
        $resp['cookies_seen'] = array_keys($_COOKIE);
        $resp['session_cookie_value'] = $_COOKIE['session_id'] ?? null;
    }

    json_out($resp, 500);
}
