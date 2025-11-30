<?php
// backend/bootstrap.php
// -----------------------------------------------------------------------------
// Centralized bootstrap file for all API endpoints.
// -----------------------------------------------------------------------------

declare(strict_types=1);

// Always return JSON from API endpoints
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Base directory (backend/)
$backendDir = __DIR__;

// Load database configuration (creates $pdo instance)
require_once $backendDir . '/config/db.php';

// Start secure session
if (session_status() === PHP_SESSION_NONE) {

    ini_set('session.cookie_httponly', '1');

    // RIT VM always serves HTTPS externally — enforce secure cookie
    ini_set('session.cookie_secure', '1');

    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_lifetime', '0');

    session_start();
}

// Load security configuration (CORS, headers)
require_once $backendDir . '/config/security.php';

// Load helper libraries
require_once $backendDir . '/lib/security.php';
require_once $backendDir . '/lib/session.php';

// Composer autoloader
$vendorAutoload = $backendDir . '/vendor/autoload.php';
if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
}
