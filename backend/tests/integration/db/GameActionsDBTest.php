<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseDBIntegrationTest.php';

/**
 * Integration tests for app/db/game_actions.php (game_actions table functions)
 *
 * Comprehensive test suite for game action database functions.
 * Tests all CRUD operations, edge cases, and business logic.
 *
 * Uses the actual MySQL database connection from bootstrap.php.
 * Tests use transactions for isolation - each test runs in a transaction
 * that is rolled back in tearDown to ensure test isolation.
 *
 * @coversNothing
 */
final class GameActionsDBTest extends BaseDBIntegrationTest
{
    /**
     * Load database functions required for game action tests
     */
    protected function loadDatabaseFunctions(): void
    {
        require_once __DIR__ . '/../../../app/db/tables.php';
        require_once __DIR__ . '/../../../app/db/games.php';
        require_once __DIR__ . '/../../../app/db/game_actions.php';
    }

    /**
     * Set up test environment with game action-specific cleanup
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Clean up any existing game action data for better isolation
        $this->pdo->exec("DELETE FROM game_actions");
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

    /**
     * Helper: Create a test game and return game ID
     */
    private function createTestGame(int $tableId): int
    {
        $gameId = db_create_game($this->pdo, $tableId, 1, 2, 3, 12345);
        $this->assertNotNull($gameId);
        return $gameId;
    }

    // ============================================================================
    // INSERT ACTION TESTS
    // ============================================================================

    /**
     * Test that inserting an action returns true
     */
    public function testInsertActionReturnsTrue(): void
    {
        $tableId = $this->createTestTable();
        $gameId = $this->createTestGame($tableId);
        
        $result = db_insert_action($this->pdo, $gameId, 1, 1, 'check', 0, []);
        
        $this->assertTrue($result, 'Inserting action should return true');
    }

    /**
     * Test that inserting an action stores all parameters correctly
     */
    public function testInsertActionStoresAllParameters(): void
    {
        $tableId = $this->createTestTable();
        $gameId = $this->createTestGame($tableId);
        
        $data = ['pot' => 100, 'phase' => 'preflop'];
        db_insert_action($this->pdo, $gameId, 1, 2, 'bet', 50, $data);
        
        $actions = db_get_actions($this->pdo, $gameId);
        
        $this->assertCount(1, $actions, 'Should have one action');
        $action = $actions[0];
        $this->assertSame($gameId, (int)$action['game_id']);
        $this->assertSame(1, (int)$action['seq']);
        $this->assertSame(2, (int)$action['actor_seat']);
        $this->assertSame('bet', $action['action_type']);
        $this->assertSame(50, (int)$action['amount']);
        $this->assertIsArray($action['data']);
        $this->assertSame(100, $action['data']['pot']);
        $this->assertSame('preflop', $action['data']['phase']);
    }

    /**
     * Test that inserting an action with null actor_seat works
     */
    public function testInsertActionWithNullActorSeat(): void
    {
        $tableId = $this->createTestTable();
        $gameId = $this->createTestGame($tableId);
        
        $result = db_insert_action($this->pdo, $gameId, 1, null, 'deal_flop', 0, []);
        
        $this->assertTrue($result);
        
        $actions = db_get_actions($this->pdo, $gameId);
        $this->assertCount(1, $actions);
        $this->assertNull($actions[0]['actor_seat'], 'actor_seat should be null for system actions');
    }

    /**
     * Test that inserting an action sets created_at timestamp
     */
    public function testInsertActionSetsCreatedAtTimestamp(): void
    {
        $tableId = $this->createTestTable();
        $gameId = $this->createTestGame($tableId);
        
        db_insert_action($this->pdo, $gameId, 1, 1, 'check', 0, []);
        
        $actions = db_get_actions($this->pdo, $gameId);
        $action = $actions[0];
        
        $this->assertNotNull($action['created_at'], 'created_at should be set');
        $this->assertRecentTimestamp('game_actions', 'created_at', $action['id']);
    }

    // ============================================================================
    // GET ACTIONS TESTS
    // ============================================================================

    /**
     * Test that getting actions returns all actions ordered by seq
     */
    public function testGetActionsReturnsAllActionsOrderedBySeq(): void
    {
        $tableId = $this->createTestTable();
        $gameId = $this->createTestGame($tableId);
        
        // Insert actions in non-sequential order
        db_insert_action($this->pdo, $gameId, 3, 1, 'call', 20, []);
        db_insert_action($this->pdo, $gameId, 1, 1, 'check', 0, []);
        db_insert_action($this->pdo, $gameId, 2, 2, 'bet', 50, []);
        
        $actions = db_get_actions($this->pdo, $gameId);
        
        $this->assertIsArray($actions);
        $this->assertCount(3, $actions, 'Should have three actions');
        
        // Verify ordering
        $this->assertSame(1, (int)$actions[0]['seq']);
        $this->assertSame(2, (int)$actions[1]['seq']);
        $this->assertSame(3, (int)$actions[2]['seq']);
    }

