<?php
// backend/app/db/games.php
// -----------------------------------------------------------------------------
// Data access layer for GAMES table.
// All functions here are pure SQL operations: no business logic, no validation.
// -----------------------------------------------------------------------------

declare(strict_types=1);

/**
 * Table schema:
 * 
 * CREATE TABLE games (
 *     id INT AUTO_INCREMENT PRIMARY KEY,
 *     table_id INT NOT NULL,
 *     dealer_seat TINYINT,
 *     sb_seat TINYINT,
 *     bb_seat TINYINT,
 *     deck_seed INT,
 *     version INT DEFAULT 0,
 *     started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 *     ended_at TIMESTAMP NULL,
 *     status ENUM('ACTIVE','COMPLETE') DEFAULT 'ACTIVE',
 *     FOREIGN KEY (table_id) REFERENCES tables(id) ON DELETE CASCADE,
 *     INDEX (table_id),
 *     INDEX (status),
 *     INDEX (started_at)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 */

// -----------------------------------------------------------------------------
// INSERT operations
// -----------------------------------------------------------------------------

/**
 * Create a new game.
 * 
 * @param PDO $pdo Database connection
 * @param int $tableId Table ID
 * @param int $dealerSeat Dealer seat number
 * @param int $sbSeat Small blind seat number
 * @param int $bbSeat Big blind seat number
 * @param int $deckSeed Deck seed for deterministic shuffling
 * @return int|null Game ID on success, null on failure
 */
function db_create_game(PDO $pdo, int $tableId, int $dealerSeat, int $sbSeat, int $bbSeat, int $deckSeed): ?int
{
    try {
        // Use positional parameters - include status as a parameter to avoid any issues
        $sql = "INSERT INTO games (table_id, dealer_seat, sb_seat, bb_seat, deck_seed, status) VALUES (?, ?, ?, ?, ?, ?)";
        
        $params = [$tableId, $dealerSeat, $sbSeat, $bbSeat, $deckSeed, 'ACTIVE'];
        $placeholderCount = substr_count($sql, '?');
        $paramCount = count($params);
        
        if ($placeholderCount !== $paramCount) {
            $msg = "SQL parameter count mismatch: {$placeholderCount} placeholders but {$paramCount} parameters";
            throw new \RuntimeException($msg);
        }
        
        $stmt = $pdo->prepare($sql);
        if ($stmt === false) {
            $error = $pdo->errorInfo();
            $msg = "Failed to prepare statement: " . ($error[2] ?? 'Unknown error');
            throw new \RuntimeException($msg);
        }
        
        $result = $stmt->execute($params);
        
        if (!$result) {
            return null;
        }
        
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        throw $e;
    } catch (\Throwable $e) {
        throw $e;
    }
}

// -----------------------------------------------------------------------------
// SELECT operations
// -----------------------------------------------------------------------------

/**
 * Get the active game for a table.
 * 
 * @param PDO $pdo Database connection
 * @param int $tableId Table ID
 * @return array<string, mixed>|null Game data or null if not found
 */
function db_get_active_game(PDO $pdo, int $tableId): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, table_id, dealer_seat, sb_seat, bb_seat, deck_seed, version, 
               started_at, ended_at, status
        FROM games
        WHERE table_id = :table_id
        AND status = 'ACTIVE'
        ORDER BY started_at DESC
        LIMIT 1
    ");
    
    $stmt->execute(['table_id' => $tableId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $row ?: null;
}

// -----------------------------------------------------------------------------
// UPDATE operations
// -----------------------------------------------------------------------------

/**
 * Update game version.
 * 
 * @param PDO $pdo Database connection
 * @param int $gameId Game ID
 * @param int $newVersion New version number
 * @return bool True on success, false on failure
 */
function db_update_game_version(PDO $pdo, int $gameId, int $newVersion): bool
{
    $stmt = $pdo->prepare("
        UPDATE games
        SET version = :version
        WHERE id = :game_id
    ");
    
    return $stmt->execute([
        'version' => $newVersion,
        'game_id' => $gameId,
    ]);
}

/**
 * End a game (set status to 'COMPLETE' and ended_at timestamp).
 * 
 * @param PDO $pdo Database connection
 * @param int $gameId Game ID
 * @return bool True on success, false on failure
 */
function db_end_game(PDO $pdo, int $gameId): bool
{
    $stmt = $pdo->prepare("
        UPDATE games
        SET status = 'COMPLETE',
            ended_at = NOW()
        WHERE id = :game_id
    ");
    
    return $stmt->execute(['game_id' => $gameId]);
}

/**
 * Delete a game (hard delete).
 * Used when match ends to clean up completely.
 * 
 * @param PDO $pdo Database connection
 * @param int $gameId Game ID
 * @return bool True on success, false on failure
 */
function db_delete_game(PDO $pdo, int $gameId): bool
{
    $stmt = $pdo->prepare("DELETE FROM games WHERE id = :game_id");
    return $stmt->execute(['game_id' => $gameId]);
}
