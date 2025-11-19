<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseDBIntegrationTest.php';

/**
 * Integration tests for app/db/games.php
 *
 * Comprehensive test suite for game database functions.
 * Tests all CRUD operations, edge cases, and business logic.
 *
 * Uses the actual MySQL database connection from bootstrap.php.
 * Tests use transactions for isolation - each test runs in a transaction
 * that is rolled back in tearDown to ensure test isolation.
 *
 * @coversNothing
 */
final class GamesDBTest extends BaseDBIntegrationTest
{
    /**
     * Load database functions required for game tests
     */
    protected function loadDatabaseFunctions(): void
    {
        require_once __DIR__ . '/../../../app/db/tables.php';
        require_once __DIR__ . '/../../../app/db/games.php';
    }

    /**
     * Set up test environment with game-specific cleanup
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Clean up any existing game data for better isolation
        $this->pdo->exec("DELETE FROM games");
        $this->pdo->exec("DELETE FROM tables");
    }

    /**
     * Helper: Create a test table and return table ID
     */
    private function createTestTable(string $name = 'Test Table'): int
    {
        $tableId = db_create_table($this->pdo, $name, 6, 10, 20, 0);
        $this->assertNotNull($tableId);
        return $tableId;
    }

    // ============================================================================
    // CREATE GAME TESTS
    // ============================================================================

    /**
     * Test that creating a game returns a valid game ID
     */
    public function testCreateGameReturnsValidId(): void
    {
        $tableId = $this->createTestTable();
        
        $gameId = db_create_game($this->pdo, $tableId, 1, 2, 3, 12345);
        
        $this->assertNotNull($gameId, 'Game ID should not be null');
        $this->assertIsInt($gameId, 'Game ID should be an integer');
        $this->assertGreaterThan(0, $gameId, 'Game ID should be positive');
    }

    /**
     * Test that creating a game stores all parameters correctly
     */
    public function testCreateGameStoresAllParameters(): void
    {
        $tableId = $this->createTestTable();
        
        $gameId = db_create_game($this->pdo, $tableId, 1, 2, 3, 54321);
        
        $game = db_get_active_game($this->pdo, $tableId);
        
        $this->assertNotNull($game, 'Game should exist after creation');
        $this->assertSame($gameId, (int)$game['id']);
        $this->assertSame($tableId, (int)$game['table_id']);
        $this->assertSame(1, (int)$game['dealer_seat']);
        $this->assertSame(2, (int)$game['sb_seat']);
        $this->assertSame(3, (int)$game['bb_seat']);
        $this->assertSame(54321, (int)$game['deck_seed']);
        $this->assertSame('ACTIVE', $game['status']);
        $this->assertSame(0, (int)$game['version']);
    }

    /**
     * Test that creating a game sets default status to ACTIVE
     */
    public function testCreateGameSetsDefaultStatusToActive(): void
    {
        $tableId = $this->createTestTable();
        
        $gameId = db_create_game($this->pdo, $tableId, 1, 2, 3, 12345);
        
        $game = db_get_active_game($this->pdo, $tableId);
        
        $this->assertNotNull($game);
        $this->assertSame('ACTIVE', $game['status']);
    }

    /**
     * Test that creating a game sets default version to 0
     */
    public function testCreateGameSetsDefaultVersionToZero(): void
    {
        $tableId = $this->createTestTable();
        
        $gameId = db_create_game($this->pdo, $tableId, 1, 2, 3, 12345);
        
        $game = db_get_active_game($this->pdo, $tableId);
        
        $this->assertNotNull($game);
        $this->assertSame(0, (int)$game['version']);
    }

    /**
     * Test that creating a game sets started_at timestamp
     */
    public function testCreateGameSetsStartedAtTimestamp(): void
    {
        $tableId = $this->createTestTable();
        
        $gameId = db_create_game($this->pdo, $tableId, 1, 2, 3, 12345);
        
        $game = db_get_active_game($this->pdo, $tableId);
        
        $this->assertNotNull($game);
        $this->assertNotNull($game['started_at'], 'started_at should be set');
        $this->assertRecentTimestamp('games', 'started_at', $gameId);
    }

    // ============================================================================
    // GET ACTIVE GAME TESTS
    // ============================================================================

