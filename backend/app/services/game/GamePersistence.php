<?php
declare(strict_types=1);

require_once __DIR__ . '/../../db/game_snapshots.php';
require_once __DIR__ . '/../../db/game_actions.php';
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
     * Insert an action and bump version.
     *
     * @param int         $gameId
     * @param int         $version
     * @param int|null    $actorSeat
     * @param string      $actionType
     * @param int         $amount
     * @param array       $data
     * @return bool
     */
    public function recordAction(
        int $gameId,
        int $version,
        ?int $actorSeat,
        string $actionType,
        int $amount,
        array $data
    ): bool {
        return db_insert_action(
            $this->pdo,
            $gameId,
            $version,
            $actorSeat,
            $actionType,
            $amount,
            $data
        );
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
     * Write a manual snapshot (e.g., after HAND_START or HAND_END).
     */
    public function snapshotForced(int $gameId, int $version, array $state): void
    {
        db_insert_snapshot($this->pdo, $gameId, $version, $state);
    }

    /**
     * Alias for snapshotForced (for compatibility with GameSocket)
     */
    public function saveSnapshot(int $gameId, array $state, int $version): void
    {
        $this->snapshotForced($gameId, $version, $state);
    }

    /**
     * Load last snapshot
     */
    public function loadLatest(int $gameId): ?array
    {
        return db_get_latest_snapshot($this->pdo, $gameId);
    }

    /**
     * Replay rebuild state
     */
    public function rebuild(int $gameId, GameService $engine): array
    {
        return db_rebuild_state($this->pdo, $gameId, $engine);
    }

    /**
     * Convert GameState to array (for getState() calls)
     * This doesn't persist - just converts state to array format
     */
    public function snapshot(GameState $state): array
    {
        return $state->toArray();
    }
}
