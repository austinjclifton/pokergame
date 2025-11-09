<?php
// backend/app/db/users.php
// -----------------------------------------------------------------------------
// Data access layer for USERS table.
// All functions here are pure SQL operations: no business logic, no validation.
// -----------------------------------------------------------------------------

declare(strict_types=1);

// -----------------------------------------------------------------------------
// SELECT operations
// -----------------------------------------------------------------------------

/**
 * Fetch a user row by username.
 * Username is canonicalized before lookup (case-insensitive).
 */
function db_get_user_by_username(PDO $pdo, string $username): ?array {
    require_once __DIR__ . '/../../lib/security.php';
    $canonical = canonicalize_username($username);
    
    $stmt = $pdo->prepare("
        SELECT id, username, email, password_hash
        FROM users
        WHERE username = :u
        LIMIT 1
    ");
    $stmt->execute(['u' => $canonical]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Fetch a user row by user ID.
 */
function db_get_user_by_id(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare("
        SELECT id, username, email, created_at
        FROM users
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Fetch only a user's username (for lightweight lookups in sockets, etc.).
 */
function db_get_username_by_id(PDO $pdo, int $userId): ?string {
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row['username'] ?? null;
}

// -----------------------------------------------------------------------------
// INSERT operations
// -----------------------------------------------------------------------------

/**
 * Insert a new user and return its ID.
 * Username and email are canonicalized before storage.
 */
function db_insert_user(PDO $pdo, string $username, string $email, string $passwordHash): int {
    require_once __DIR__ . '/../../lib/security.php';
    $canonicalUsername = canonicalize_username($username);
    $canonicalEmail = canonicalize_email($email);
    
    $stmt = $pdo->prepare("
        INSERT INTO users (username, email, password_hash, created_at)
        VALUES (:u, :e, :p, NOW())
    ");
    $stmt->execute(['u' => $canonicalUsername, 'e' => $canonicalEmail, 'p' => $passwordHash]);
    return (int)$pdo->lastInsertId();
}

// -----------------------------------------------------------------------------
// UPDATE operations
// -----------------------------------------------------------------------------

/**
 * Update a user's password hash (e.g., after rehashing).
 */
function db_update_user_password_hash(PDO $pdo, int $userId, string $newHash): void {
    $stmt = $pdo->prepare("UPDATE users SET password_hash = :h WHERE id = :id");
    $stmt->execute(['h' => $newHash, 'id' => $userId]);
}

/**
 * Update the last_login_at timestamp (used for presence or analytics).
 */
function db_update_user_last_seen(PDO $pdo, int $userId): void {
    $stmt = $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = :id");
    $stmt->execute(['id' => $userId]);
}

// -----------------------------------------------------------------------------
// EXISTS / VALIDATION checks
// -----------------------------------------------------------------------------

/**
 * Check if a username or email already exists.
 * Returns true if either value is taken.
 * Username and email are canonicalized before checking.
 */
function db_user_exists(PDO $pdo, string $username, string $email): bool {
    require_once __DIR__ . '/../../lib/security.php';
    $canonicalUsername = canonicalize_username($username);
    $canonicalEmail = canonicalize_email($email);
    
    $stmt = $pdo->prepare("
        SELECT id FROM users
        WHERE username = :u OR email = :e
        LIMIT 1
    ");
    $stmt->execute(['u' => $canonicalUsername, 'e' => $canonicalEmail]);
    return (bool)$stmt->fetch();
}
