<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This migration is CLI-only.\n");
    exit(1);
}

$options = getopt('', ['confirm-database:', 'confirm:']);
$expectedDatabase = (string) ($options['confirm-database'] ?? '');
if ($expectedDatabase === '' || ($options['confirm'] ?? '') !== 'phase-2-auth-security') {
    fwrite(STDERR, "Refusing migration. Supply --confirm-database=<exact DB_NAME> --confirm=phase-2-auth-security\n");
    exit(1);
}

require __DIR__ . '/../../config/db.php';
$actualDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if (!hash_equals($expectedDatabase, $actualDatabase)) {
    fwrite(STDERR, "Refusing migration: connected database does not match --confirm-database.\n");
    exit(1);
}

function migrationColumnExists(PDO $pdo, string $database, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.columns
         WHERE table_schema = :database AND table_name = :table AND column_name = :column'
    );
    $stmt->execute(['database' => $database, 'table' => $table, 'column' => $column]);
    return (bool) $stmt->fetchColumn();
}

if (!migrationColumnExists($pdo, $actualDatabase, 'users', 'is_admin')) {
    $pdo->exec('ALTER TABLE users ADD COLUMN is_admin BOOLEAN NOT NULL DEFAULT FALSE AFTER password_hash');
}

if (!migrationColumnExists($pdo, $actualDatabase, 'sessions', 'token_hash')) {
    $pdo->exec('ALTER TABLE sessions ADD COLUMN token_hash CHAR(64) NULL AFTER user_id');
    $pdo->exec("UPDATE sessions SET token_hash = SHA2(CONCAT(UUID(), ':', id, ':', RAND()), 256), revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP())");
    $pdo->exec('ALTER TABLE sessions MODIFY token_hash CHAR(64) NOT NULL, ADD UNIQUE KEY uq_sessions_token_hash (token_hash)');
}
if (!migrationColumnExists($pdo, $actualDatabase, 'sessions', 'rotated_at')) {
    $pdo->exec('ALTER TABLE sessions ADD COLUMN rotated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_at');
}
if (migrationColumnExists($pdo, $actualDatabase, 'sessions', 'refresh_token')) {
    $pdo->exec('ALTER TABLE sessions DROP COLUMN refresh_token');
}

if (!migrationColumnExists($pdo, $actualDatabase, 'csrf_nonces', 'nonce_hash')) {
    $pdo->exec('ALTER TABLE csrf_nonces ADD COLUMN nonce_hash CHAR(64) NULL AFTER session_id, ADD COLUMN purpose VARCHAR(32) NULL AFTER nonce_hash');
    $pdo->exec("UPDATE csrf_nonces SET nonce_hash = SHA2(nonce, 256), purpose = 'expired-migration', used_at = COALESCE(used_at, UTC_TIMESTAMP())");
    $pdo->exec('ALTER TABLE csrf_nonces MODIFY nonce_hash CHAR(64) NOT NULL, MODIFY purpose VARCHAR(32) NOT NULL, ADD UNIQUE KEY uq_csrf_nonce_hash (nonce_hash), DROP COLUMN nonce');
}

if (migrationColumnExists($pdo, $actualDatabase, 'password_resets', 'token')) {
    $pdo->exec('ALTER TABLE password_resets ADD COLUMN token_hash CHAR(64) NULL AFTER user_id');
    $pdo->exec('UPDATE password_resets SET token_hash = SHA2(token, 256), used_at = COALESCE(used_at, UTC_TIMESTAMP())');
    $pdo->exec('ALTER TABLE password_resets MODIFY token_hash CHAR(64) NOT NULL, ADD UNIQUE KEY uq_password_reset_token_hash (token_hash), DROP COLUMN token');
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS auth_rate_limits (
       key_hash CHAR(64) PRIMARY KEY,
       window_started_at DATETIME NOT NULL,
       request_count INT UNSIGNED NOT NULL,
       blocked_until DATETIME NULL,
       updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       INDEX (blocked_until),
       INDEX (updated_at)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

fwrite(STDOUT, "Phase 2 authentication migration complete for the confirmed database. Existing sessions and nonces were revoked.\n");
