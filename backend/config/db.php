<?php
// backend/config/db.php
// Centralized PDO connection setup for PokerGame backend.

declare(strict_types=1);

// ---------------------------------------------------------------
// Detect LOCAL vs VM environment
// ---------------------------------------------------------------
// Detect LOCAL vs VM environment --------------------------------------------
$runningInCli = php_sapi_name() === 'cli';

$IS_LOCAL =
    $runningInCli ||                                                // Fix for WS server
    php_sapi_name() === 'cli-server' ||
    str_contains($_SERVER['HTTP_HOST'] ?? '', 'localhost') ||
    str_contains($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1');

if ($IS_LOCAL) {
    $DB_HOST = '127.0.0.1';
    $DB_NAME = 'pokergame';
    $DB_USER = 'root';
    $DB_PASS = '';   // local DB has no password
} else {
    $DB_HOST = getenv('DB_HOST') ?: '127.0.0.1';
    $DB_NAME = getenv('DB_NAME') ?: 'pokergame';
    $DB_USER = getenv('DB_USER') ?: 'root';
    $DB_PASS = getenv('DB_PASS') ?: 'student';
}

// ---------------------------------------------------------------
// Construct PDO
// ---------------------------------------------------------------
try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
        ]
    );
} catch (PDOException $e) {

    if (!headers_sent()) {
        header('Content-Type: application/json');
        http_response_code(500);
    }

    error_log('[db.php] Database connection failed: ' . $e->getMessage());

    $isDebug = getenv('DEBUG') === '1' || getenv('APP_ENV') === 'development';

    $response = ['ok' => false, 'error' => 'Database connection failed'];
    if ($isDebug) {
        $response['details'] = $e->getMessage();
    }

    echo json_encode($response);
    exit;
}
