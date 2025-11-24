<?php
// backend/app/db/game_actions.php
// -----------------------------------------------------------------------------
// Minimal DB utility: only game version incrementing is needed.
// All old action/table logic has been removed from the architecture.
// -----------------------------------------------------------------------------

declare(strict_types=1);

/**
 * Increment the game version and return the new version.
 *
 * @param PDO $pdo
 * @param int $gameId
 * @return int
 */
function db_increment_game_version(PDO $pdo, int $gameId): int
{
    $stmt = $pdo->prepare("
        UPDATE games
        SET version = version + 1
        WHERE id = :game_id
    ");
    $stmt->execute(['game_id' => $gameId]);

    $stmt = $pdo->prepare("
        SELECT version
        FROM games
        WHERE id = :game_id
    ");
    $stmt->execute(['game_id' => $gameId]);

    return (int)$stmt->fetchColumn();
}
