<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseDBIntegrationTest.php';

/**
 * Integration tests for app/db/tables.php
 *
 * Comprehensive test suite for table database functions.
 * Tests all CRUD operations, edge cases, and business logic.
 *
 * Uses the actual MySQL database connection from bootstrap.php.
 * Tests use transactions for isolation - each test runs in a transaction
 * that is rolled back in tearDown to ensure test isolation.
 *
 * @coversNothing
 */
final class TablesDBTest extends BaseDBIntegrationTest
{
    /**
     * Load database functions required for table tests
     */
    protected function loadDatabaseFunctions(): void
    {
        require_once __DIR__ . '/../../../app/db/tables.php';
    }

    /**
     * Set up test environment with table-specific cleanup
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Clean up any existing table data for better isolation
        $this->pdo->exec("DELETE FROM tables");
    }

    // ============================================================================
    // CREATE TABLE TESTS
    // ============================================================================

    /**
     * Test that creating a table returns a valid table ID
     */
    public function testCreateTableReturnsValidId(): void
    {
        $tableId = db_create_table($this->pdo, 'Test Table 1', 6, 10, 20, 0);
        
        $this->assertNotNull($tableId, 'Table ID should not be null');
        $this->assertIsInt($tableId, 'Table ID should be an integer');
        $this->assertGreaterThan(0, $tableId, 'Table ID should be positive');
    }

    /**
     * Test that creating a table with all parameters stores them correctly
     */
    public function testCreateTableStoresAllParameters(): void
    {
        $tableId = db_create_table($this->pdo, 'Test Table 2', 8, 25, 50, 5);
        
        $table = db_get_table_by_id($this->pdo, $tableId);
        
        $this->assertNotNull($table, 'Table should exist after creation');
        $this->assertSame('Test Table 2', $table['name']);
        $this->assertSame(8, (int)$table['max_seats']);
        $this->assertSame(25, (int)$table['small_blind']);
        $this->assertSame(50, (int)$table['big_blind']);
        $this->assertSame(5, (int)$table['ante']);
        $this->assertSame('OPEN', $table['status']);
    }

    /**
     * Test that creating a table sets default status to OPEN
     */
    public function testCreateTableSetsDefaultStatusToOpen(): void
    {
        $tableId = db_create_table($this->pdo, 'Test Table 3', 6, 10, 20);
        
        $table = db_get_table_by_id($this->pdo, $tableId);
        
        $this->assertNotNull($table);
        $this->assertSame('OPEN', $table['status']);
    }

    /**
     * Test that creating a table sets created_at timestamp
     */
    public function testCreateTableSetsCreatedAtTimestamp(): void
    {
        $tableId = db_create_table($this->pdo, 'Test Table 4', 6, 10, 20);
        
        $table = db_get_table_by_id($this->pdo, $tableId);
        
        $this->assertNotNull($table);
        $this->assertNotNull($table['created_at'], 'created_at should be set');
        $this->assertRecentTimestamp('tables', 'created_at', $tableId);
    }

    // ============================================================================
    // GET TABLE BY ID TESTS
    // ============================================================================

    /**
     * Test that getting table by ID returns correct table data
     */
    public function testGetTableByIdReturnsCorrectData(): void
    {
        $tableId = db_create_table($this->pdo, 'Test Table 5', 6, 10, 20, 0);
        
        $table = db_get_table_by_id($this->pdo, $tableId);
        
        $this->assertNotNull($table, 'Table should be found');
        $this->assertSame($tableId, (int)$table['id']);
        $this->assertSame('Test Table 5', $table['name']);
        $this->assertArrayHasKey('max_seats', $table);
        $this->assertArrayHasKey('small_blind', $table);
        $this->assertArrayHasKey('big_blind', $table);
        $this->assertArrayHasKey('ante', $table);
        $this->assertArrayHasKey('status', $table);
        $this->assertArrayHasKey('created_at', $table);
    }

