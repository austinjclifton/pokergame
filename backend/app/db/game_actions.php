<?php
// backend/app/db/game_actions.php
// -----------------------------------------------------------------------------
// Minimal DB utility: only game version incrementing is needed.
// All old action/table logic has been removed from the architecture.
// -----------------------------------------------------------------------------

declare(strict_types=1);

final class GameVersionConflictException extends RuntimeException
{
}

/**
 * Increment the game version and return the new version.
 *
 * @param PDO $pdo
 * @param int $gameId
 * @return int
 */
function db_increment_game_version(PDO $pdo, int $gameId, ?int $expectedVersion = null): int
{
    if ($expectedVersion === null) {
        $stmt = $pdo->prepare("
            UPDATE games
            SET version = version + 1
            WHERE id = :game_id
        ");
        $stmt->execute(['game_id' => $gameId]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE games
            SET version = version + 1
            WHERE id = :game_id AND version = :expected_version
        ");
        $stmt->execute([
            'game_id' => $gameId,
            'expected_version' => $expectedVersion,
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new GameVersionConflictException(
                "Game {$gameId} is no longer at version {$expectedVersion}"
            );
        }
    }

    $stmt = $pdo->prepare("
        SELECT version
        FROM games
        WHERE id = :game_id
    ");
    $stmt->execute(['game_id' => $gameId]);

    $version = $stmt->fetchColumn();
    if ($version === false) {
        throw new RuntimeException("Game {$gameId} was not found while advancing its version");
    }

    return (int)$version;
}
