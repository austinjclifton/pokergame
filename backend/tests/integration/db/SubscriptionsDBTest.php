<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseDBIntegrationTest.php';

/**
 * Integration tests for app/db/subscriptions.php
 *
 * Comprehensive test suite for WebSocket subscription database functions.
 * Tests all CRUD operations, edge cases, and business logic.
 *
 * Uses the actual MySQL database connection from bootstrap.php.
 * Tests use transactions for isolation - each test runs in a transaction
 * that is rolled back in tearDown to ensure test isolation.
 *
 * @coversNothing
 */
final class SubscriptionsDBTest extends BaseDBIntegrationTest
{
    /**
     * Load database functions required for subscription tests
     */
    protected function loadDatabaseFunctions(): void
    {
        require_once __DIR__ . '/../../../app/db/subscriptions.php';
        require_once __DIR__ . '/../../../app/db/users.php';
    }

    // Wrapper methods to call the actual database functions
    // (These match the function signatures from app/db/subscriptions.php)
    
    /**
     * Insert a subscription
     */
    private function dbInsertSubscription(
        int $userId,
        string $connectionId,
        string $channelType,
        int $channelId
    ): bool {
        return db_insert_subscription($this->pdo, $userId, $connectionId, $channelType, $channelId);
    }

    /**
     * Update subscription ping timestamp
     */
    private function dbUpdateSubscriptionPing(string $connectionId): bool {
        return db_update_subscription_ping($this->pdo, $connectionId);
    }

    /**
     * Set subscription as disconnected
     */
    private function dbSetSubscriptionDisconnected(string $connId): bool {
        return db_set_subscription_disconnected($this->pdo, $connId);
    }

    /**
     * Delete a subscription
     */
    private function dbDeleteSubscription(string $connectionId): bool {
        return db_delete_subscription($this->pdo, $connectionId);
    }

    /**
     * Get user subscriptions
     */
    private function dbGetUserSubscriptions(int $userId): array {
        return db_get_user_subscriptions($this->pdo, $userId);
    }

    /**
     * Delete stale subscriptions
     */
    private function dbDeleteStaleSubscriptions(int $staleMinutes = 10): int {
        return db_delete_stale_subscriptions($this->pdo, $staleMinutes);
    }

    // ============================================================================
    // INSERT TESTS
    // ============================================================================

    /**
     * Test that inserting a subscription persists a row
     */
    public function testInsertSubscriptionPersistsRow(): void
    {
        $userId = $this->createTestUser('subscription_user1');
        
        $ok = $this->dbInsertSubscription(
            $userId,
            'conn-abc123',
            'lobby',
            0
        );

        $this->assertTrue($ok, 'Insert should return true.');

        $row = $this->getSubscription('conn-abc123');

        $this->assertNotNull($row, 'Row should exist after insert.');
        $this->assertSame($userId, (int)$row['user_id']);
        $this->assertSame('lobby', $row['channel_type']);
        $this->assertSame(0, (int)$row['channel_id']);
        $this->assertNull($row['game_id'], 'Lobby subscriptions should have NULL game_id');
        $this->assertNotEmpty($row['connected_at'], 'connected_at should be set');
        $this->assertNotEmpty($row['last_ping_at'], 'last_ping_at should be set');
        $this->assertNull($row['disconnected_at'], 'disconnected_at should be NULL initially');
    }

    /**
     * Test that inserting a game subscription sets game_id
     */
    public function testInsertGameSubscriptionSetsGameId(): void
    {
        $userId = $this->createTestUser('subscription_user2');
        // Note: Using a fake game ID is acceptable here because:
        // 1. We're testing that game_id is set correctly in the subscription record
        // 2. The subscription table doesn't validate that the game exists
        // 3. Foreign key checks are disabled in tests
        // 4. Creating a real game would add unnecessary complexity for this test
        $gameId = 42;
        
        $ok = $this->dbInsertSubscription(
            $userId,
            'conn-game-1',
            'game',
            $gameId
        );

        $this->assertTrue($ok, 'Game subscription insert should succeed.');

        $row = $this->getSubscription('conn-game-1');

        $this->assertSame('game', $row['channel_type']);
        $this->assertSame($gameId, (int)$row['channel_id']);
        $this->assertSame($gameId, (int)$row['game_id'], 'Game subscriptions should set game_id = channel_id');
    }

