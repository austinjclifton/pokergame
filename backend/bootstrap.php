<?php
// backend/bootstrap.php
declare(strict_types=1);

// Backend root
define('BASE_PATH', __DIR__);

// Report every diagnostic to the configured PHP error log, never to an API
// response. Endpoint catch blocks return deliberately generic JSON errors.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

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
require_once BASE_PATH . '/lib/security.php';
require_once BASE_PATH . '/config/security.php';

// ---------------------------------------------------------------
// Load internal libraries
// ---------------------------------------------------------------
require_once BASE_PATH . '/lib/session.php';
require_once BASE_PATH . '/app/services/NonceService.php';
require_once BASE_PATH . '/app/services/AuthService.php';
require_once BASE_PATH . '/app/db/challenges.php';

// ---------------------------------------------------------------
// Bootstrap complete
// ---------------------------------------------------------------
