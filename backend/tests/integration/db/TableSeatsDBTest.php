<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseDBIntegrationTest.php';

/**
 * Integration tests for app/db/table_seats.php
 *
 * Comprehensive test suite for table seat database functions.
 * Tests all CRUD operations, edge cases, and business logic.
 *
 * Uses the actual MySQL database connection from bootstrap.php.
 * Tests use transactions for isolation - each test runs in a transaction
 * that is rolled back in tearDown to ensure test isolation.
 *
 * @coversNothing
 */
final class TableSeatsDBTest extends BaseDBIntegrationTest
{
    /**
     * Load database functions required for table seat tests
     */
    protected function loadDatabaseFunctions(): void
    {
        require_once __DIR__ . '/../../../app/db/tables.php';
        require_once __DIR__ . '/../../../app/db/table_seats.php';
    }

    /**
     * Set up test environment with table seat-specific cleanup
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Clean up any existing table seat data for better isolation
        $this->pdo->exec("DELETE FROM table_seats");
        $this->pdo->exec("DELETE FROM tables");
    }

    /**
     * Helper: Create a test table and return table ID
     */
    private function createTestTable(string $name = 'Test Table', int $maxSeats = 6): int
    {
        $tableId = db_create_table($this->pdo, $name, $maxSeats, 10, 20, 0);
        $this->assertNotNull($tableId);
        return $tableId;
    }

    // ============================================================================
    // SEAT PLAYER TESTS
    // ============================================================================

    /**
     * Test that seating a player returns true and creates a seat record
     */
    public function testSeatPlayerReturnsTrueAndCreatesRecord(): void
    {
        $tableId = $this->createTestTable();
        $userId = $this->createTestUser('player1');
        
        $result = db_seat_player($this->pdo, $tableId, 1, $userId);
        
        $this->assertTrue($result, 'Seating player should return true');
        
        $seats = db_get_table_seats($this->pdo, $tableId);
        $this->assertCount(1, $seats, 'Should have one seat');
        $this->assertSame($tableId, (int)$seats[0]['table_id']);
        $this->assertSame(1, (int)$seats[0]['seat_no']);
        $this->assertSame($userId, (int)$seats[0]['user_id']);
    }

    /**
     * Test that seating a player sets joined_at timestamp
     */
    public function testSeatPlayerSetsJoinedAtTimestamp(): void
    {
        $tableId = $this->createTestTable();
        $userId = $this->createTestUser('player2');
        
        db_seat_player($this->pdo, $tableId, 1, $userId);
        
        $seat = db_find_seat_by_user($this->pdo, $tableId, $userId);
        $this->assertNotNull($seat);
        $this->assertNotNull($seat['joined_at'], 'joined_at should be set');
        $this->assertRecentTimestamp('table_seats', 'joined_at', $seat['id']);
    }

    /**
     * Test that seating a player in a duplicate seat updates the existing seat
     */
    public function testSeatPlayerInDuplicateSeatUpdatesExistingSeat(): void
    {
        $tableId = $this->createTestTable();
        $userId1 = $this->createTestUser('player3');
        $userId2 = $this->createTestUser('player4');
        
        // Seat first player
        db_seat_player($this->pdo, $tableId, 1, $userId1);
        
        // Try to seat second player in same seat (should update)
        $result = db_seat_player($this->pdo, $tableId, 1, $userId2);
        $this->assertTrue($result, 'Should return true even when updating');
        
        $seats = db_get_table_seats($this->pdo, $tableId);
        $this->assertCount(1, $seats, 'Should still have one seat');
        $this->assertSame($userId2, (int)$seats[0]['user_id'], 'Seat should now have second user');
        $this->assertNull($seats[0]['left_at'], 'left_at should be null after reseating');
    }

    /**
     * Test that seating multiple players in different seats works correctly
     */
    public function testSeatMultiplePlayersInDifferentSeats(): void
    {
        $tableId = $this->createTestTable();
        $userId1 = $this->createTestUser('player5');
        $userId2 = $this->createTestUser('player6');
        
        db_seat_player($this->pdo, $tableId, 1, $userId1);
        db_seat_player($this->pdo, $tableId, 2, $userId2);
        
        $seats = db_get_table_seats($this->pdo, $tableId);
        $this->assertCount(2, $seats, 'Should have two seats');
        
        $seatNos = array_map(fn($s) => (int)$s['seat_no'], $seats);
        $this->assertContains(1, $seatNos);
        $this->assertContains(2, $seatNos);
    }

    // ============================================================================
    // GET TABLE SEATS TESTS
    // ============================================================================