    /**
     * Test that getting active game returns correct game data
     */
    public function testGetActiveGameReturnsCorrectData(): void
    {
        $tableId = $this->createTestTable();
        
        $gameId = db_create_game($this->pdo, $tableId, 1, 2, 3, 12345);
        
        $game = db_get_active_game($this->pdo, $tableId);
        
        $this->assertNotNull($game, 'Active game should be found');
        $this->assertSame($gameId, (int)$game['id']);
        $this->assertArrayHasKey('table_id', $game);
        $this->assertArrayHasKey('dealer_seat', $game);
        $this->assertArrayHasKey('sb_seat', $game);
        $this->assertArrayHasKey('bb_seat', $game);
        $this->assertArrayHasKey('deck_seed', $game);
        $this->assertArrayHasKey('version', $game);
        $this->assertArrayHasKey('started_at', $game);
        $this->assertArrayHasKey('ended_at', $game);
        $this->assertArrayHasKey('status', $game);
    }

    /**
     * Test that getting active game returns null when no active game
     */
    public function testGetActiveGameReturnsNullWhenNoActiveGame(): void
    {
        $tableId = $this->createTestTable();
        
        $game = db_get_active_game($this->pdo, $tableId);
        
        $this->assertNull($game, 'Should return null when no active game');
    }

    /**
     * Test that getting active game returns only ACTIVE games
     */
    public function testGetActiveGameReturnsOnlyActiveGames(): void
    {
        $tableId = $this->createTestTable();
        
        $gameId = db_create_game($this->pdo, $tableId, 1, 2, 3, 12345);
        
        // End the game
        db_end_game($this->pdo, $gameId);
        
        $game = db_get_active_game($this->pdo, $tableId);
        
        $this->assertNull($game, 'Should return null for completed game');
    }

    /**
     * Test that getting active game returns most recent game when multiple exist
     */
    public function testGetActiveGameReturnsMostRecentGame(): void
    {
        $tableId = $this->createTestTable();
        
        // Create first game and end it
        $gameId1 = db_create_game($this->pdo, $tableId, 1, 2, 3, 11111);
        db_end_game($this->pdo, $gameId1);
        
        // Create second active game
        usleep(100000); // 0.1 second delay
        $gameId2 = db_create_game($this->pdo, $tableId, 2, 3, 4, 22222);
        
        $game = db_get_active_game($this->pdo, $tableId);
        
        $this->assertNotNull($game);
        $this->assertSame($gameId2, (int)$game['id'], 'Should return most recent active game');
    }

    // ============================================================================
    // UPDATE GAME VERSION TESTS
    // ============================================================================

    /**
     * Test that updating game version changes the version correctly
     */
    public function testUpdateGameVersionChangesVersion(): void
    {
        $tableId = $this->createTestTable();
        $gameId = db_create_game($this->pdo, $tableId, 1, 2, 3, 12345);
        
        // Update version
        $result = db_update_game_version($this->pdo, $gameId, 5);
        $this->assertTrue($result, 'Update should return true');
        
        $game = db_get_active_game($this->pdo, $tableId);
        $this->assertNotNull($game);
        $this->assertSame(5, (int)$game['version']);
    }

    /**
     * Test that updating game version multiple times works correctly
     */
    public function testUpdateGameVersionMultipleTimes(): void
    {
        $tableId = $this->createTestTable();
        $gameId = db_create_game($this->pdo, $tableId, 1, 2, 3, 12345);
        
        // Update version several times
        db_update_game_version($this->pdo, $gameId, 1);
        db_update_game_version($this->pdo, $gameId, 2);
        db_update_game_version($this->pdo, $gameId, 3);
        db_update_game_version($this->pdo, $gameId, 10);
        
        $game = db_get_active_game($this->pdo, $tableId);
        $this->assertNotNull($game);
        $this->assertSame(10, (int)$game['version'], 'Version should be the last updated value');
    }

    /**
     * Test that updating game version for non-existent game returns false
     */
    public function testUpdateGameVersionForNonExistentGameReturnsFalse(): void
    {
        $result = db_update_game_version($this->pdo, 999999, 5);
        
        $this->assertFalse($result, 'Non-existent game should return false');
    }

    // ============================================================================
    // END GAME TESTS
    // ============================================================================

