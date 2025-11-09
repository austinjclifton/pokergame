<?php
// backend/app/db/games.php
declare(strict_types=1);

/**
 * Database functions for games table.
 * Based on the actual schema with proper columns.
 */

function db_create_game(PDO $pdo, int $p1, int $p2): int {
    // Create a new game with default settings
    $sql = "INSERT INTO games (status, small_blind, big_blind, starting_stack, turn_timer_secs, created_at)
            VALUES ('waiting', 10, 20, 1000, 30, NOW())";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $gameId = (int)$pdo->lastInsertId();
    
    // Add both players to the game
    db_add_player_to_game($pdo, $gameId, $p1, 1);
    db_add_player_to_game($pdo, $gameId, $p2, 2);
    
    return $gameId;
}

function db_add_player_to_game(PDO $pdo, int $gameId, int $userId, int $seat): bool {
    $sql = "INSERT INTO game_players (game_id, user_id, seat, stack, joined_at, status)
            VALUES (?, ?, ?, ?, NOW(), 'active')";
    $stmt = $pdo->prepare($sql);
    return $stmt->execute([$gameId, $userId, $seat, 1000]);
}
