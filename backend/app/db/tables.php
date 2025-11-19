<?php
// backend/app/db/tables.php
// -----------------------------------------------------------------------------
// Data access layer for TABLES table.
// All functions here are pure SQL operations: no business logic, no validation.
// -----------------------------------------------------------------------------

declare(strict_types=1);

/**
 * Table schema:
 * 
 * CREATE TABLE tables (
 *     id INT AUTO_INCREMENT PRIMARY KEY,
 *     name VARCHAR(100) NOT NULL,
 *     max_seats TINYINT NOT NULL DEFAULT 6,
 *     small_blind INT NOT NULL,
 *     big_blind INT NOT NULL,
 *     ante INT NOT NULL DEFAULT 0,
 *     status ENUM('OPEN','IN_GAME','CLOSED') DEFAULT 'OPEN',
 *     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 *     INDEX (status),
 *     INDEX (created_at)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 */

// -----------------------------------------------------------------------------
// INSERT operations
// -----------------------------------------------------------------------------

/**
 * Create a new poker table.
 * 
 * @param PDO $pdo Database connection
 * @param string $name Table name
 * @param int $maxSeats Maximum number of seats (default: 6)
 * @param int $smallBlind Small blind amount
 * @param int $bigBlind Big blind amount
 * @param int $ante Ante amount (default: 0)
 * @return int|null Table ID on success, null on failure
 */
function db_create_table(PDO $pdo, string $name, int $maxSeats, int $smallBlind, int $bigBlind, int $ante = 0): ?int
{
    $stmt = $pdo->prepare("
        INSERT INTO tables (name, max_seats, small_blind, big_blind, ante, status)
        VALUES (:name, :max_seats, :small_blind, :big_blind, :ante, 'OPEN')
    ");
    
    $result = $stmt->execute([
        'name' => $name,
        'max_seats' => $maxSeats,
        'small_blind' => $smallBlind,
        'big_blind' => $bigBlind,
        'ante' => $ante,
    ]);
    
    if (!$result) {
        return null;
    }
    
    return (int)$pdo->lastInsertId();
}

// -----------------------------------------------------------------------------
// SELECT operations
// -----------------------------------------------------------------------------

/**
 * Get a table by ID.
 * 
 * @param PDO $pdo Database connection
 * @param int $tableId Table ID
 * @return array<string, mixed>|null Table data or null if not found
 */
function db_get_table_by_id(PDO $pdo, int $tableId): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, name, max_seats, small_blind, big_blind, ante, status, created_at
        FROM tables
        WHERE id = :table_id
        LIMIT 1
    ");
    
    $stmt->execute(['table_id' => $tableId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $row ?: null;
}

/**
 * List all active tables (status = 'OPEN' or 'IN_GAME').
 * 
 * @param PDO $pdo Database connection
 * @return array<array<string, mixed>> Array of table records
 */
function db_list_active_tables(PDO $pdo): array
{
    $stmt = $pdo->prepare("
        SELECT id, name, max_seats, small_blind, big_blind, ante, status, created_at
        FROM tables
        WHERE status IN ('OPEN', 'IN_GAME')
        ORDER BY created_at DESC
    ");
    
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// -----------------------------------------------------------------------------
// UPDATE operations
// -----------------------------------------------------------------------------

/**
 * Update table status.
 * 
 * @param PDO $pdo Database connection
 * @param int $tableId Table ID
 * @param string $status New status ('OPEN', 'IN_GAME', or 'CLOSED')
 * @return bool True on success, false on failure
 */
function db_update_table_status(PDO $pdo, int $tableId, string $status): bool
{
    // Validate status value
    $validStatuses = ['OPEN', 'IN_GAME', 'CLOSED'];
    if (!in_array($status, $validStatuses, true)) {
        return false;
    }
    
    $stmt = $pdo->prepare("
        UPDATE tables
        SET status = :status
        WHERE id = :table_id
    ");
    
    return $stmt->execute([
        'status' => $status,
        'table_id' => $tableId,
    ]);
}