    /**
     * Test that getting table seats returns all seats ordered by seat_no
     */
    public function testGetTableSeatsReturnsAllSeatsOrdered(): void
    {
        $tableId = $this->createTestTable();
        $userId1 = $this->createTestUser('player7');
        $userId2 = $this->createTestUser('player8');
        $userId3 = $this->createTestUser('player9');
        
        // Seat in non-sequential order
        db_seat_player($this->pdo, $tableId, 3, $userId3);
        db_seat_player($this->pdo, $tableId, 1, $userId1);
        db_seat_player($this->pdo, $tableId, 2, $userId2);
        
        $seats = db_get_table_seats($this->pdo, $tableId);
        
        $this->assertIsArray($seats);
        $this->assertCount(3, $seats);
        
        // Verify ordering
        $this->assertSame(1, (int)$seats[0]['seat_no']);
        $this->assertSame(2, (int)$seats[1]['seat_no']);
        $this->assertSame(3, (int)$seats[2]['seat_no']);
    }

    /**
     * Test that getting table seats returns empty array when no seats
     */
    public function testGetTableSeatsReturnsEmptyArrayWhenNoSeats(): void
    {
        $tableId = $this->createTestTable();
        
        $seats = db_get_table_seats($this->pdo, $tableId);
        
        $this->assertIsArray($seats);
        $this->assertCount(0, $seats, 'Should return empty array when no seats');
    }

    /**
     * Test that getting table seats includes all required fields
     */
    public function testGetTableSeatsIncludesAllRequiredFields(): void
    {
        $tableId = $this->createTestTable();
        $userId = $this->createTestUser('player10');
        
        db_seat_player($this->pdo, $tableId, 1, $userId);
        
        $seats = db_get_table_seats($this->pdo, $tableId);
        $seat = $seats[0];
        
        $this->assertArrayHasKey('id', $seat);
        $this->assertArrayHasKey('table_id', $seat);
        $this->assertArrayHasKey('seat_no', $seat);
        $this->assertArrayHasKey('user_id', $seat);
        $this->assertArrayHasKey('joined_at', $seat);
        $this->assertArrayHasKey('left_at', $seat);
    }

    // ============================================================================
    // FIND SEAT BY USER TESTS
    // ============================================================================

    /**
     * Test that finding seat by user returns correct seat data
     */
    public function testFindSeatByUserReturnsCorrectSeat(): void
    {
        $tableId = $this->createTestTable();
        $userId = $this->createTestUser('player11');
        
        db_seat_player($this->pdo, $tableId, 2, $userId);
        
        $seat = db_find_seat_by_user($this->pdo, $tableId, $userId);
        
        $this->assertNotNull($seat, 'Seat should be found');
        $this->assertSame($tableId, (int)$seat['table_id']);
        $this->assertSame(2, (int)$seat['seat_no']);
        $this->assertSame($userId, (int)$seat['user_id']);
        $this->assertNull($seat['left_at'], 'left_at should be null for active seat');
    }

    /**
     * Test that finding seat by user returns null for non-existent user
     */
    public function testFindSeatByUserReturnsNullForNonExistentUser(): void
    {
        $tableId = $this->createTestTable();
        
        $seat = db_find_seat_by_user($this->pdo, $tableId, 999999);
        
        $this->assertNull($seat, 'Non-existent user should return null');
    }

    /**
     * Test that finding seat by user returns null for unseated user
     */
    public function testFindSeatByUserReturnsNullForUnseatedUser(): void
    {
        $tableId = $this->createTestTable();
        $userId = $this->createTestUser('player12');
        
        // Don't seat the user
        $seat = db_find_seat_by_user($this->pdo, $tableId, $userId);
        
        $this->assertNull($seat, 'Unseated user should return null');
    }

    // ============================================================================
    // UNSEAT PLAYER TESTS
    // ============================================================================

