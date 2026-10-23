<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/services/game/GameService.php';

final class GamePersistenceAtomicityTest extends TestCase
{
    public function testSnapshotFailureRollsBackVersionAndInMemoryState(): void
    {
        $pdo = $this->databaseWithoutSnapshotTable();
        $game = $this->gameWithPlayers($pdo);
        $stateBefore = $game->getSnapshot();

        try {
            $game->startHand(12345);
            $this->fail('Expected persistence failure');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Failed to persist snapshot', $e->getMessage());
        }

        $this->assertSame(0, $this->databaseVersion($pdo));
        $this->assertSame(0, $game->getVersion());
        $this->assertSame($stateBefore, $game->getSnapshot());
        $this->assertFalse($game->isHandBootstrapped());
        $this->assertFalse($pdo->inTransaction());
    }

    public function testSnapshotFailureRollsBackToSavepointWithoutClosingCallerTransaction(): void
    {
        $pdo = $this->databaseWithoutSnapshotTable();
        $game = $this->gameWithPlayers($pdo);
        $stateBefore = $game->getSnapshot();
        $pdo->beginTransaction();

        try {
            $game->startHand(12345);
            $this->fail('Expected persistence failure');
        } catch (RuntimeException) {
        }

        $this->assertTrue($pdo->inTransaction());
        $this->assertSame(0, $this->databaseVersion($pdo));
        $this->assertSame(0, $game->getVersion());
        $this->assertSame($stateBefore, $game->getSnapshot());
        $pdo->rollBack();
    }

    private function databaseWithoutSnapshotTable(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE games (id INTEGER PRIMARY KEY, version INTEGER NOT NULL)');
        $pdo->exec('INSERT INTO games (id, version) VALUES (1, 0)');
        return $pdo;
    }

    private function gameWithPlayers(PDO $pdo): GameService
    {
        $game = new GameService(new GamePersistence($pdo), 10, 20);
        $game->setGameId(1);
        $game->loadPlayers([
            ['seat' => 1, 'stack' => 1000],
            ['seat' => 2, 'stack' => 1000],
        ]);
        return $game;
    }

    private function databaseVersion(PDO $pdo): int
    {
        return (int)$pdo->query('SELECT version FROM games WHERE id = 1')->fetchColumn();
    }
}