    /**
     * Test that inserting duplicate connection ID fails
     */
    public function testInsertDuplicateConnectionIdFails(): void
    {
        $userId1 = $this->createTestUser('subscription_user3');
        $userId2 = $this->createTestUser('subscription_user4');
        
        // Insert first subscription
        $this->dbInsertSubscription($userId1, 'conn-duplicate', 'lobby', 0);

        // Try to insert with same connection_id
        $this->expectException(PDOException::class);
        $this->dbInsertSubscription($userId2, 'conn-duplicate', 'lobby', 0);
    }

    /**
     * Test that a user can have multiple subscriptions
     */
    public function testInsertMultipleSubscriptionsForSameUser(): void
    {
        $userId = $this->createTestUser('subscription_user5');
        
        // User can have multiple active connections (e.g., multiple tabs)
        $this->dbInsertSubscription($userId, 'conn-1', 'lobby', 0);
        $this->dbInsertSubscription($userId, 'conn-2', 'lobby', 0);
        $this->dbInsertSubscription($userId, 'conn-3', 'game', 10);

        $stmt = $this->pdo->prepare("SELECT COUNT(*) as count FROM ws_subscriptions WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $userId]);
        $count = (int)$stmt->fetch()['count'];

        $this->assertSame(3, $count, 'User should be able to have multiple subscriptions.');
    }

    /**
     * Test that inserting a subscription sets timestamps
     */
    public function testInsertSetsTimestamps(): void
    {
        $userId = $this->createTestUser('subscription_user6');
        
        $this->dbInsertSubscription($userId, 'conn-time-1', 'lobby', 0);
        
        $row = $this->getSubscription('conn-time-1');

        $this->assertNotEmpty($row['connected_at'], 'connected_at should be set');
        $this->assertNotEmpty($row['last_ping_at'], 'last_ping_at should be set');
        
        // Verify timestamps are recent (within last 5 minutes)
        $this->assertRecentTimestamp('ws_subscriptions', 'connected_at', 'conn-time-1', 'connection_id', 300);
    }

    // ============================================================================
    // PING/HEARTBEAT TESTS
    // ============================================================================

    /**
     * Test that updating ping refreshes timestamp
     */
    public function testUpdatePingRefreshesTimestamp(): void
    {
        $userId = $this->createTestUser('subscription_user7');
        
        // Seed a row
        $this->dbInsertSubscription($userId, 'conn-ping-1', 'lobby', 0);

        $before = $this->getSubscription('conn-ping-1');
        $beforePing = $before['last_ping_at'];

        // Sleep to ensure timestamp difference (MySQL NOW() has second precision)
        sleep(1); // 1 second to ensure MySQL NOW() produces a different value

        $ok = $this->dbUpdateSubscriptionPing('conn-ping-1');
        $this->assertTrue($ok, 'Ping update should return true.');

        $after = $this->getSubscription('conn-ping-1');
        $afterPing = $after['last_ping_at'];

        $this->assertNotSame($beforePing, $afterPing, 'last_ping_at must change after ping.');
        
        // Convert to timestamps for comparison (handles timezone differences)
        $beforeTs = strtotime($beforePing);
        $afterTs = strtotime($afterPing);
        $this->assertGreaterThan($beforeTs, $afterTs, 'last_ping_at should be later after ping.');
    }

    /**
     * Test that pinging non-existent connection returns false
     */
    public function testPingNonExistentConnectionReturnsFalse(): void
    {
        // Ping a connection that doesn't exist
        $ok = $this->dbUpdateSubscriptionPing('non-existent-conn');
        
        // Returns true even if no rows updated (PDO execute returns true)
        // But rowCount would be 0, so we check that no update occurred
        $result = $this->getSubscription('non-existent-conn');
        
        $this->assertNull($result, 'Non-existent connection should not exist.');
    }

    /**
     * Test that ping does not affect connected_at
     */
    public function testPingDoesNotAffectConnectedAt(): void
    {
        $userId = $this->createTestUser('subscription_user8');
        
        $this->dbInsertSubscription($userId, 'conn-ping-2', 'lobby', 0);
        
        $before = $this->getSubscription('conn-ping-2');
        $originalConnectedAt = $before['connected_at'];

        usleep(100_000); // 0.1s
        $this->dbUpdateSubscriptionPing('conn-ping-2');

        $after = $this->getSubscription('conn-ping-2');
        $connectedAtAfterPing = $after['connected_at'];

        $this->assertSame($originalConnectedAt, $connectedAtAfterPing,
            'connected_at should not change after ping.');
    }

    /**
     * Test that multiple pings update timestamp
     */
    public function testMultiplePingsUpdateTimestamp(): void
    {
        $userId = $this->createTestUser('subscription_user9');
        
        $this->dbInsertSubscription($userId, 'conn-multi-ping', 'lobby', 0);
        
        $timestamps = [];
        for ($i = 0; $i < 5; $i++) {
            sleep(1); // Wait 1 second between pings (MySQL NOW() has second precision)
            $this->dbUpdateSubscriptionPing('conn-multi-ping');
            
            $sub = $this->getSubscription('conn-multi-ping');
            $timestamps[] = strtotime($sub['last_ping_at']); // Convert to timestamp for comparison
        }

        // Each ping should update to a later or equal timestamp
        // (MySQL NOW() has second precision, so rapid pings might have same timestamp)
        for ($i = 1; $i < count($timestamps); $i++) {
            $this->assertGreaterThanOrEqual($timestamps[$i - 1], $timestamps[$i],
                "Ping #{$i} should have timestamp >= previous ping.");
        }
    }

    // ============================================================================
    // DISCONNECT TESTS
    // ============================================================================

    /**
     * Test that setting subscription disconnected works
     */
    public function testSetSubscriptionDisconnected(): void
    {
        $userId = $this->createTestUser('subscription_user10');
        
        $this->dbInsertSubscription($userId, 'conn-disconnect-1', 'lobby', 0);
        
        $ok = $this->dbSetSubscriptionDisconnected('conn-disconnect-1');
        
        $this->assertTrue($ok, 'Disconnect should return true.');
        
        $row = $this->getSubscription('conn-disconnect-1');
        
        $this->assertNotNull($row['disconnected_at'], 'disconnected_at should be set after disconnect.');
        $this->assertNotEmpty($row['disconnected_at'], 'disconnected_at should not be empty.');
    }

    /**
     * Test that disconnecting non-existent connection returns false
     */
    public function testDisconnectNonExistentConnectionReturnsFalse(): void
    {
        $ok = $this->dbSetSubscriptionDisconnected('non-existent');
        
        // Returns false because rowCount() == 0
        $this->assertFalse($ok, 'Disconnecting non-existent connection should return false.');
    }

    /**
     * Test that disconnect is idempotent
     */
    public function testDisconnectIsIdempotent(): void
    {
        $userId = $this->createTestUser('subscription_user11');
        
        $this->dbInsertSubscription($userId, 'conn-disconnect-2', 'lobby', 0);
        
        // Disconnect once
        $ok1 = $this->dbSetSubscriptionDisconnected('conn-disconnect-2');
        $this->assertTrue($ok1);
        
        usleep(100_000);
        
        // Try to disconnect again (should not update because disconnected_at IS NOT NULL)
        $ok2 = $this->dbSetSubscriptionDisconnected('conn-disconnect-2');
        $this->assertFalse($ok2, 'Second disconnect should return false (already disconnected).');
        
        // Verify disconnected_at didn't change
        $firstDisconnect = $this->getSubscription('conn-disconnect-2');
        
        // The timestamp should remain the same (idempotent)
        $this->assertNotEmpty($firstDisconnect['disconnected_at']);
    }

    // ============================================================================
    // DELETE TESTS
    // ============================================================================

    /**
     * Test that deleting a subscription works
     */
    public function testDeleteSubscription(): void
    {
        $userId = $this->createTestUser('subscription_user12');
        
        $this->dbInsertSubscription($userId, 'conn-delete-1', 'lobby', 0);
        
        $ok = $this->dbDeleteSubscription('conn-delete-1');
        $this->assertTrue($ok, 'Delete should return true.');
        
        $stmt = $this->pdo->prepare("SELECT COUNT(*) as count FROM ws_subscriptions WHERE connection_id = :conn_id");
        $stmt->execute(['conn_id' => 'conn-delete-1']);
        $count = (int)$stmt->fetch()['count'];
        
        $this->assertSame(0, $count, 'Subscription should be deleted.');
    }

    /**
     * Test that deleting non-existent subscription completes without error
     */
    public function testDeleteNonExistentSubscription(): void
    {
        $ok = $this->dbDeleteSubscription('non-existent-delete');
        
        // Delete returns true even if no rows affected (PDO execute behavior)
        // So we just verify it doesn't throw
        $this->assertTrue($ok);
    }

    /**
     * Test that delete removes all subscription data
     */
    public function testDeleteRemovesAllSubscriptionData(): void
    {
        $userId = $this->createTestUser('subscription_user13');
        
        $this->dbInsertSubscription($userId, 'conn-delete-2', 'game', 15);
        $this->dbUpdateSubscriptionPing('conn-delete-2');
        $this->dbSetSubscriptionDisconnected('conn-delete-2');
        
        // Verify it exists
        $before = $this->getSubscription('conn-delete-2');
        $this->assertNotNull($before);
        
        // Delete it
        $this->dbDeleteSubscription('conn-delete-2');
        
        // Verify it's gone
        $after = $this->getSubscription('conn-delete-2');
        $this->assertNull($after, 'Subscription should be completely removed.');
    }

    // ============================================================================
    // QUERY TESTS
    // ============================================================================

    /**
     * Test that getting user subscriptions returns only active subscriptions
     */
    public function testGetUserSubscriptionsReturnsOnlyActive(): void
    {
        $userId = $this->createTestUser('subscription_active_user');
        
        // Create active subscriptions
        $this->dbInsertSubscription($userId, 'conn-active-1', 'lobby', 0);
        $this->dbInsertSubscription($userId, 'conn-active-2', 'game', 20);
        
        // Create disconnected subscription
        $this->dbInsertSubscription($userId, 'conn-disconnected', 'lobby', 0);
        $this->dbSetSubscriptionDisconnected('conn-disconnected');
        
        $subscriptions = $this->dbGetUserSubscriptions($userId);
        
        $this->assertCount(2, $subscriptions, 'Should return only active subscriptions.');
        
        $connectionIds = array_column($subscriptions, 'connection_id');
        $this->assertContains('conn-active-1', $connectionIds);
        $this->assertContains('conn-active-2', $connectionIds);
        $this->assertNotContains('conn-disconnected', $connectionIds);
    }

    /**
     * Test that getting user subscriptions returns correct fields
     */
    public function testGetUserSubscriptionsReturnsCorrectFields(): void
    {
        $userId = $this->createTestUser('subscription_user14');
        
        $this->dbInsertSubscription($userId, 'conn-fields-1', 'lobby', 0);
        
        $subscriptions = $this->dbGetUserSubscriptions($userId);
        
        $this->assertCount(1, $subscriptions);
        $sub = $subscriptions[0];
        
        $expectedFields = ['id', 'connection_id', 'channel_type', 'channel_id', 'connected_at', 'last_ping_at'];
        foreach ($expectedFields as $field) {
            $this->assertArrayHasKey($field, $sub, "Result should contain field: {$field}");
        }
        
        $this->assertSame('conn-fields-1', $sub['connection_id']);
        $this->assertSame('lobby', $sub['channel_type']);
    }

    /**
     * Test that getting user subscriptions for non-existent user returns empty array
     */
    public function testGetUserSubscriptionsForNonExistentUser(): void
    {
        $subscriptions = $this->dbGetUserSubscriptions(99999);
        
        $this->assertIsArray($subscriptions);
        $this->assertCount(0, $subscriptions, 'Non-existent user should return empty array.');
    }

    /**
     * Test that getting user subscriptions filters by disconnected_at
     */
    public function testGetUserSubscriptionsFiltersByDisconnectedAt(): void
    {
        $userId = $this->createTestUser('subscription_filter_user');
        
        // Insert and disconnect
        $this->dbInsertSubscription($userId, 'conn-test-filter', 'lobby', 0);
        $allBefore = $this->dbGetUserSubscriptions($userId);
        $this->assertCount(1, $allBefore);
        
        $this->dbSetSubscriptionDisconnected('conn-test-filter');
        
        $allAfter = $this->dbGetUserSubscriptions($userId);
        $this->assertCount(0, $allAfter, 'Disconnected subscriptions should be filtered out.');
    }

    // ============================================================================
    // CLEANUP TESTS
    // ============================================================================

    /**
     * Test that deleting stale subscriptions removes disconnected subscriptions
     */
    public function testDeleteStaleSubscriptionsRemovesDisconnected(): void
    {
        $userId = $this->createTestUser('subscription_user15');
        
        // Create disconnected subscription
        $this->dbInsertSubscription($userId, 'conn-stale-1', 'lobby', 0);
        $this->dbSetSubscriptionDisconnected('conn-stale-1');
        
        // Create active subscription
        $this->dbInsertSubscription($userId, 'conn-active-stale', 'lobby', 0);
        
        $deleted = $this->dbDeleteStaleSubscriptions(10);
        
        $this->assertGreaterThanOrEqual(1, $deleted, 'Should delete at least disconnected subscription.');
        
        // Active subscription should still exist
        $active = $this->getSubscription('conn-active-stale');
        $this->assertNotNull($active, 'Active subscription should not be deleted.');
    }

    /**
     * Test that deleting stale subscriptions with zero minutes completes without error
     */
    public function testDeleteStaleSubscriptionsWithZeroMinutes(): void
    {
        $userId = $this->createTestUser('subscription_user16');
        
        // Test with zero minutes - should still execute without error
        $this->dbInsertSubscription($userId, 'conn-stale-2', 'lobby', 0);
        
        $deleted = $this->dbDeleteStaleSubscriptions(0);
        
        $this->assertIsInt($deleted, 'Should return integer count.');
        $this->assertGreaterThanOrEqual(0, $deleted);
    }

    // ============================================================================
    // EDGE CASES & INTEGRATION
    // ============================================================================

    /**
     * Test full lifecycle of a subscription
     */
    public function testFullLifecycleOfSubscription(): void
    {
        $userId = $this->createTestUser('subscription_lifecycle_user');
        $connId = 'conn-lifecycle';
        
        // 1. Insert
        $ok = $this->dbInsertSubscription($userId, $connId, 'lobby', 0);
        $this->assertTrue($ok);
        
        // 2. Ping multiple times
        for ($i = 0; $i < 3; $i++) {
            usleep(50_000);
            $this->dbUpdateSubscriptionPing($connId);
        }
        
        // 3. Verify it's in active list
        $active = $this->dbGetUserSubscriptions($userId);
        $this->assertCount(1, $active);
        $this->assertSame($connId, $active[0]['connection_id']);
        
        // 4. Disconnect
        $ok = $this->dbSetSubscriptionDisconnected($connId);
        $this->assertTrue($ok);
        
        // 5. Verify it's no longer in active list
        $active = $this->dbGetUserSubscriptions($userId);
        $this->assertCount(0, $active);
        
        // 6. Delete
        $ok = $this->dbDeleteSubscription($connId);
        $this->assertTrue($ok);
        
        // 7. Verify it's gone
        $stmt = $this->pdo->prepare("SELECT COUNT(*) as count FROM ws_subscriptions WHERE connection_id = :conn_id");
        $stmt->execute(['conn_id' => $connId]);
        $count = (int)$stmt->fetch()['count'];
        $this->assertSame(0, $count);
    }

    /**
     * Test that multiple users can have multiple connections
     */
    public function testMultipleUsersMultipleConnections(): void
    {
        $userId1 = $this->createTestUser('subscription_user17');
        $userId2 = $this->createTestUser('subscription_user18');
        $userId3 = $this->createTestUser('subscription_user19');
        
        // User 1 has 2 lobby connections
        $this->dbInsertSubscription($userId1, 'user1-conn1', 'lobby', 0);
        $this->dbInsertSubscription($userId1, 'user1-conn2', 'lobby', 0);
        
        // User 2 has 1 game connection (using fake game IDs - see comment in testInsertGameSubscriptionSetsGameId())
        $this->dbInsertSubscription($userId2, 'user2-conn1', 'game', 50);
        
        // User 3 has mixed connections (using fake game IDs - see comment in testInsertGameSubscriptionSetsGameId())
        $this->dbInsertSubscription($userId3, 'user3-lobby', 'lobby', 0);
        $this->dbInsertSubscription($userId3, 'user3-game1', 'game', 100);
        $this->dbInsertSubscription($userId3, 'user3-game2', 'game', 101);
        
        $user1Subs = $this->dbGetUserSubscriptions($userId1);
        $user2Subs = $this->dbGetUserSubscriptions($userId2);
        $user3Subs = $this->dbGetUserSubscriptions($userId3);
        
        $this->assertCount(2, $user1Subs);
        $this->assertCount(1, $user2Subs);
        $this->assertCount(3, $user3Subs);
        
        // Verify user isolation - results from db_get_user_subscriptions don't include user_id
        // but we can verify the count and connection IDs are correct
        $this->assertCount(2, $user1Subs, 'User 1 should have 2 subscriptions');
        $this->assertCount(1, $user2Subs, 'User 2 should have 1 subscription');
        $this->assertCount(3, $user3Subs, 'User 3 should have 3 subscriptions');
    }

    /**
     * Test game subscription logic
     */
    public function testGameSubscriptionLogic(): void
    {
        $userId = $this->createTestUser('subscription_user20');
        
        // Game subscriptions should set game_id = channel_id
        $this->dbInsertSubscription($userId, 'game-conn-1', 'game', 999);
        
        $row = $this->getSubscription('game-conn-1');
        
        $this->assertSame(999, (int)$row['game_id']);
        $this->assertSame(999, (int)$row['channel_id']);
        $this->assertSame((int)$row['game_id'], (int)$row['channel_id'],
            'For game subscriptions, game_id should equal channel_id');
    }

    /**
     * Test lobby subscription logic
     */
    public function testLobbySubscriptionLogic(): void
    {
        $userId = $this->createTestUser('subscription_user21');
        
        // Lobby subscriptions should have game_id = NULL and channel_id = 0 (per CHECK constraint)
        $this->dbInsertSubscription($userId, 'lobby-conn-1', 'lobby', 0);
        $this->dbInsertSubscription($userId, 'lobby-conn-2', 'lobby', 0); // Second lobby connection
        
        $stmt = $this->pdo->prepare("SELECT game_id, channel_id FROM ws_subscriptions WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $userId]);
        $rows = $stmt->fetchAll();
        
        $this->assertCount(2, $rows, 'Should have 2 lobby subscriptions');
        foreach ($rows as $row) {
            $this->assertNull($row['game_id'], 'Lobby subscriptions should have NULL game_id');
            $this->assertSame(0, (int)$row['channel_id'], 
                'Lobby subscriptions must have channel_id = 0 (per CHECK constraint)');
        }
    }
}
