<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../db/BaseDBIntegrationTest.php';

use PHPUnit\Framework\TestCase;

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
class GameFoldTerminationTest extends BaseDBIntegrationTest
{
    public function testFoldEndsHandAndStartsNext(): void
    {
        $this->pdo->beginTransaction();
        
        try {
            // Create two users
            $user1 = $this->createTestUser('player1', 'p1@example.com', 'password123');
            $user2 = $this->createTestUser('player2', 'p2@example.com', 'password123');
            
            // Create a table
            require_once __DIR__ . '/../../../app/db/tables.php';
            $tableId = db_create_table($this->pdo, 'Test Table', 2, 10, 20);
            $this->assertNotFalse($tableId);
            
            // Seat both users
            require_once __DIR__ . '/../../../app/db/table_seats.php';
            $this->assertTrue(db_seat_player($this->pdo, $tableId, 1, $user1['id']));
            $this->assertTrue(db_seat_player($this->pdo, $tableId, 2, $user2['id']));
            
            // Create a game
            require_once __DIR__ . '/../../../app/db/games.php';
            $gameId = db_create_game($this->pdo, $tableId, 1, 1, 2, 12345);
            $this->assertNotFalse($gameId);
            
            // Create GameService and start hand
            require_once __DIR__ . '/../../../app/services/game/GameService.php';
            $gameService = new GameService(10, 20, $this->pdo, $gameId, $tableId);
            
            $result = $gameService->startHand([
                ['seat' => 1, 'stack' => 1000],
                ['seat' => 2, 'stack' => 1000],
            ]);
            
            $this->assertTrue($result['ok']);
            
            // Get initial state
            $initialState = $gameService->getState();
            $this->assertGreaterThan(0, $initialState['pot']); // Blinds posted
            
            // Player 1 folds
            $foldResult = $gameService->playerAction(1, \ActionType::FOLD);
            $this->assertTrue($foldResult['ok']);
            $this->assertTrue($foldResult['handEnded'] ?? false);
            
            // Verify hand ended and pot was awarded
            $finalState = $gameService->getState();
            $this->assertEquals(0, $finalState['pot']); // Pot should be 0 (awarded)
            
            // Verify a new hand started (phase should be PREFLOP again)
            $this->assertEquals('preflop', $finalState['phase']);
            
        } finally {
            $this->pdo->rollBack();
        }
    }
}

