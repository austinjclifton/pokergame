<?php
declare(strict_types=1);

require_once __DIR__ . '/../../db/game_snapshots.php';
require_once __DIR__ . '/../../db/game_actions.php'; // only for db_increment_game_version()
require_once __DIR__ . '/GameState.php';

final class GamePersistence
{
    private PDO $pdo;
    private int $snapshotInterval;

    public function __construct(PDO $pdo, int $snapshotInterval = 5)
    {
        $this->pdo = $pdo;
        $this->snapshotInterval = $snapshotInterval;
    }

    /**
     * NO ACTION LOGGING ANYMORE.
     * We simply bump the game version (optional) and rely on snapshots only.
     */
    public function bumpVersion(int $gameId, ?int $expectedVersion = null): int
    {
        return db_increment_game_version($this->pdo, $gameId, $expectedVersion);
    }

    /**
     * Write a snapshot if needed (phase change or every N actions).
     */
    public function maybeSnapshot(
        int $gameId,
        int $version,
        array $state,
        string $previousPhase,
        string $currentPhase
    ): void {
        if ($previousPhase !== $currentPhase) {
            db_insert_snapshot($this->pdo, $gameId, $version, $state);
            return;
        }

        if ($version % $this->snapshotInterval === 0) {
            db_insert_snapshot($this->pdo, $gameId, $version, $state);
        }
    }

    /**
     * Write a forced snapshot (e.g., at HAND_START or HAND_END)
     */
    public function snapshotForced(int $gameId, int $version, array $state): void
    {
        if (!db_insert_snapshot($this->pdo, $gameId, $version, $state)) {
            throw new RuntimeException("Failed to persist snapshot for game {$gameId} at version {$version}");
        }
    }

    /**
     * Load the most recent snapshot
     */
    public function loadLatest(int $gameId): ?array
    {
        return db_get_latest_snapshot($this->pdo, $gameId);
    }

    /**
     * Convert GameState object to array
     */
    public function snapshot(GameState $state): array
    {
        return $state->toArray();
    }
}
