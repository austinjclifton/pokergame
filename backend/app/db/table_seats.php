<?php
// backend/app/db/table_seats.php
// -----------------------------------------------------------------------------
// Data access layer for TABLE_SEATS table.
// All functions here are pure SQL operations: no business logic, no validation.
// -----------------------------------------------------------------------------

declare(strict_types=1);

/**
 * Table schema:
 * 
 * CREATE TABLE table_seats (
 *     id INT AUTO_INCREMENT PRIMARY KEY,
 *     table_id INT NOT NULL,
 *     seat_no TINYINT NOT NULL,
 *     user_id INT NULL,
 *     joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 *     left_at TIMESTAMP NULL,
 *     UNIQUE KEY (table_id, seat_no),
 *     FOREIGN KEY (table_id) REFERENCES tables(id) ON DELETE CASCADE,
 *     FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
 *     INDEX (table_id),
 *     INDEX (user_id)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 */

// -----------------------------------------------------------------------------
// INSERT/UPDATE operations
// -----------------------------------------------------------------------------

/**
 * Seat a player at a table.
 * 
 * @param PDO $pdo Database connection
 * @param int $tableId Table ID
 * @param int $seatNo Seat number (1-based)
 * @param int $userId User ID
 * @return bool True on success, false on failure
 */
function db_seat_player(PDO $pdo, int $tableId, int $seatNo, int $userId): bool
{
    $stmt = $pdo->prepare("
        INSERT INTO table_seats (table_id, seat_no, user_id, joined_at)
        VALUES (:table_id, :seat_no, :user_id, NOW())
        ON DUPLICATE KEY UPDATE
            user_id = :user_id_update,
            joined_at = NOW(),
            left_at = NULL
    ");
    
    return $stmt->execute([
        'table_id' => $tableId,
        'seat_no' => $seatNo,
        'user_id' => $userId,
        'user_id_update' => $userId,
    ]);
}

/**
 * Unseat a player from a table (set left_at timestamp).
 * 
 * @param PDO $pdo Database connection
 * @param int $tableId Table ID
 * @param int $userId User ID
 * @return bool True on success, false on failure
 */
function db_unseat_player(PDO $pdo, int $tableId, int $userId): bool
{
    $stmt = $pdo->prepare("
        UPDATE table_seats
        SET left_at = NOW()
        WHERE table_id = :table_id
        AND user_id = :user_id
        AND left_at IS NULL
    ");
    
    return $stmt->execute([
        'table_id' => $tableId,
        'user_id' => $userId,
    ]);
}

// -----------------------------------------------------------------------------
// SELECT operations
// -----------------------------------------------------------------------------

/**
 * Get all seats for a table (including empty seats) with usernames.
 * 
 * @param PDO $pdo Database connection
 * @param int $tableId Table ID
 * @return array<array<string, mixed>> Array of seat records with username
 */
function db_get_table_seats(PDO $pdo, int $tableId): array
{
    $stmt = $pdo->prepare("
        SELECT 
            ts.id, 
            ts.table_id, 
            ts.seat_no, 
            ts.user_id, 
            ts.joined_at, 
            ts.left_at,
            u.username
        FROM table_seats ts
        LEFT JOIN users u ON ts.user_id = u.id
        WHERE ts.table_id = :table_id
        ORDER BY ts.seat_no ASC
    ");
    
    $stmt->execute(['table_id' => $tableId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Find a seat by user ID at a specific table.
 * 
 * @param PDO $pdo Database connection
 * @param int $tableId Table ID
 * @param int $userId User ID
 * @return array<string, mixed>|null Seat data or null if not found
 */
function db_find_seat_by_user(PDO $pdo, int $tableId, int $userId): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, table_id, seat_no, user_id, joined_at, left_at
        FROM table_seats
        WHERE table_id = :table_id
        AND user_id = :user_id
        AND left_at IS NULL
        LIMIT 1
    ");
    
    $stmt->execute([
        'table_id' => $tableId,
        'user_id' => $userId,
    ]);
    
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Find the active table (with active game) for a user.
 * Returns table_id if user has a seat with left_at IS NULL and there's an active game.
 * 
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @return int|null Table ID or null if not found
 */
function db_find_active_table_for_user(PDO $pdo, int $userId): ?int
{
    $stmt = $pdo->prepare("
        SELECT ts.table_id
        FROM table_seats ts
        INNER JOIN games g ON ts.table_id = g.table_id
        WHERE ts.user_id = :user_id
        AND ts.left_at IS NULL
        AND g.status = 'ACTIVE'
        ORDER BY g.started_at DESC
        LIMIT 1
    ");
    
    $stmt->execute(['user_id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $row ? (int)$row['table_id'] : null;
}

/**
 * Clear all seats at a table (set left_at timestamp for all active seats).
 * Used when match ends to return players to lobby.
 * 
 * @param PDO $pdo Database connection
 * @param int $tableId Table ID
 * @return bool True on success, false on failure
 */
function db_clear_table_seats(PDO $pdo, int $tableId): bool
{
    $stmt = $pdo->prepare("
        UPDATE table_seats
        SET left_at = NOW()
        WHERE table_id = :table_id
        AND left_at IS NULL
    ");
    
    return $stmt->execute(['table_id' => $tableId]);
}

