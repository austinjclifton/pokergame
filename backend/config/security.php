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
// Content-Security-Policy: Allow WebSocket connections and inline scripts (needed for React/Vite dev)
header("Content-Security-Policy: default-src 'self'; connect-src 'self' ws: wss:; frame-ancestors 'none'; object-src 'none'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline' fonts.googleapis.com; font-src 'self' fonts.gstatic.com;");
header('Referrer-Policy: no-referrer-when-downgrade');
header('X-Permitted-Cross-Domain-Policies: none');

// Enable HSTS only when HTTPS is used (do not force on localhost)
if (!empty($_SERVER['HTTPS'])) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}

// -------------------- CORS Configuration --------------------
$allowedOrigins = [
    'http://localhost:5173',               // local Vite dev
    'https://pokergame.futuredomain.com',    // production (adjust as needed)
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
}

header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

// Always JSON API responses
header('Content-Type: application/json; charset=utf-8');

// -------------------- Preflight Handling --------------------
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
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
