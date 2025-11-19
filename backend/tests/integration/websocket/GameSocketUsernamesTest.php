<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../db/BaseDBIntegrationTest.php';
require_once __DIR__ . '/helpers/HttpHarness.php';

use PHPUnit\Framework\TestCase;

/**
 * Integration test for username display in GameSocket.
 * 
 * Tests:
 *  - STATE_SYNC includes usernames for all seats
 *  - Usernames are correctly retrieved from database JOIN
 * 
 * @coversNothing
 */
class GameSocketUsernamesTest extends BaseDBIntegrationTest
{
    public function testStateSyncIncludesUsernames(): void
    {
        $this->pdo->beginTransaction();
        
        try {
            // Create two users
            $user1 = $this->createTestUser('testuser1', 'test1@example.com', 'password123');
            $user2 = $this->createTestUser('testuser2', 'test2@example.com', 'password123');
            
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
            
            // Get seats with usernames
            $seats = db_get_table_seats($this->pdo, $tableId);
            $this->assertCount(2, $seats);
            
            // Verify usernames are included
            $seat1 = $seats[0];
            $seat2 = $seats[1];
            
            $this->assertArrayHasKey('username', $seat1);
            $this->assertArrayHasKey('username', $seat2);
            $this->assertContains($seat1['username'], ['testuser1', 'testuser2']);
            $this->assertContains($seat2['username'], ['testuser1', 'testuser2']);
            $this->assertNotEquals($seat1['username'], $seat2['username']);
            
        } finally {
            $this->pdo->rollBack();
        }
    }
}

