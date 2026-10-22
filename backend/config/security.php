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
// API responses are JSON; this policy also protects accidental HTML responses.
header("Content-Security-Policy: default-src 'self'; connect-src 'self' ws: wss:; frame-ancestors 'none'; object-src 'none'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline' fonts.googleapis.com; font-src 'self' fonts.gstatic.com;");
header('Referrer-Policy: no-referrer-when-downgrade');
header('X-Permitted-Cross-Domain-Policies: none');

// Enable HSTS only when HTTPS is used (do not force on localhost)
if (!empty($_SERVER['HTTPS'])) {
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
function setAllowedMethods(string $methods): void {
    header("Access-Control-Allow-Methods: {$methods}");
}
