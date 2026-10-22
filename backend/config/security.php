<?php
// backend/config/security.php
// -----------------------------------------------------------------------------
// Centralized HTTP security and CORS configuration for all API endpoints.
// Include this at the top of every public API file to ensure consistent
// and secure headers across the PokerGame backend.
// -----------------------------------------------------------------------------

// -------------------- Core Security Headers --------------------
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
// API responses never execute application assets. Keep WebSocket destinations
// available for clients that apply this policy to a response context.
header("Content-Security-Policy: default-src 'none'; base-uri 'none'; connect-src 'self' ws: wss:; form-action 'none'; frame-ancestors 'none'; object-src 'none'");
header('Referrer-Policy: no-referrer');
header('X-Permitted-Cross-Domain-Policies: none');
header('Permissions-Policy: camera=(), geolocation=(), microphone=()');
header('Cache-Control: no-store');

// Enable HSTS only when HTTPS is used (do not force on localhost)
$https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
$isHttps = $https !== '' && $https !== 'off' && $https !== '0';
if ($isHttps) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}

// -------------------- CORS Configuration --------------------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigin = trim((string) (getenv('APP_ORIGIN') ?: ''));
if ($allowedOrigin !== '' && $origin !== '' && hash_equals($allowedOrigin, $origin)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Vary: Origin');
}

header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

// Always JSON API responses
header('Content-Type: application/json; charset=utf-8');

// -------------------- Preflight Handling --------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// -------------------- Helper --------------------
/**
 * setAllowedMethods()
 * Set allowed HTTP methods for the current endpoint.
 * Example: setAllowedMethods('POST, OPTIONS');
 */
function setAllowedMethods(string $methods): void
{
    header("Access-Control-Allow-Methods: {$methods}");
}
