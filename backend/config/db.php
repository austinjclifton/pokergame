<?php
// Centralized PDO connection setup for the HTTP and WebSocket backends.

declare(strict_types=1);

$databaseVariables = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'];
$databaseConfig = [];

foreach ($databaseVariables as $key) {
    $value = getenv($key);
    if ($value === false) {
        throw new RuntimeException("Missing required database setting: {$key}");
    }
    $databaseConfig[$key] = $value;
}

$DB_HOST = trim($databaseConfig['DB_HOST']);
$DB_NAME = trim($databaseConfig['DB_NAME']);
$DB_USER = trim($databaseConfig['DB_USER']);
$DB_PASS = $databaseConfig['DB_PASS'];
$DB_PORT_RAW = trim($databaseConfig['DB_PORT']);

if ($DB_HOST === '' || $DB_NAME === '' || $DB_USER === '') {
    throw new RuntimeException('DB_HOST, DB_NAME, and DB_USER must not be empty');
}

if (filter_var($DB_PORT_RAW, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
    throw new RuntimeException('DB_PORT must be an integer between 1 and 65535');
}
$DB_PORT = (int) $DB_PORT_RAW;

if (!preg_match('/^[A-Za-z0-9_]+$/', $DB_NAME)) {
    throw new RuntimeException('DB_NAME must contain only letters, numbers, and underscores');
}

if (getenv('APP_ENV') === 'testing' && !str_ends_with($DB_NAME, '_test')) {
    throw new RuntimeException('Testing requires a database name ending in _test');
}

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
        ]
    );
} catch (PDOException $e) {
    if (!headers_sent()) {
        header('Content-Type: application/json');
        http_response_code(500);
    }

    error_log('[db.php] Database connection failed: ' . $e->getMessage());

    echo json_encode(['ok' => false, 'error' => 'Database connection failed']);
    exit;
}