    /**
     * Test that getting actions returns empty array when no actions
     */
    public function testGetActionsReturnsEmptyArrayWhenNoActions(): void
    {
        $tableId = $this->createTestTable();
        $gameId = $this->createTestGame($tableId);
        
        $actions = db_get_actions($this->pdo, $gameId);
        
        $this->assertIsArray($actions);
        $this->assertCount(0, $actions, 'Should return empty array when no actions');
    }

    /**
     * Test that getting actions includes all required fields
     */
    public function testGetActionsIncludesAllRequiredFields(): void
    {
        $tableId = $this->createTestTable();
        $gameId = $this->createTestGame($tableId);
        
        db_insert_action($this->pdo, $gameId, 1, 1, 'check', 0, ['test' => 'data']);
        
        $actions = db_get_actions($this->pdo, $gameId);
        $action = $actions[0];
        
        $this->assertArrayHasKey('id', $action);
        $this->assertArrayHasKey('game_id', $action);
        $this->assertArrayHasKey('seq', $action);
        $this->assertArrayHasKey('actor_seat', $action);
        $this->assertArrayHasKey('action_type', $action);
        $this->assertArrayHasKey('amount', $action);
        $this->assertArrayHasKey('data', $action);
        $this->assertArrayHasKey('created_at', $action);
    }

    /**
     * Test that getting actions decodes JSON data correctly
     */
    public function testGetActionsDecodesJsonDataCorrectly(): void
    {
        $tableId = $this->createTestTable();
        $gameId = $this->createTestGame($tableId);
        
        $data = [
            'pot' => 100,
            'phase' => 'flop',
            'board' => ['AS', 'KD', 'QC'],
            'nested' => ['key' => 'value'],
        ];
        
        db_insert_action($this->pdo, $gameId, 1, 1, 'bet', 50, $data);
        
        $actions = db_get_actions($this->pdo, $gameId);
        $action = $actions[0];
        
        $this->assertIsArray($action['data']);
        $this->assertSame(100, $action['data']['pot']);
        $this->assertSame('flop', $action['data']['phase']);
        $this->assertIsArray($action['data']['board']);
        $this->assertSame('AS', $action['data']['board'][0]);
        $this->assertIsArray($action['data']['nested']);
        $this->assertSame('value', $action['data']['nested']['key']);
    }

    // ============================================================================
    // GET LAST SEQ TESTS
    // ============================================================================

    /**
     * Test that getting last seq returns 0 when no actions
     */
    public function testGetLastSeqReturnsZeroWhenNoActions(): void
    {
        $tableId = $this->createTestTable();
        $gameId = $this->createTestGame($tableId);
        
        $lastSeq = db_get_last_seq($this->pdo, $gameId);
        
        $this->assertSame(0, $lastSeq, 'Should return 0 when no actions');
    }

    /**
     * Test that getting last seq returns highest sequence number
     */
    public function testGetLastSeqReturnsHighestSequenceNumber(): void
    {
        $tableId = $this->createTestTable();
        $gameId = $this->createTestGame($tableId);
        
        db_insert_action($this->pdo, $gameId, 1, 1, 'check', 0, []);
        db_insert_action($this->pdo, $gameId, 2, 2, 'bet', 50, []);
        db_insert_action($this->pdo, $gameId, 5, 1, 'call', 50, []);
        db_insert_action($this->pdo, $gameId, 3, 2, 'fold', 0, []);
        
        $lastSeq = db_get_last_seq($this->pdo, $gameId);
        
        $this->assertSame(5, $lastSeq, 'Should return highest sequence number');
    }

    /**
     * Test that getting last seq works correctly after sequential inserts
     */
    public function testGetLastSeqWorksAfterSequentialInserts(): void
    {
        $tableId = $this->createTestTable();
        $gameId = $this->createTestGame($tableId);
        
        for ($i = 1; $i <= 10; $i++) {
            db_insert_action($this->pdo, $gameId, $i, 1, 'check', 0, []);
            $lastSeq = db_get_last_seq($this->pdo, $gameId);
            $this->assertSame($i, $lastSeq, "Last seq should be {$i} after inserting seq {$i}");
        }
    }

    // ============================================================================
    // INTEGRATION TESTS
    // ============================================================================