    /**
     * Test that ending a game sets status to COMPLETE and ended_at timestamp
     */
    public function testEndGameSetsStatusToCompleteAndEndedAt(): void
    {
        $tableId = $this->createTestTable();
        $gameId = db_create_game($this->pdo, $tableId, 1, 2, 3, 12345);
        
        $result = db_end_game($this->pdo, $gameId);
        $this->assertTrue($result, 'Ending game should return true');
        
        // Get game directly (not via get_active_game since it's now complete)
        $stmt = $this->pdo->prepare("
            SELECT status, ended_at FROM games WHERE id = :game_id LIMIT 1
        ");
        $stmt->execute(['game_id' => $gameId]);
        $game = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertNotNull($game);
        $this->assertSame('COMPLETE', $game['status']);
        $this->assertNotNull($game['ended_at'], 'ended_at should be set');
        $this->assertRecentTimestamp('games', 'ended_at', $gameId);
    }

    /**
     * Test that ending a game makes it no longer appear in active games
     */
    public function testEndGameRemovesFromActiveGames(): void
    {
        $tableId = $this->createTestTable();
        $gameId = db_create_game($this->pdo, $tableId, 1, 2, 3, 12345);
        
        // Verify it's active
        $game = db_get_active_game($this->pdo, $tableId);
        $this->assertNotNull($game);
        
        // End the game
        db_end_game($this->pdo, $gameId);
        
        // Verify it's no longer active
        $game = db_get_active_game($this->pdo, $tableId);
        $this->assertNull($game, 'Ended game should not appear in active games');
    }

    /**
     * Test that ending a game for non-existent game returns false
     */
    public function testEndGameForNonExistentGameReturnsFalse(): void
    {
        $result = db_end_game($this->pdo, 999999);
        
        $this->assertFalse($result, 'Non-existent game should return false');
    }

    // ============================================================================
    // INTEGRATION TESTS
    // ============================================================================

    /**
     * Test full game lifecycle: create, update version, end
     */
    public function testFullGameLifecycle(): void
    {
        $tableId = $this->createTestTable();
        
        // 1. Create game
        $gameId = db_create_game($this->pdo, $tableId, 1, 2, 3, 12345);
        $this->assertNotNull($gameId);
        
        // 2. Verify initial state
        $game = db_get_active_game($this->pdo, $tableId);
        $this->assertNotNull($game);
        $this->assertSame('ACTIVE', $game['status']);
        $this->assertSame(0, (int)$game['version']);
        
        // 3. Update version several times
        db_update_game_version($this->pdo, $gameId, 1);
        db_update_game_version($this->pdo, $gameId, 2);
        db_update_game_version($this->pdo, $gameId, 3);
        
        $game = db_get_active_game($this->pdo, $tableId);
        $this->assertSame(3, (int)$game['version']);
        
        // 4. End the game
        db_end_game($this->pdo, $gameId);
        
        // 5. Verify game is complete
        $game = db_get_active_game($this->pdo, $tableId);
        $this->assertNull($game, 'Game should no longer be active');
        
        // Verify status directly
        $stmt = $this->pdo->prepare("SELECT status FROM games WHERE id = :game_id LIMIT 1");
        $stmt->execute(['game_id' => $gameId]);
        $game = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('COMPLETE', $game['status']);
    }

    /**
     * Test that version increments persist correctly
     */
    public function testVersionIncrementsPersistCorrectly(): void
    {
        $tableId = $this->createTestTable();
        $gameId = db_create_game($this->pdo, $tableId, 1, 2, 3, 12345);
        
        // Update version multiple times
        for ($i = 1; $i <= 10; $i++) {
            db_update_game_version($this->pdo, $gameId, $i);
            
            $game = db_get_active_game($this->pdo, $tableId);
            $this->assertNotNull($game);
            $this->assertSame($i, (int)$game['version'], "Version should be {$i} after update");
        }
    }

    /**
     * Test that multiple tables can have independent games
     */
    public function testMultipleTablesHaveIndependentGames(): void
    {
        $tableId1 = $this->createTestTable('Table 1');
        $tableId2 = $this->createTestTable('Table 2');
        
        $gameId1 = db_create_game($this->pdo, $tableId1, 1, 2, 3, 11111);
        $gameId2 = db_create_game($this->pdo, $tableId2, 2, 3, 4, 22222);
        
        // Verify each table has its own game
        $game1 = db_get_active_game($this->pdo, $tableId1);
        $game2 = db_get_active_game($this->pdo, $tableId2);
        
        $this->assertNotNull($game1);
        $this->assertNotNull($game2);
        $this->assertSame($gameId1, (int)$game1['id']);
        $this->assertSame($gameId2, (int)$game2['id']);
        
        // End one game - other should remain active
        db_end_game($this->pdo, $gameId1);
        
        $game1 = db_get_active_game($this->pdo, $tableId1);
        $game2 = db_get_active_game($this->pdo, $tableId2);
        
        $this->assertNull($game1, 'First game should no longer be active');
        $this->assertNotNull($game2, 'Second game should still be active');
    }
}

