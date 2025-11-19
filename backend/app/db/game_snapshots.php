<?php
// backend/app/db/game_snapshots.php
// -----------------------------------------------------------------------------
// Data access layer for game_snapshots table.
// All functions here are pure SQL operations: no business logic, no validation.
// -----------------------------------------------------------------------------

declare(strict_types=1);

/**
 * Table schema:
 * 
 * CREATE TABLE game_snapshots (
 *     id INT AUTO_INCREMENT PRIMARY KEY,
 *     game_id INT NOT NULL,
 *     version INT NOT NULL,
 *     state_json JSON NOT NULL,
 *     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE KEY (game_id, version),
 *     INDEX (game_id),
 *     INDEX (game_id, version),
 *     FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 */

/**
 * Insert a game state snapshot
 * 
 * @param PDO $pdo Database connection
 * @param int $gameId Game ID
 * @param int $version Game version
 * @param array<string, mixed> $state State data
 * @return bool True on success
 */
function db_insert_snapshot(PDO $pdo, int $gameId, int $version, array $state): bool
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO game_snapshots (game_id, version, state_json)
            VALUES (:game_id, :version, :state_json)
            ON DUPLICATE KEY UPDATE
                state_json = VALUES(state_json),
                created_at = CURRENT_TIMESTAMP
        ");
        
        $stateJson = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        $stmt->execute([
            'game_id' => $gameId,
            'version' => $version,
            'state_json' => $stateJson,
        ]);
        
        return true;
    } catch (JsonException $e) {
        error_log("Failed to encode state JSON: " . $e->getMessage());
        return false;
    } catch (PDOException $e) {
        error_log("Failed to insert snapshot: " . $e->getMessage());
        return false;
    }
}

/**
 * Get the latest snapshot for a game
 * 
 * @param PDO $pdo Database connection
 * @param int $gameId Game ID
 * @return array{version: int, state: array<string, mixed>}|null Snapshot data or null if not found
 */
function db_get_latest_snapshot(PDO $pdo, int $gameId): ?array
{
    $stmt = $pdo->prepare("
        SELECT version, state_json
        FROM game_snapshots
        WHERE game_id = :game_id
        ORDER BY version DESC
        LIMIT 1
    ");
    $stmt->execute(['game_id' => $gameId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$row) {
        return null;
    }
    
    try {
        $state = json_decode($row['state_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($state)) {
            return null;
        }
        
        return [
            'version' => (int)$row['version'],
            'state' => $state,
        ];
    } catch (JsonException $e) {
        error_log("Failed to decode snapshot JSON: " . $e->getMessage());
        return null;
    }
}

/**
 * Get a snapshot by specific version
 * 
 * @param PDO $pdo Database connection
 * @param int $gameId Game ID
 * @param int $version Version number
 * @return array{version: int, state: array<string, mixed>}|null Snapshot data or null if not found
 */
function db_get_snapshot_by_version(PDO $pdo, int $gameId, int $version): ?array
{
    $stmt = $pdo->prepare("
        SELECT version, state_json
        FROM game_snapshots
        WHERE game_id = :game_id AND version = :version
        LIMIT 1
    ");
    $stmt->execute([
        'game_id' => $gameId,
        'version' => $version,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$row) {
        return null;
    }
    
    try {
        $state = json_decode($row['state_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($state)) {
            return null;
        }
        
        return [
            'version' => (int)$row['version'],
            'state' => $state,
        ];
    } catch (JsonException $e) {
        error_log("Failed to decode snapshot JSON: " . $e->getMessage());
        return null;
    }
}