    /**
     * Test full action lifecycle: insert multiple actions, retrieve, verify ordering
     */
    public function testFullActionLifecycle(): void
    {
        $tableId = $this->createTestTable();
        $gameId = $this->createTestGame($tableId);
        
        // Insert multiple actions
        $actionsToInsert = [
            [1, 1, 'check', 0],
            [2, 2, 'bet', 50],
            [3, 1, 'call', 50],
            [4, 2, 'check', 0],
        ];
        
        foreach ($actionsToInsert as [$seq, $seat, $type, $amount]) {
            db_insert_action($this->pdo, $gameId, $seq, $seat, $type, $amount, []);
        }
        
        // Verify all actions are stored
        $actions = db_get_actions($this->pdo, $gameId);
        $this->assertCount(4, $actions, 'Should have four actions');
        
        // Verify ordering
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame($i + 1, (int)$actions[$i]['seq'], "Action {$i} should have seq " . ($i + 1));
        }
        
        // Verify last seq
        $lastSeq = db_get_last_seq($this->pdo, $gameId);
        $this->assertSame(4, $lastSeq, 'Last seq should be 4');
    }

    /**
     * Test that sequential logging works correctly
     */
    public function testSequentialLoggingWorksCorrectly(): void
    {
        $tableId = $this->createTestTable();
        $gameId = $this->createTestGame($tableId);
        
        // Insert actions sequentially
        for ($seq = 1; $seq <= 10; $seq++) {
            $seat = ($seq % 2) + 1; // Alternate between seat 1 and 2
            $type = $seq % 2 === 0 ? 'bet' : 'check';
            $amount = $seq % 2 === 0 ? 50 : 0;
            
            db_insert_action($this->pdo, $gameId, $seq, $seat, $type, $amount, []);
            
            // Verify last seq matches
            $lastSeq = db_get_last_seq($this->pdo, $gameId);
            $this->assertSame($seq, $lastSeq, "Last seq should be {$seq} after inserting seq {$seq}");
        }
        
        // Verify all actions are retrieved in order
        $actions = db_get_actions($this->pdo, $gameId);
        $this->assertCount(10, $actions);
        
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame($i + 1, (int)$actions[$i]['seq'], "Action {$i} should have seq " . ($i + 1));
        }
    }

    /**
     * Test that multiple games have isolated action data
     */
    public function testMultipleGamesHaveIsolatedActions(): void
    {
        $tableId1 = $this->createTestTable('Table 1');
        $tableId2 = $this->createTestTable('Table 2');
        
        $gameId1 = $this->createTestGame($tableId1);
        $gameId2 = $this->createTestGame($tableId2);
        
        // Insert actions for both games
        db_insert_action($this->pdo, $gameId1, 1, 1, 'check', 0, []);
        db_insert_action($this->pdo, $gameId1, 2, 2, 'bet', 50, []);
        db_insert_action($this->pdo, $gameId2, 1, 1, 'fold', 0, []);
        
        // Verify each game has its own actions
        $actions1 = db_get_actions($this->pdo, $gameId1);
        $actions2 = db_get_actions($this->pdo, $gameId2);
        
        $this->assertCount(2, $actions1, 'Game 1 should have 2 actions');
        $this->assertCount(1, $actions2, 'Game 2 should have 1 action');
        
        // Verify last seq is independent
        $lastSeq1 = db_get_last_seq($this->pdo, $gameId1);
        $lastSeq2 = db_get_last_seq($this->pdo, $gameId2);
        
        $this->assertSame(2, $lastSeq1, 'Game 1 last seq should be 2');
        $this->assertSame(1, $lastSeq2, 'Game 2 last seq should be 1');
    }

    /**
     * Test that action data with complex JSON structures is preserved
     */
    public function testActionDataWithComplexJsonStructures(): void
    {
        $tableId = $this->createTestTable();
        $gameId = $this->createTestGame($tableId);
        
        $complexData = [
            'pot' => 1000,
            'phase' => 'river',
            'board' => ['AS', 'KD', 'QC', 'JH', '10S'],
            'players' => [
                ['seat' => 1, 'stack' => 500, 'bet' => 100],
                ['seat' => 2, 'stack' => 400, 'bet' => 100],
            ],
            'metadata' => [
                'timestamp' => time(),
                'version' => 42,
            ],
        ];
        
        db_insert_action($this->pdo, $gameId, 1, 1, 'allin', 500, $complexData);
        
        $actions = db_get_actions($this->pdo, $gameId);
        $action = $actions[0];
        
        $this->assertIsArray($action['data']);
        $this->assertSame(1000, $action['data']['pot']);
        $this->assertSame('river', $action['data']['phase']);
        $this->assertIsArray($action['data']['board']);
        $this->assertCount(5, $action['data']['board']);
        $this->assertIsArray($action['data']['players']);
        $this->assertCount(2, $action['data']['players']);
        $this->assertSame(1, $action['data']['players'][0]['seat']);
        $this->assertIsArray($action['data']['metadata']);
        $this->assertSame(42, $action['data']['metadata']['version']);
    }
}