    /**
     * Test that getting table by non-existent ID returns null
     */
    public function testGetTableByIdReturnsNullForNonExistentId(): void
    {
        $table = db_get_table_by_id($this->pdo, 999999);
        
        $this->assertNull($table, 'Non-existent table should return null');
    }

    // ============================================================================
    // LIST ACTIVE TABLES TESTS
    // ============================================================================

    /**
     * Test that listing active tables returns only OPEN and IN_GAME tables
     */
    public function testListActiveTablesReturnsOnlyOpenAndInGameTables(): void
    {
        $tableId1 = db_create_table($this->pdo, 'Open Table', 6, 10, 20);
        $tableId2 = db_create_table($this->pdo, 'In Game Table', 6, 10, 20);
        $tableId3 = db_create_table($this->pdo, 'Closed Table', 6, 10, 20);
        
        // Set statuses
        db_update_table_status($this->pdo, $tableId1, 'OPEN');
        db_update_table_status($this->pdo, $tableId2, 'IN_GAME');
        db_update_table_status($this->pdo, $tableId3, 'CLOSED');
        
        $activeTables = db_list_active_tables($this->pdo);
        
        $this->assertIsArray($activeTables);
        $this->assertCount(2, $activeTables, 'Should return only OPEN and IN_GAME tables');
        
        $tableIds = array_map(fn($t) => (int)$t['id'], $activeTables);
        $this->assertContains($tableId1, $tableIds);
        $this->assertContains($tableId2, $tableIds);
        $this->assertNotContains($tableId3, $tableIds);
    }

    /**
     * Test that listing active tables returns empty array when no active tables
     */
    public function testListActiveTablesReturnsEmptyArrayWhenNoActiveTables(): void
    {
        $tableId = db_create_table($this->pdo, 'Closed Table', 6, 10, 20);
        db_update_table_status($this->pdo, $tableId, 'CLOSED');
        
        $activeTables = db_list_active_tables($this->pdo);
        
        $this->assertIsArray($activeTables);
        $this->assertCount(0, $activeTables, 'Should return empty array when no active tables');
    }

    /**
     * Test that listing active tables orders by created_at DESC
     */
    public function testListActiveTablesOrdersByCreatedAtDesc(): void
    {
        $tableId1 = db_create_table($this->pdo, 'Table 1', 6, 10, 20);
        usleep(100000); // 0.1 second delay to ensure different timestamps
        $tableId2 = db_create_table($this->pdo, 'Table 2', 6, 10, 20);
        
        $activeTables = db_list_active_tables($this->pdo);
        
        $this->assertIsArray($activeTables);
        $this->assertCount(2, $activeTables);
        
        // Most recent should be first
        $this->assertSame($tableId2, (int)$activeTables[0]['id'], 'Most recent table should be first');
        $this->assertSame($tableId1, (int)$activeTables[1]['id'], 'Older table should be second');
    }

    // ============================================================================
    // UPDATE TABLE STATUS TESTS
    // ============================================================================

    /**
     * Test that updating table status changes the status correctly
     */
    public function testUpdateTableStatusChangesStatus(): void
    {
        $tableId = db_create_table($this->pdo, 'Test Table 6', 6, 10, 20);
        
        // Update to IN_GAME
        $result = db_update_table_status($this->pdo, $tableId, 'IN_GAME');
        $this->assertTrue($result, 'Update should return true');
        
        $table = db_get_table_by_id($this->pdo, $tableId);
        $this->assertNotNull($table);
        $this->assertSame('IN_GAME', $table['status']);
        
        // Update to CLOSED
        $result = db_update_table_status($this->pdo, $tableId, 'CLOSED');
        $this->assertTrue($result, 'Update should return true');
        
        $table = db_get_table_by_id($this->pdo, $tableId);
        $this->assertNotNull($table);
        $this->assertSame('CLOSED', $table['status']);
        
        // Update back to OPEN
        $result = db_update_table_status($this->pdo, $tableId, 'OPEN');
        $this->assertTrue($result, 'Update should return true');
        
        $table = db_get_table_by_id($this->pdo, $tableId);
        $this->assertNotNull($table);
        $this->assertSame('OPEN', $table['status']);
    }

