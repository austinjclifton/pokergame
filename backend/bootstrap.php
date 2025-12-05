<?php
// backend/bootstrap.php
// -----------------------------------------------------------------------------
// Central bootstrap used by all API endpoints in both local and production.
// -----------------------------------------------------------------------------

declare(strict_types=1);

// Backend root is simply this directory
define('BASE_PATH', __DIR__);

// ---------------------------------------------------------------
// Output headers (API endpoints only)
// ---------------------------------------------------------------
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ---------------------------------------------------------------
// Composer autoload (MUST load before any class usage)
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
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', '1');
    session_start();
}

// ---------------------------------------------------------------
// Load internal libraries / helpers
// ---------------------------------------------------------------

// helper functions (procedural)
require_once BASE_PATH . '/lib/security.php';
require_once BASE_PATH . '/lib/session.php';

// procedural nonce helpers (your functions)
require_once BASE_PATH . '/app/services/NonceService.php';
require_once BASE_PATH . '/app/services/AuthService.php';

// Database helpers
require_once BASE_PATH . '/app/db/challenges.php';   // <-- REQUIRED FOR pending.php

// ---------------------------------------------------------------
// Bootstrap complete
// ---------------------------------------------------------------
