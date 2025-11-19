<?php
// backend/app/db/game_actions.php
// -----------------------------------------------------------------------------
// Data access layer for game actions (actions table).
// All functions here are pure SQL operations: no business logic, no validation.
// -----------------------------------------------------------------------------

declare(strict_types=1);

/**
 * Insert a game action
 * 
 * @param PDO $pdo Database connection
 * @param array{
 *   hand_id: int,
 *   game_id: int,
 *   user_id: int,
 *   hand_no: int,
 *   seq_no: int,
 *   street: string,
 *   action_type: string,
 *   amount: int,
 *   balance_before: int,
 *   balance_after: int,
 *   action_nonce: string
 * } $data Action data
 * @return int The ID of the inserted action
 */
function db_insert_game_action(PDO $pdo, array $data): int
{
    $stmt = $pdo->prepare("
        INSERT INTO actions (
            hand_id, game_id, user_id, hand_no, seq_no,
            street, action_type, amount,
            balance_before, balance_after, action_nonce
        ) VALUES (
            :hand_id, :game_id, :user_id, :hand_no, :seq_no,
            :street, :action_type, :amount,
            :balance_before, :balance_after, :action_nonce
        )
    ");
    
    $stmt->execute([
        'hand_id' => $data['hand_id'],
        'game_id' => $data['game_id'],
        'user_id' => $data['user_id'],
        'hand_no' => $data['hand_no'],
        'seq_no' => $data['seq_no'],
        'street' => $data['street'],
        'action_type' => $data['action_type'],
        'amount' => $data['amount'],
        'balance_before' => $data['balance_before'],
        'balance_after' => $data['balance_after'],
        'action_nonce' => $data['action_nonce'],
    ]);
    
    return (int)$pdo->lastInsertId();
}

/**
 * Get the next sequence number for a hand
 * 
 * @param PDO $pdo Database connection
 * @param int $handId Hand ID
 * @return int Next sequence number
 */
function db_get_next_seq_no(PDO $pdo, int $handId): int
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(MAX(seq_no), 0) + 1 as next_seq
        FROM actions
        WHERE hand_id = :hand_id
    ");
    $stmt->execute(['hand_id' => $handId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int)($row['next_seq'] ?? 1);
}

/**
 * Get all actions for a hand (for replay/state reconstruction)
 * 
 * @param PDO $pdo Database connection
 * @param int $handId Hand ID
 * @return array<array<string, mixed>>
 */
function db_get_hand_actions(PDO $pdo, int $handId): array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM actions
        WHERE hand_id = :hand_id
        ORDER BY seq_no ASC
    ");
    $stmt->execute(['hand_id' => $handId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Increment game version
 * Note: If version column doesn't exist, this will use updated_at timestamp as version
 * 
 * @param PDO $pdo Database connection
 * @param int $gameId Game ID
 * @return int New version number (timestamp if version column doesn't exist)
 */
function db_increment_game_version(PDO $pdo, int $gameId): int
{
    // Check if version column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM games LIKE 'version'");
    $hasVersion = $stmt->rowCount() > 0;
    
    if ($hasVersion) {
        $stmt = $pdo->prepare("
            UPDATE games
            SET version = version + 1
            WHERE id = :game_id
        ");
        $stmt->execute(['game_id' => $gameId]);
        
        // Get the new version
        $stmt = $pdo->prepare("SELECT version FROM games WHERE id = :game_id");
        $stmt->execute(['game_id' => $gameId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['version'] ?? 0);
    } else {
        // Fallback: use timestamp as version
        $stmt = $pdo->prepare("
            UPDATE games
            SET updated_at = NOW()
            WHERE id = :game_id
        ");
        $stmt->execute(['game_id' => $gameId]);
        
        // Return timestamp as version
        $stmt = $pdo->prepare("SELECT UNIX_TIMESTAMP(updated_at) as version FROM games WHERE id = :game_id");
        $stmt->execute(['game_id' => $gameId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['version'] ?? time());
    }
}

/**
 * Get game state snapshot
 * 
 * @param PDO $pdo Database connection
 * @param int $gameId Game ID
 * @return array<string, mixed>|null Game state snapshot or null if not found
 */
function db_get_game_state_snapshot(PDO $pdo, int $gameId): ?array
{
    $stmt = $pdo->prepare("
        SELECT state_json, hand_id, hand_no, street, snapshot_type
        FROM game_state_snapshots
        WHERE game_id = :game_id
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute(['game_id' => $gameId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$row) {
        return null;
    }
    
    $stateJson = json_decode($row['state_json'], true);
    if (!is_array($stateJson)) {
        return null;
    }
    
    return [
        'state' => $stateJson,
        'hand_id' => (int)$row['hand_id'],
        'hand_no' => (int)$row['hand_no'],
        'street' => $row['street'],
        'snapshot_type' => $row['snapshot_type'],
    ];
}

/**
 * Save game state snapshot
 * 
 * @param PDO $pdo Database connection
 * @param int $gameId Game ID
 * @param array<string, mixed> $state State data
 * @param int|null $handId Hand ID (optional)
 * @param int|null $handNo Hand number (optional)
 * @param string|null $street Street name (optional)
 * @param string $snapshotType Snapshot type
 * @param string|null $reason Snapshot reason (optional)
 * @return int Snapshot ID
 */
function db_save_game_state_snapshot(
    PDO $pdo,
    int $gameId,
    array $state,
    ?int $handId = null,
    ?int $handNo = null,
    ?string $street = null,
    string $snapshotType = 'state_update',
    ?string $reason = null
): int {
    $stmt = $pdo->prepare("
        INSERT INTO game_state_snapshots (
            game_id, hand_id, hand_no, street,
            snapshot_type, snapshot_reason, state_json
        ) VALUES (
            :game_id, :hand_id, :hand_no, :street,
            :snapshot_type, :snapshot_reason, :state_json
        )
    ");
    
    $stmt->execute([
        'game_id' => $gameId,
        'hand_id' => $handId,
        'hand_no' => $handNo,
        'street' => $street,
        'snapshot_type' => $snapshotType,
        'snapshot_reason' => $reason,
        'state_json' => json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    
    return (int)$pdo->lastInsertId();
}

/**
 * Get actions since a specific version
 * 
 * @param PDO $pdo Database connection
 * @param int $gameId Game ID
 * @param int $sinceVersion Version to get actions after
 * @return array<array<string, mixed>> Array of action records
 */
function db_get_actions_since(PDO $pdo, int $gameId, int $sinceVersion): array
{
    // Get all actions for the current active hand
    // Note: In a production system, you'd want to track version per action
    // For now, we'll get all actions for the current hand
    $stmt = $pdo->prepare("
        SELECT a.*
        FROM actions a
        INNER JOIN game_hands h ON a.hand_id = h.id
        WHERE a.game_id = :game_id
        AND h.ended_at IS NULL
        ORDER BY a.seq_no ASC
    ");
    $stmt->execute(['game_id' => $gameId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Rebuild game state by replaying actions
 * 
 * @param PDO $pdo Database connection
 * @param int $gameId Game ID
 * @param GameService $engine Game service instance
 * @return array<string, mixed> Reconstructed state
 */
function db_rebuild_state(PDO $pdo, int $gameId, GameService $engine): array
{
    require_once __DIR__ . '/../services/game/GameService.php';
    require_once __DIR__ . '/game_snapshots.php';
    
    // Get game settings
    $stmt = $pdo->prepare("
        SELECT small_blind, big_blind, starting_stack
        FROM games
        WHERE id = :game_id
    ");
    $stmt->execute(['game_id' => $gameId]);
    $gameData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$gameData) {
        throw new RuntimeException("Game #{$gameId} not found");
    }
    
    // Get players
    $stmt = $pdo->prepare("
        SELECT seat, stack, user_id
        FROM game_players
        WHERE game_id = :game_id AND status = 'active'
        ORDER BY seat
    ");
    $stmt->execute(['game_id' => $gameId]);
    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($players) < 2) {
        throw new RuntimeException("Not enough players in game");
    }
    
    // Create user_id to seat mapping
    $userIdToSeat = [];
    $playerData = [];
    foreach ($players as $p) {
        $seat = (int)$p['seat'];
        $userId = (int)$p['user_id'];
        $userIdToSeat[$userId] = $seat;
        $playerData[] = [
            'seat' => $seat,
            'stack' => (int)$p['stack'],
        ];
    }
    
    // Start fresh hand
    $result = $engine->startHand($playerData);
    if (!$result['ok']) {
        throw new RuntimeException("Failed to start hand: " . ($result['message'] ?? 'Unknown error'));
    }
    
    // Get all actions for the current hand
    $stmt = $pdo->prepare("
        SELECT h.id as hand_id
        FROM game_hands h
        WHERE h.game_id = :game_id AND h.ended_at IS NULL
        ORDER BY h.hand_no DESC
        LIMIT 1
    ");
    $stmt->execute(['game_id' => $gameId]);
    $handRow = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$handRow) {
        // No active hand, return current state
        return $engine->getState();
    }
    
    $handId = (int)$handRow['hand_id'];
    $actions = db_get_hand_actions($pdo, $handId);
    
    // Apply actions sequentially
    foreach ($actions as $action) {
        try {
            $actionType = ActionType::from($action['action_type']);
            $userId = (int)$action['user_id'];
            
            if (!isset($userIdToSeat[$userId])) {
                error_log("User {$userId} not found in game");
                continue;
            }
            
            $seat = $userIdToSeat[$userId];
            $amount = (int)$action['amount'];
            
            // Skip post_sb, post_bb, post_ante as they're handled by startHand
            if (in_array($action['action_type'], ['post_sb', 'post_bb', 'post_ante'], true)) {
                continue;
            }
            
            $result = $engine->playerAction($seat, $actionType, $amount);
            
            if (!$result['ok']) {
                error_log("Failed to replay action seq {$action['seq_no']}: " . ($result['message'] ?? 'Unknown error'));
                // Continue anyway - some actions might fail due to state differences
                // Note: Phase transitions (flop/turn/river) are handled automatically by
                // GameService->advancePhaseIfNeeded() after each action
            }
        } catch (ValueError $e) {
            error_log("Invalid action type in replay: " . $action['action_type']);
            continue;
        }
    }
    
    return $engine->getState();
}

// -----------------------------------------------------------------------------
// Game Actions Table Functions (game_actions table)
// -----------------------------------------------------------------------------

/**
 * Table schema:
 * 
 * CREATE TABLE game_actions (
 *     id INT AUTO_INCREMENT PRIMARY KEY,
 *     game_id INT NOT NULL,
 *     seq INT NOT NULL,
 *     actor_seat TINYINT,
 *     action_type VARCHAR(20),
 *     amount INT,
 *     data_json JSON,
 *     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE KEY (game_id, seq),
 *     FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
 *     INDEX (game_id),
 *     INDEX (game_id, seq)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 */

/**
 * Insert a game action.
 * 
 * @param PDO $pdo Database connection
 * @param int $gameId Game ID
 * @param int $seq Sequence number
 * @param int|null $actorSeat Seat number of the actor (null for system actions)
 * @param string $actionType Action type (e.g., 'check', 'call', 'bet', 'fold', 'raise')
 * @param int $amount Amount for bet/raise actions
 * @param array<string, mixed> $data Additional action data as JSON
 * @return bool True on success, false on failure
 */
function db_insert_action(PDO $pdo, int $gameId, int $seq, ?int $actorSeat, string $actionType, int $amount, array $data = []): bool
{
    $stmt = $pdo->prepare("
        INSERT INTO game_actions (game_id, seq, actor_seat, action_type, amount, data_json)
        VALUES (:game_id, :seq, :actor_seat, :action_type, :amount, :data_json)
    ");
    
    $dataJson = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    
    return $stmt->execute([
        'game_id' => $gameId,
        'seq' => $seq,
        'actor_seat' => $actorSeat,
        'action_type' => $actionType,
        'amount' => $amount,
        'data_json' => $dataJson,
    ]);
}

/**
 * Get all actions for a game.
 * 
 * @param PDO $pdo Database connection
 * @param int $gameId Game ID
 * @return array<array<string, mixed>> Array of action records
 */
function db_get_actions(PDO $pdo, int $gameId): array
{
    $stmt = $pdo->prepare("
        SELECT id, game_id, seq, actor_seat, action_type, amount, data_json, created_at
        FROM game_actions
        WHERE game_id = :game_id
        ORDER BY seq ASC
    ");
    
    $stmt->execute(['game_id' => $gameId]);
    $actions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Decode JSON data for each action
    foreach ($actions as &$action) {
        if (!empty($action['data_json'])) {
            $decoded = json_decode($action['data_json'], true);
            $action['data'] = is_array($decoded) ? $decoded : [];
        } else {
            $action['data'] = [];
        }
        // Remove data_json field (decoded data is in 'data' field)
        unset($action['data_json']);
    }
    unset($action); // Unset reference
    
    return $actions;
}

/**
 * Get the last sequence number for a game.
 * 
 * @param PDO $pdo Database connection
 * @param int $gameId Game ID
 * @return int Last sequence number (0 if no actions exist)
 */
function db_get_last_seq(PDO $pdo, int $gameId): int
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(MAX(seq), 0) as last_seq
        FROM game_actions
        WHERE game_id = :game_id
    ");
    
    $stmt->execute(['game_id' => $gameId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return (int)($row['last_seq'] ?? 0);
}

