<?php
// backend/config/db.php
// Centralized PDO connection setup for PokerGame backend.

declare(strict_types=1);

// MySQL connection settings for RIT Apache VM
// Can be overridden via environment variables for local development
// Note: getenv() returns false if var doesn't exist, empty string if it exists but is empty
$DB_HOST = (getenv('DB_HOST') !== false) ? getenv('DB_HOST') : '127.0.0.1';
$DB_NAME = (getenv('DB_NAME') !== false) ? getenv('DB_NAME') : 'pokergame';
$DB_USER = (getenv('DB_USER') !== false) ? getenv('DB_USER') : 'root';
$DB_PASS = (getenv('DB_PASS') !== false) ? getenv('DB_PASS') : 'student';

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,

            // Ensure MySQL always speaks utf8mb4
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
