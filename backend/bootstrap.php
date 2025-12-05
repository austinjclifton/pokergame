<?php
// backend/bootstrap.php
declare(strict_types=1);

// Backend root
define('BASE_PATH', __DIR__);

// ---------------------------------------------------------------
// LOCAL vs VM detection
// ---------------------------------------------------------------
$host = $_SERVER['HTTP_HOST'] ?? '';
$https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';

// Local dev = no HTTPS + localhost or 127.x.x.x
$runningLocally =
    (!$https) &&
    (str_starts_with($host, 'localhost') || str_starts_with($host, '127.'));

// ---------------------------------------------------------------
// CORS (LOCAL ONLY)
// ---------------------------------------------------------------
if ($runningLocally) {
    header("Access-Control-Allow-Origin: http://localhost:5173");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

    // Preflight requests must exit early
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

// ---------------------------------------------------------------
// Output headers (API endpoints only)
// ---------------------------------------------------------------
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ---------------------------------------------------------------
// Composer autoload
// ---------------------------------------------------------------
require_once BASE_PATH . '/vendor/autoload.php';

// ---------------------------------------------------------------
// Load config files
// ---------------------------------------------------------------
require_once BASE_PATH . '/config/db.php';
require_once BASE_PATH . '/config/security.php';

// ---------------------------------------------------------------
// Session hardening
// ---------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', '1');  // HTTPS VM enforces secure cookies
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', '1');
    session_start();
}

// ---------------------------------------------------------------
// Load internal libraries
// ---------------------------------------------------------------
require_once BASE_PATH . '/lib/security.php';
require_once BASE_PATH . '/lib/session.php';
require_once BASE_PATH . '/app/services/NonceService.php';
require_once BASE_PATH . '/app/services/AuthService.php';
require_once BASE_PATH . '/app/db/challenges.php';

// ---------------------------------------------------------------
// Bootstrap complete
// ---------------------------------------------------------------
