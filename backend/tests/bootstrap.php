<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Load test environment variables (separate from prod)
if (file_exists(__DIR__ . '/../.env.test')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..', '.env.test');
    $dotenv->load();
}

// Connect to the test database
require __DIR__ . '/../config/db.php';

// Verify connection points to test schema
if (!isset($pdo) || !$pdo instanceof PDO) {
    throw new RuntimeException('PDO connection was not initialized in config/db.php');
}
if (strpos($_ENV['DB_NAME'] ?? '', 'test') === false) {
    fwrite(STDERR, "⚠️  Warning: running tests against non-test DB (" . ($_ENV['DB_NAME'] ?? 'unknown') . ")\n");
}

// Disable foreign key checks for test safety
$pdo->exec('SET FOREIGN_KEY_CHECKS=0;');

// Make PDO globally accessible to all tests
$GLOBALS['pdo'] = $pdo;
