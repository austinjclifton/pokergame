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
    // CRITICAL: Force immediate output - this MUST appear in terminal
    fwrite(STDERR, "\n*** db_create_game() CALLED ***\n");
    fflush(STDERR);
    
    // Force output immediately (no buffering)
    if (ob_get_level() > 0) {
        ob_flush();
    }
    
    // Verify function signature
    $ref = new ReflectionFunction(__FUNCTION__);
    $paramCount = $ref->getNumberOfParameters();
    fwrite(STDERR, "[db_create_game] FUNCTION CALLED - Signature has {$paramCount} parameters\n");
    fwrite(STDERR, "[db_create_game] Received args: tableId={$tableId} (type: " . gettype($tableId) . "), dealerSeat={$dealerSeat}, sbSeat={$sbSeat}, bbSeat={$bbSeat}, deckSeed={$deckSeed}\n");
    fflush(STDERR);
    
    try {
        // Use positional parameters - include status as a parameter to avoid any issues
        $sql = "INSERT INTO games (table_id, dealer_seat, sb_seat, bb_seat, deck_seed, status) VALUES (?, ?, ?, ?, ?, ?)";
        
        $params = [$tableId, $dealerSeat, $sbSeat, $bbSeat, $deckSeed, 'ACTIVE'];
        $placeholderCount = substr_count($sql, '?');
        $paramCount = count($params);
        
        // Log for debugging (to stderr so it appears in server terminal)
        fwrite(STDERR, "[db_create_game] SQL: {$sql}\n");
        fwrite(STDERR, "[db_create_game] Placeholders in SQL: {$placeholderCount}\n");
        fwrite(STDERR, "[db_create_game] Parameters array count: {$paramCount}\n");
        fwrite(STDERR, "[db_create_game] Params array: " . json_encode($params, JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n");
        fwrite(STDERR, "[db_create_game] Param types: " . implode(', ', array_map('gettype', $params)) . "\n");
        
        error_log("[db_create_game] SQL: {$sql}");
        error_log("[db_create_game] Placeholders: {$placeholderCount}, Parameters: {$paramCount}");
        error_log("[db_create_game] Params: " . json_encode($params));
        
        if ($placeholderCount !== $paramCount) {
            $msg = "SQL parameter count mismatch: {$placeholderCount} placeholders but {$paramCount} parameters";
            fwrite(STDERR, "[db_create_game] ERROR: {$msg}\n");
            error_log("[db_create_game] Parameter mismatch: {$placeholderCount} placeholders but {$paramCount} parameters");
            throw new \RuntimeException($msg);
        }
        
        fwrite(STDERR, "[db_create_game] Preparing statement...\n");
        $stmt = $pdo->prepare($sql);
        if ($stmt === false) {
            $error = $pdo->errorInfo();
            $msg = "Failed to prepare statement: " . ($error[2] ?? 'Unknown error');
            fwrite(STDERR, "[db_create_game] ERROR: {$msg}\n");
            throw new \RuntimeException($msg);
        }
        
        fwrite(STDERR, "[db_create_game] Executing with " . count($params) . " parameters...\n");
        $result = $stmt->execute($params);
        
        if (!$result) {
            $errorInfo = $stmt->errorInfo();
            error_log("[db_create_game] Execute returned false. Error: " . ($errorInfo[2] ?? 'Unknown error'));
            return null;
        }
        
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log("[db_create_game] PDO Exception: " . $e->getMessage());
        error_log("[db_create_game] SQL: {$sql}");
        error_log("[db_create_game] Params: " . json_encode($params ?? []));
        throw $e;
    } catch (\Throwable $e) {
        error_log("[db_create_game] Exception: " . $e->getMessage());
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