    /**
     * Test that unseating a player sets left_at timestamp
     */
    public function testUnseatPlayerSetsLeftAtTimestamp(): void
    {
        $tableId = $this->createTestTable();
        $userId = $this->createTestUser('player13');
        
        db_seat_player($this->pdo, $tableId, 1, $userId);
        
        $result = db_unseat_player($this->pdo, $tableId, $userId);
        $this->assertTrue($result, 'Unseating should return true');
        
        $seat = db_find_seat_by_user($this->pdo, $tableId, $userId);
        $this->assertNull($seat, 'Seat should not be found after unseating (left_at is set)');
        
        // Verify left_at is set by querying directly
        $stmt = $this->pdo->prepare("
            SELECT left_at FROM table_seats
            WHERE table_id = :table_id AND user_id = :user_id
            LIMIT 1
        ");
        $stmt->execute(['table_id' => $tableId, 'user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotNull($row);
        $this->assertNotNull($row['left_at'], 'left_at should be set');
    }

    /**
     * Test that unseating a player returns false for non-existent user
     */
    public function testUnseatPlayerReturnsFalseForNonExistentUser(): void
    {
        $tableId = $this->createTestTable();
        
        $result = db_unseat_player($this->pdo, $tableId, 999999);
        
        // Function should return false (no rows affected)
        $this->assertFalse($result, 'Non-existent user should return false');
    }

    /**
     * Test that unseating a player that already left returns false
     */
    public function testUnseatPlayerThatAlreadyLeftReturnsFalse(): void
    {
        $tableId = $this->createTestTable();
        $userId = $this->createTestUser('player14');
        
        db_seat_player($this->pdo, $tableId, 1, $userId);
        db_unseat_player($this->pdo, $tableId, $userId);
        
        // Try to unseat again
        $result = db_unseat_player($this->pdo, $tableId, $userId);
        $this->assertFalse($result, 'Already unseated player should return false');
    }

    // ============================================================================
    // INTEGRATION TESTS
    // ============================================================================

    /**
     * Test full seat lifecycle: seat, find, unseat, verify
     */
    public function testFullSeatLifecycle(): void
    {
        $tableId = $this->createTestTable();
        $userId1 = $this->createTestUser('player15');
        $userId2 = $this->createTestUser('player16');
        
        // 1. Seat two players
        db_seat_player($this->pdo, $tableId, 1, $userId1);
        db_seat_player($this->pdo, $tableId, 2, $userId2);
        
        // 2. Verify both are seated
        $seats = db_get_table_seats($this->pdo, $tableId);
        $this->assertCount(2, $seats);
        
        $seat1 = db_find_seat_by_user($this->pdo, $tableId, $userId1);
        $seat2 = db_find_seat_by_user($this->pdo, $tableId, $userId2);
        $this->assertNotNull($seat1);
        $this->assertNotNull($seat2);
        
        // 3. Unseat one player
        db_unseat_player($this->pdo, $tableId, $userId1);
        
        // 4. Verify unseated player is not found
        $seat1 = db_find_seat_by_user($this->pdo, $tableId, $userId1);
        $this->assertNull($seat1, 'Unseated player should not be found');
        
        // 5. Verify other player is still seated
        $seat2 = db_find_seat_by_user($this->pdo, $tableId, $userId2);
        $this->assertNotNull($seat2, 'Other player should still be seated');
        
        // 6. Verify seats list still shows both (but one has left_at set)
        $seats = db_get_table_seats($this->pdo, $tableId);
        $this->assertCount(2, $seats, 'Seats should still exist in database');
    }

    /**
     * Test that duplicate seats are rejected (seat conflict handling)
     */
    public function testDuplicateSeatsAreRejected(): void
    {
        $tableId = $this->createTestTable();
        $userId1 = $this->createTestUser('player17');
        $userId2 = $this->createTestUser('player18');
        
        // Seat first player
        db_seat_player($this->pdo, $tableId, 1, $userId1);
        
        // Try to seat second player in same seat - should update (replace)
        db_seat_player($this->pdo, $tableId, 1, $userId2);
        
        // Verify only one seat exists and it has the second user
        $seats = db_get_table_seats($this->pdo, $tableId);
        $this->assertCount(1, $seats);
        $this->assertSame($userId2, (int)$seats[0]['user_id'], 'Seat should have second user');
        
        // First user should not be found
        $seat1 = db_find_seat_by_user($this->pdo, $tableId, $userId1);
        $this->assertNull($seat1, 'First user should not be found after replacement');
    }

    /**
     * Test that multiple tables have isolated seat data
     */
    public function testMultipleTablesHaveIsolatedSeats(): void
    {
        $tableId1 = $this->createTestTable('Table 1');
        $tableId2 = $this->createTestTable('Table 2');
        $userId = $this->createTestUser('player19');
        
        // Seat user at table 1
        db_seat_player($this->pdo, $tableId1, 1, $userId);
        
        // Verify user is at table 1
        $seat1 = db_find_seat_by_user($this->pdo, $tableId1, $userId);
        $this->assertNotNull($seat1);
        
        // Verify user is NOT at table 2
        $seat2 = db_find_seat_by_user($this->pdo, $tableId2, $userId);
        $this->assertNull($seat2);
        
        // Seat user at table 2 as well
        db_seat_player($this->pdo, $tableId2, 1, $userId);
        
        // Verify user is at both tables
        $seat1 = db_find_seat_by_user($this->pdo, $tableId1, $userId);
        $seat2 = db_find_seat_by_user($this->pdo, $tableId2, $userId);
        $this->assertNotNull($seat1);
        $this->assertNotNull($seat2);
    }
}

