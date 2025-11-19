<?php
/**
 * Reset database script - drops and recreates the poker database
 * Usage: php backend/app/db/reset_database.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

echo "=========================================\n";
echo "Resetting Poker Database\n";
echo "=========================================\n";
echo "Host: {$DB_HOST}\n";
echo "Database: {$DB_NAME}\n";
echo "User: {$DB_USER}\n";
echo "\n";
echo "⚠️  WARNING: This will DROP and recreate the database!\n";
echo "   All data will be lost!\n";
echo "\n";

// Check if running in CLI
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

echo "Type 'yes' to continue: ";
$handle = fopen("php://stdin", "r");
$confirm = trim(fgets($handle));
fclose($handle);

if ($confirm !== 'yes') {
    echo "Cancelled.\n";
    exit(1);
}

try {
    // Connect without database name first
    $pdo = new PDO(
        "mysql:host={$DB_HOST};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]
    );

    echo "\nDropping database...\n";
    $pdo->exec("DROP DATABASE IF EXISTS `{$DB_NAME}`");

    echo "Creating database...\n";
    $pdo->exec("CREATE DATABASE `{$DB_NAME}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    // Connect to the new database
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]
    );

    echo "Loading schema...\n";
    $schemaFile = __DIR__ . '/poker_schema.sql';
    if (!file_exists($schemaFile)) {
        throw new RuntimeException("Schema file not found: {$schemaFile}");
    }

    $schema = file_get_contents($schemaFile);
    if ($schema === false) {
        throw new RuntimeException("Failed to read schema file");
    }

    // Execute schema (split by semicolons, but be careful with stored procedures)
    // For simplicity, execute the whole file
    $pdo->exec($schema);

    echo "\n✅ Database reset complete!\n";
    echo "\n";

} catch (PDOException $e) {
    echo "\n❌ Database error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Throwable $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

