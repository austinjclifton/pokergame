<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../db/BaseDBIntegrationTest.php';

/**
 * Integration test for hand termination when player folds.
 * 
 * Tests:
 *  - When one player folds and only one remains, hand ends immediately
 *  - Pot is awarded to the winner
 *  - Next hand starts automatically
 * 
 * @coversNothing
 */
final class GameFoldTerminationTest extends BaseDBIntegrationTest
{
    protected function loadDatabaseFunctions(): void
    {
        require_once __DIR__ . '/../../../app/db/games.php';
        require_once __DIR__ . '/../../../app/db/table_seats.php';
        require_once __DIR__ . '/../../../app/db/tables.php';
        require_once __DIR__ . '/../../../app/db/users.php';
    }

    public function testFoldEndsHandAndNextHandCanStart(): void
    {
        require_once __DIR__ . '/../../../app/services/game/GamePersistence.php';
        require_once __DIR__ . '/../../../app/services/game/GameService.php';
        require_once __DIR__ . '/../../../app/services/game/cards/DealerService.php';
        require_once __DIR__ . '/../../../app/services/game/cards/HandEvaluator.php';

        $userId1 = $this->createTestUser('player1', 'p1@example.com');
        $userId2 = $this->createTestUser('player2', 'p2@example.com');

        $tableId = (int) db_create_table($this->pdo, 'Test Table', 2, 10, 20, 0);
        $this->assertGreaterThan(0, $tableId);

        $this->assertTrue(db_seat_player($this->pdo, $tableId, 1, $userId1));
        $this->assertTrue(db_seat_player($this->pdo, $tableId, 2, $userId2));

        $gameId = (int) db_create_game($this->pdo, $tableId, 1, 1, 2, 12345);
        $this->assertGreaterThan(0, $gameId);

        $persistence = new GamePersistence($this->pdo, 5);
        $gameService = new GameService($persistence, 10, 20);
        $gameService->setGameId($gameId);
        $gameService->loadPlayers([
            ['seat' => 1, 'stack' => 1000],
            ['seat' => 2, 'stack' => 1000],
        ]);

        $startResult = $gameService->startHand(12345);
        $this->assertTrue($startResult['ok']);
        $this->assertGreaterThan(0, $startResult['state']['pot']);

        $actingSeat = (int) $startResult['state']['actionSeat'];
        $winningSeat = $actingSeat === 1 ? 2 : 1;

        $foldResult = $gameService->applyAction($actingSeat, 'fold');
        $this->assertTrue($foldResult['ok']);
        $this->assertTrue($foldResult['handEnded'] ?? false);
        $this->assertFalse($foldResult['matchEnded'] ?? false);

        $stateAfterFold = $foldResult['state'];
        $this->assertSame(30, $stateAfterFold['pot']);
        $this->assertSame(0, $stateAfterFold['currentBet']);
        $this->assertSame(30, $foldResult['summary']['pot'] ?? null);
        $this->assertLessThan(1000, $stateAfterFold['players'][$actingSeat]['stack']);
        $this->assertGreaterThan(1000, $stateAfterFold['players'][$winningSeat]['stack']);

        $nextHandResult = $gameService->startNextHand(12346);
        $this->assertTrue($nextHandResult['ok']);
        $this->assertFalse($nextHandResult['handEnded'] ?? false);
        $this->assertSame('preflop', $nextHandResult['state']['phase']);
        $this->assertGreaterThan(0, $nextHandResult['state']['pot']);
    }
}