    /**
     * Test that updating table status with invalid status returns false
     */
    public function testUpdateTableStatusWithInvalidStatusReturnsFalse(): void
    {
        $tableId = db_create_table($this->pdo, 'Test Table 7', 6, 10, 20);
        
        $result = db_update_table_status($this->pdo, $tableId, 'INVALID_STATUS');
        
        $this->assertFalse($result, 'Invalid status should return false');
        
        // Status should remain unchanged
        $table = db_get_table_by_id($this->pdo, $tableId);
        $this->assertNotNull($table);
        $this->assertSame('OPEN', $table['status'], 'Status should remain unchanged');
    }

    /**
     * Test that updating status for non-existent table returns false
     */
    public function testUpdateTableStatusForNonExistentTableReturnsFalse(): void
    {
        $result = db_update_table_status($this->pdo, 999999, 'IN_GAME');
        
        // Function should return false (no rows affected)
        $this->assertFalse($result, 'Non-existent table should return false');
    }

    // ============================================================================
    // INTEGRATION TESTS
    // ============================================================================

    /**
     * Test full table lifecycle: create, update status, list, retrieve
     */
    public function testFullTableLifecycle(): void
    {
        // 1. Create table
        $tableId = db_create_table($this->pdo, 'Lifecycle Table', 6, 10, 20, 0);
        $this->assertNotNull($tableId);
        
        // 2. Verify initial state
        $table = db_get_table_by_id($this->pdo, $tableId);
        $this->assertNotNull($table);
        $this->assertSame('OPEN', $table['status']);
        
        // 3. Update to IN_GAME
        db_update_table_status($this->pdo, $tableId, 'IN_GAME');
        $table = db_get_table_by_id($this->pdo, $tableId);
        $this->assertSame('IN_GAME', $table['status']);
        
        // 4. Verify it appears in active tables
        $activeTables = db_list_active_tables($this->pdo);
        $tableIds = array_map(fn($t) => (int)$t['id'], $activeTables);
        $this->assertContains($tableId, $tableIds);
        
        // 5. Close table
        db_update_table_status($this->pdo, $tableId, 'CLOSED');
        $table = db_get_table_by_id($this->pdo, $tableId);
        $this->assertSame('CLOSED', $table['status']);
        
        // 6. Verify it no longer appears in active tables
        $activeTables = db_list_active_tables($this->pdo);
        $tableIds = array_map(fn($t) => (int)$t['id'], $activeTables);
        $this->assertNotContains($tableId, $tableIds);
    }

    /**
     * Test that multiple tables can coexist independently
     */
    public function testMultipleTablesCoexistIndependently(): void
    {
        $tableId1 = db_create_table($this->pdo, 'Table A', 6, 10, 20);
        $tableId2 = db_create_table($this->pdo, 'Table B', 8, 25, 50);
        $tableId3 = db_create_table($this->pdo, 'Table C', 4, 5, 10);
        
        // Update statuses independently
        db_update_table_status($this->pdo, $tableId1, 'IN_GAME');
        db_update_table_status($this->pdo, $tableId2, 'CLOSED');
        // tableId3 remains OPEN
        
        // Verify each table's status
        $table1 = db_get_table_by_id($this->pdo, $tableId1);
        $table2 = db_get_table_by_id($this->pdo, $tableId2);
        $table3 = db_get_table_by_id($this->pdo, $tableId3);
        
        $this->assertSame('IN_GAME', $table1['status']);
        $this->assertSame('CLOSED', $table2['status']);
        $this->assertSame('OPEN', $table3['status']);
        
        // Verify active tables list
        $activeTables = db_list_active_tables($this->pdo);
        $tableIds = array_map(fn($t) => (int)$t['id'], $activeTables);
        $this->assertContains($tableId1, $tableIds);
        $this->assertNotContains($tableId2, $tableIds);
        $this->assertContains($tableId3, $tableIds);
    }
}

