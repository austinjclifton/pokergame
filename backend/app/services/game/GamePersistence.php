<?php
declare(strict_types=1);

require_once __DIR__ . '/../../db/game_snapshots.php';
require_once __DIR__ . '/../../db/game_actions.php';

final class GamePersistence
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Atomically advance the authoritative version and persist its state.
     * A savepoint keeps this operation atomic when the caller owns a wider
     * transaction, such as terminal match cleanup.
     */
    public function persistTransition(
        int $gameId,
        int $expectedVersion,
        array $state
    ): int
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT game_transition');
        }

        try {
            $version = db_increment_game_version($this->pdo, $gameId, $expectedVersion);
            try {
                $saved = db_insert_snapshot($this->pdo, $gameId, $version, $state);
            } catch (\Throwable $e) {
                throw new RuntimeException(
                    "Failed to persist snapshot for game {$gameId} at version {$version}",
                    0,
                    $e,
                );
            }
            if (!$saved) {
                throw new RuntimeException("Failed to persist snapshot for game {$gameId} at version {$version}");
            }

            if ($ownsTransaction) {
                if (!$this->pdo->commit()) {
                    throw new RuntimeException('Failed to commit game transition');
                }
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT game_transition');
            }

            return $version;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            } elseif (!$ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT game_transition');
                $this->pdo->exec('RELEASE SAVEPOINT game_transition');
            }

            throw $e;
        }
    }

    /**
     * Load the most recent snapshot
     */
    public function loadLatest(int $gameId): ?array
    {
        return db_get_latest_snapshot($this->pdo, $gameId);
    }
}
