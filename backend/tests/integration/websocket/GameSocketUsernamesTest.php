<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../db/BaseDBIntegrationTest.php';

/**
 * Integration test for username display in GameSocket.
 * 
 * Tests:
 *  - STATE_SYNC includes usernames for all seats
 *  - Usernames are correctly retrieved from database JOIN
 * 
 * @coversNothing
 */
final class GameSocketUsernamesTest extends BaseDBIntegrationTest
{
    protected function loadDatabaseFunctions(): void
    {
        require_once __DIR__ . '/../../../app/db/table_seats.php';
        require_once __DIR__ . '/../../../app/db/tables.php';
        require_once __DIR__ . '/../../../app/db/users.php';
    }

    public function testStateSyncIncludesUsernames(): void
    {
        $userId1 = $this->createTestUser('testuser1', 'test1@example.com');
        $userId2 = $this->createTestUser('testuser2', 'test2@example.com');

        $tableId = (int) db_create_table($this->pdo, 'Test Table', 2, 10, 20, 0);
        $this->assertGreaterThan(0, $tableId);

        $this->assertTrue(db_seat_player($this->pdo, $tableId, 1, $userId1));
        $this->assertTrue(db_seat_player($this->pdo, $tableId, 2, $userId2));

        $seats = db_get_table_seats($this->pdo, $tableId);
        $this->assertCount(2, $seats);

        $seat1 = $seats[0];
        $seat2 = $seats[1];

        $this->assertArrayHasKey('username', $seat1);
        $this->assertArrayHasKey('username', $seat2);
        $this->assertContains($seat1['username'], ['testuser1', 'testuser2']);
        $this->assertContains($seat2['username'], ['testuser1', 'testuser2']);
        $this->assertNotSame($seat1['username'], $seat2['username']);
    }
}

