<?php
// backend/config/db.php
// Centralized PDO connection setup for PokerGame backend.

// -----------------------------------------------------------------------------
// Adjust connection settings automatically depending on environment.
// On localhost: use root user, no password.
// On RLES / production: set environment vars securely.
// -----------------------------------------------------------------------------
$DB_HOST = getenv('DB_HOST') ?: '127.0.0.1';
$DB_NAME = getenv('DB_NAME') ?: 'pokergame';
$DB_USER = getenv('DB_USER') ?: 'root';
$DB_PASS = getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    
    // Log the actual error for debugging
    error_log('[db.php] Database connection failed: ' . $e->getMessage());
    
    // Only expose details in debug mode
    $isDebug = getenv('DEBUG') === '1' || getenv('APP_ENV') === 'development';
    $response = ['ok' => false, 'error' => 'Database connection failed'];
    if ($isDebug) {
        $response['details'] = $e->getMessage();
    }
    
    echo json_encode($response);
    exit;
}
