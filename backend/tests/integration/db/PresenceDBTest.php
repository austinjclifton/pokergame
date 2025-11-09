<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseDBIntegrationTest.php';

/**
 * Integration tests for app/db/presence.php
 *
 * Comprehensive test suite for presence database functions.
 * Tests all CRUD operations, edge cases, and business logic.
 *
 * Uses the actual MySQL database connection from bootstrap.php.
 * Tests use transactions for isolation - each test runs in a transaction
 * that is rolled back in tearDown to ensure test isolation.
 *
 * @coversNothing
 */
final class PresenceDBTest extends BaseDBIntegrationTest
{
    /**
     * Load database functions required for presence tests
     */
    protected function loadDatabaseFunctions(): void
    {
        require_once __DIR__ . '/../../../app/db/presence.php';
    }

    /**
     * Set up test environment with presence-specific cleanup
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Clean up any existing presence data for better isolation
        // This is safe because we're in a transaction that will be rolled back
        $this->pdo->exec("DELETE FROM user_lobby_presence");
    }

    /**
     * Helper: Set last_seen_at to a specific time ago using prepared statement
     * 
     * @param int $userId User ID
     * @param int $minutesAgo Minutes ago to set the timestamp
     * @return void
     */
    private function setLastSeenMinutesAgo(int $userId, int $minutesAgo): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE user_lobby_presence 
            SET last_seen_at = DATE_SUB(NOW(), INTERVAL :minutes MINUTE)
            WHERE user_id = :user_id
        ");
        $stmt->execute([
            'minutes' => $minutesAgo,
            'user_id' => $userId,
        ]);
    }

    /**
     * Helper: Assert presence record has correct structure and values
     * 
     * Validates both schema integrity (field presence, types) and data correctness (values).
     * 
     * @param array|null $presence Presence record
     * @param int $expectedUserId Expected user ID
     * @param string $expectedUsername Expected username
     * @param string $expectedStatus Expected status
     * @param bool $checkTimestamp Whether to verify timestamp is recent
     * @return void
     */
    private function assertPresenceRecord(
        ?array $presence,
        int $expectedUserId,
        string $expectedUsername,
        string $expectedStatus,
        bool $checkTimestamp = true
    ): void {
        $this->assertNotNull($presence, 'Presence record should exist');
        $this->assertIsArray($presence, 'Presence should be an array');
        
        // Schema validation: ensure all required fields exist
        $requiredFields = ['user_id', 'user_username', 'status', 'last_seen_at'];
        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $presence, "Presence should have {$field} field");
        }
        
        // Type validation
        $this->assertIsInt($presence['user_id'], 'user_id should be an integer');
        $this->assertIsString($presence['user_username'], 'user_username should be a string');
        $this->assertIsString($presence['status'], 'status should be a string');
        $this->assertIsString($presence['last_seen_at'], 'last_seen_at should be a string');
        
        // Value validation
        $this->assertSame($expectedUserId, (int)$presence['user_id'], 'user_id should match');
        $this->assertSame($expectedUsername, $presence['user_username'], 'user_username should match');
        $this->assertSame($expectedStatus, $presence['status'], 'status should match');
        $this->assertNotNull($presence['last_seen_at'], 'last_seen_at should be set');
        $this->assertNotEmpty($presence['last_seen_at'], 'last_seen_at should not be empty');
        
        // Timestamp validation (if requested)
        if ($checkTimestamp) {
            $this->assertRecentTimestamp('user_lobby_presence', 'last_seen_at', $expectedUserId, 'user_id');
        }
    }

    // ============================================================================
    // UPSERT PRESENCE TESTS
    // ============================================================================

    /**
     * Test that upserting presence creates a new record with all fields set correctly
     */
    public function testUpsertPresenceCreatesNewRecord(): void
    {
        $userId = $this->createTestUser('testuser_presence1');
        
        $result = db_upsert_presence($this->pdo, $userId, 'testuser_presence1', 'online');
        
        $this->assertTrue($result, 'Upsert should return true');
        $this->assertPresenceRecord($this->getPresence($userId), $userId, 'testuser_presence1', 'online');
    }

    /**
     * Test that upserting presence updates existing record and refreshes timestamp
     */
    public function testUpsertPresenceUpdatesExistingRecord(): void
    {
        $userId = $this->createTestUser('testuser_presence2');
        
        // Insert initial presence
        db_upsert_presence($this->pdo, $userId, 'testuser_presence2', 'online');
        
        // Set timestamp to 5 minutes ago to ensure update will change it deterministically
        $this->setLastSeenMinutesAgo($userId, 5);
        $presence1 = $this->getPresence($userId);
        $firstSeen = $presence1['last_seen_at'];
        
        // Update presence with different status (timestamp should be refreshed)
        db_upsert_presence($this->pdo, $userId, 'testuser_presence2_updated', 'in_game');
        
        $presence2 = $this->getPresence($userId);
        $this->assertPresenceRecord($presence2, $userId, 'testuser_presence2_updated', 'in_game');
        $this->assertNotSame($firstSeen, $presence2['last_seen_at'], 'last_seen_at should be updated');
    }

    /**
     * Test that upserting presence uses default status when not provided
     */
    public function testUpsertPresenceWithDefaultStatus(): void
    {
        $userId = $this->createTestUser('testuser_presence3');
        
        // Default status should be 'online'
        db_upsert_presence($this->pdo, $userId, 'testuser_presence3');
        
        $this->assertPresenceRecord($this->getPresence($userId), $userId, 'testuser_presence3', 'online');
    }

    /**
     * Test that upserting presence works with different statuses
     */
    public function testUpsertPresenceWithDifferentStatuses(): void
    {
        $userId = $this->createTestUser('testuser_presence4');
        
        db_upsert_presence($this->pdo, $userId, 'testuser_presence4', 'online');
        $this->assertPresenceRecord($this->getPresence($userId), $userId, 'testuser_presence4', 'online');
        
        db_upsert_presence($this->pdo, $userId, 'testuser_presence4', 'in_game');
        $this->assertPresenceRecord($this->getPresence($userId), $userId, 'testuser_presence4', 'in_game');
        
        db_upsert_presence($this->pdo, $userId, 'testuser_presence4', 'idle');
        $this->assertPresenceRecord($this->getPresence($userId), $userId, 'testuser_presence4', 'idle');
    }

    // ============================================================================
    // SET OFFLINE TESTS
    // ============================================================================

    /**
     * Test that setting offline updates status to idle and refreshes timestamp
     */
    public function testSetOfflineUpdatesStatusToIdle(): void
    {
        $userId = $this->createTestUser('testuser_offline1');
        
        // Create online presence
        db_upsert_presence($this->pdo, $userId, 'testuser_offline1', 'online');
        
        // Set offline (default status is 'idle')
        db_set_offline($this->pdo, $userId);
        
        $this->assertPresenceRecord($this->getPresence($userId), $userId, 'testuser_offline1', 'idle');
    }

    /**
     * Test that setting offline works with explicit status
     */
    public function testSetOfflineWithExplicitStatus(): void
    {
        $userId = $this->createTestUser('testuser_offline2');
        
        db_upsert_presence($this->pdo, $userId, 'testuser_offline2', 'online');
        
        // Set to specific status
        db_set_offline($this->pdo, $userId, 'idle');
        $this->assertPresenceRecord($this->getPresence($userId), $userId, 'testuser_offline2', 'idle', false);
        
        db_set_offline($this->pdo, $userId, 'in_game');
        $this->assertPresenceRecord($this->getPresence($userId), $userId, 'testuser_offline2', 'in_game', false);
    }

    /**
     * Test that setting offline with invalid status defaults to idle
     */
    public function testSetOfflineWithInvalidStatusDefaultsToIdle(): void
    {
        $userId = $this->createTestUser('testuser_offline3');
        
        db_upsert_presence($this->pdo, $userId, 'testuser_offline3', 'online');
        
        // Invalid status should default to 'idle'
        db_set_offline($this->pdo, $userId, 'invalid_status');
        
        $presence = $this->getPresence($userId);
        $this->assertNotNull($presence, 'Presence record should still exist');
        $this->assertIsArray($presence);
        $this->assertArrayHasKey('status', $presence);
        $this->assertSame('idle', $presence['status'], 'Invalid status should default to idle');
        $this->assertSame($userId, (int)$presence['user_id']);
    }

    /**
     * Test that setting offline does not create new record when none exists
     */
    public function testSetOfflineDoesNotCreateNewRecord(): void
    {
        $userId = $this->createTestUser('testuser_offline4');
        
        // Set offline without existing presence
        $result = db_set_offline($this->pdo, $userId);
        
        // Should return true but no record should exist
        $this->assertTrue($result);
        $this->assertNull($this->getPresence($userId), 'Should not create new record');
    }

    // ============================================================================
    // UPDATE LAST SEEN TESTS
    // ============================================================================

    /**
     * Test that updating last seen updates timestamp without changing status
     */
    public function testUpdateLastSeenUpdatesTimestamp(): void
    {
        $userId = $this->createTestUser('testuser_updateseen1');
        
        db_upsert_presence($this->pdo, $userId, 'testuser_updateseen1', 'online');
        
        // Set timestamp to 5 minutes ago to ensure update will change it deterministically
        $this->setLastSeenMinutesAgo($userId, 5);
        $presence1 = $this->getPresence($userId);
        $firstSeen = $presence1['last_seen_at'];
        $originalStatus = $presence1['status'];
        
        // Update last seen (timestamp should change, status should not)
        db_update_last_seen($this->pdo, $userId);
        
        $presence2 = $this->getPresence($userId);
        $this->assertPresenceRecord($presence2, $userId, 'testuser_updateseen1', $originalStatus);
        $this->assertNotSame($firstSeen, $presence2['last_seen_at'], 'last_seen_at should be updated');
    }

    /**
     * Test that updating last seen does not create new record when none exists
     */
    public function testUpdateLastSeenDoesNotCreateNewRecord(): void
    {
        $userId = $this->createTestUser('testuser_updateseen2');
        
        // Update last seen without existing presence
        $result = db_update_last_seen($this->pdo, $userId);
        
        $this->assertTrue($result);
        $this->assertNull($this->getPresence($userId), 'Should not create new record');
    }

    // ============================================================================
    // GET ONLINE USERS TESTS
    // ============================================================================

    /**
     * Test that getting online users returns only online users
     */
    public function testGetOnlineUsersReturnsOnlyOnlineUsers(): void
    {
        $userId1 = $this->createTestUser('testuser_online1');
        $userId2 = $this->createTestUser('testuser_online2');
        $userId3 = $this->createTestUser('testuser_online3');
        
        db_upsert_presence($this->pdo, $userId1, 'testuser_online1', 'online');
        db_upsert_presence($this->pdo, $userId2, 'testuser_online2', 'in_game');
        db_upsert_presence($this->pdo, $userId3, 'testuser_online3', 'online');
        
        $onlineUsers = db_get_online_users($this->pdo);
        
        $this->assertIsArray($onlineUsers);
        $this->assertCount(2, $onlineUsers, 'Should return only online users');
        $userIds = array_map(fn($u) => (int)$u['user_id'], $onlineUsers);
        $this->assertContains($userId1, $userIds);
        $this->assertContains($userId3, $userIds);
        $this->assertNotContains($userId2, $userIds);
    }

    /**
     * Test that getting online users returns correct fields with proper values
     */
    public function testGetOnlineUsersReturnsCorrectFields(): void
    {
        $userId = $this->createTestUser('testuser_online4');
        db_upsert_presence($this->pdo, $userId, 'testuser_online4', 'online');
        
        $onlineUsers = db_get_online_users($this->pdo);
        $this->assertIsArray($onlineUsers);
        $this->assertCount(1, $onlineUsers);
        
        $user = $onlineUsers[0];
        // Use helper to validate structure and values
        $this->assertPresenceRecord($user, $userId, 'testuser_online4', 'online');
    }

    /**
     * Test that getting online users returns empty array when no online users
     */
    public function testGetOnlineUsersReturnsEmptyArrayWhenNoOnlineUsers(): void
    {
        $onlineUsers = db_get_online_users($this->pdo);
        $this->assertIsArray($onlineUsers);
        $this->assertCount(0, $onlineUsers);
    }

    /**
     * Test that getting online users orders by username
     */
    public function testGetOnlineUsersOrdersByUsername(): void
    {
        $userId1 = $this->createTestUser('zebra_user');
        $userId2 = $this->createTestUser('alpha_user');
        $userId3 = $this->createTestUser('beta_user');
        
        db_upsert_presence($this->pdo, $userId1, 'zebra_user', 'online');
        db_upsert_presence($this->pdo, $userId2, 'alpha_user', 'online');
        db_upsert_presence($this->pdo, $userId3, 'beta_user', 'online');
        
        $onlineUsers = db_get_online_users($this->pdo);
        $this->assertIsArray($onlineUsers);
        $this->assertCount(3, $onlineUsers);
        
        $usernames = array_column($onlineUsers, 'user_username');
        $this->assertSame(['alpha_user', 'beta_user', 'zebra_user'], $usernames, 
            'Should be ordered by username');
    }

    // ============================================================================
    // PURGE STALE PRESENCES TESTS
    // ============================================================================

    /**
     * Test that purging stale presences removes old records but keeps recent ones
     */
    public function testPurgeStalePresencesRemovesOldRecords(): void
    {
        $userId1 = $this->createTestUser('testuser_stale1');
        $userId2 = $this->createTestUser('testuser_stale2');
        
        db_upsert_presence($this->pdo, $userId1, 'testuser_stale1', 'online');
        db_upsert_presence($this->pdo, $userId2, 'testuser_stale2', 'idle');
        
        // Set old last_seen_at deterministically (older than 10 minutes)
        $this->setLastSeenMinutesAgo($userId1, 15);
        
        $deleted = db_purge_stale_presences($this->pdo, 10);
        
        $this->assertIsInt($deleted);
        $this->assertGreaterThanOrEqual(1, $deleted, 'Should delete at least one stale record');
        $this->assertNull($this->getPresence($userId1), 'Stale presence should be deleted');
        $this->assertPresenceRecord($this->getPresence($userId2), $userId2, 'testuser_stale2', 'idle', false);
    }

    /**
     * Test that purging stale presences does not remove in_game users
     */
    public function testPurgeStalePresencesDoesNotRemoveInGameUsers(): void
    {
        $userId = $this->createTestUser('testuser_stale3');
        
        db_upsert_presence($this->pdo, $userId, 'testuser_stale3', 'in_game');
        
        // Set old last_seen_at deterministically
        $this->setLastSeenMinutesAgo($userId, 15);
        
        $deleted = db_purge_stale_presences($this->pdo, 10);
        
        $this->assertIsInt($deleted);
        $this->assertSame(0, $deleted, 'Should not delete in_game users');
        $this->assertPresenceRecord($this->getPresence($userId), $userId, 'testuser_stale3', 'in_game', false);
    }

    /**
     * Test that purging stale presences works with custom minutes threshold
     */
    public function testPurgeStalePresencesWithCustomMinutes(): void
    {
        $userId = $this->createTestUser('testuser_stale4');
        
        db_upsert_presence($this->pdo, $userId, 'testuser_stale4', 'online');
        
        // Set last_seen_at to 5 minutes ago deterministically
        $this->setLastSeenMinutesAgo($userId, 5);
        
        // Purge with 10 minute threshold (should not delete)
        $deleted = db_purge_stale_presences($this->pdo, 10);
        $this->assertIsInt($deleted);
        $this->assertSame(0, $deleted);
        $presence = $this->getPresence($userId);
        $this->assertNotNull($presence);
        $this->assertSame($userId, (int)$presence['user_id']);
        
        // Purge with 3 minute threshold (should delete)
        $deleted = db_purge_stale_presences($this->pdo, 3);
        $this->assertIsInt($deleted);
        $this->assertGreaterThanOrEqual(1, $deleted);
        $this->assertNull($this->getPresence($userId));
    }

    // ============================================================================
    // SET USER ONLINE TESTS
    // ============================================================================

    /**
     * Test that setting user online creates online presence with all fields
     */
    public function testSetUserOnlineCreatesOnlinePresence(): void
    {
        $userId = $this->createTestUser('testuser_setonline1');
        
        $result = db_set_user_online($this->pdo, $userId, 'testuser_setonline1');
        
        $this->assertTrue($result);
        $this->assertPresenceRecord($this->getPresence($userId), $userId, 'testuser_setonline1', 'online');
    }

    /**
     * Test that setting user online updates existing presence status
     */
    public function testSetUserOnlineUpdatesExistingPresence(): void
    {
        $userId = $this->createTestUser('testuser_setonline2');
        
        db_upsert_presence($this->pdo, $userId, 'testuser_setonline2', 'idle');
        $presenceBefore = $this->getPresence($userId);
        $this->assertSame('idle', $presenceBefore['status']);
        
        db_set_user_online($this->pdo, $userId, 'testuser_setonline2');
        $this->assertPresenceRecord($this->getPresence($userId), $userId, 'testuser_setonline2', 'online', false);
    }

    // ============================================================================
    // REMOVE PRESENCE TESTS
    // ============================================================================

    /**
     * Test that removing presence deletes record completely
     */
    public function testRemovePresenceDeletesRecord(): void
    {
        $userId = $this->createTestUser('testuser_remove1');
        
        db_upsert_presence($this->pdo, $userId, 'testuser_remove1', 'online');
        $this->assertNotNull($this->getPresence($userId), 'Presence should exist before removal');
        
        $result = db_remove_presence($this->pdo, $userId);
        
        $this->assertTrue($result);
        $this->assertNull($this->getPresence($userId), 'Presence should be deleted');
    }

    /**
     * Test that removing presence with non-existent user completes without error
     */
    public function testRemovePresenceWithNonExistentUser(): void
    {
        $result = db_remove_presence($this->pdo, 999999);
        
        $this->assertTrue($result, 'Should return true even if no record exists');
    }

    /**
     * Test that removing presence by session works correctly
     */
    public function testRemovePresenceBySession(): void
    {
        require_once __DIR__ . '/../../../app/db/sessions.php';
        
        $userId = $this->createTestUser('testuser_remove2');
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
        $sessionId = db_insert_session($this->pdo, $userId, 'test-ip', 'test-agent', $expiresAt);
        
        db_upsert_presence($this->pdo, $userId, 'testuser_remove2', 'online');
        $this->assertNotNull($this->getPresence($userId), 'Presence should exist before removal');
        
        $result = db_remove_presence_by_session($this->pdo, $sessionId);
        
        $this->assertTrue($result);
        $this->assertNull($this->getPresence($userId), 'Presence should be deleted');
    }

    /**
     * Test that removing presence by session with non-existent session returns false
     */
    public function testRemovePresenceBySessionWithNonExistentSession(): void
    {
        $result = db_remove_presence_by_session($this->pdo, 999999);
        
        $this->assertFalse($result, 'Should return false for non-existent session');
    }

    // ============================================================================
    // GET USER STATUS TESTS
    // ============================================================================

    /**
     * Test that getting user status returns current status
     */
    public function testGetUserStatusReturnsCurrentStatus(): void
    {
        $userId = $this->createTestUser('testuser_status1');
        
        db_upsert_presence($this->pdo, $userId, 'testuser_status1', 'online');
        $this->assertSame('online', db_get_user_status($this->pdo, $userId));
        $this->assertIsString(db_get_user_status($this->pdo, $userId));
        
        db_upsert_presence($this->pdo, $userId, 'testuser_status1', 'in_game');
        $this->assertSame('in_game', db_get_user_status($this->pdo, $userId));
        
        db_upsert_presence($this->pdo, $userId, 'testuser_status1', 'idle');
        $this->assertSame('idle', db_get_user_status($this->pdo, $userId));
    }

    /**
     * Test that getting user status returns null for non-existent user
     */
    public function testGetUserStatusReturnsNullForNonExistentUser(): void
    {
        $status = db_get_user_status($this->pdo, 999999);
        $this->assertNull($status, 'Should return null for non-existent user');
    }

    // ============================================================================
    // GET USERNAME TESTS (utility function)
    // ============================================================================

    /**
     * Test that getting username returns correct username
     */
    public function testGetUsernameReturnsUsername(): void
    {
        $userId = $this->createTestUser('testuser_getusername1');
        
        $username = db_get_username($this->pdo, $userId);
        $this->assertIsString($username);
        $this->assertSame('testuser_getusername1', $username);
    }

    /**
     * Test that getting username returns null for non-existent user
     */
    public function testGetUsernameReturnsNullForNonExistentUser(): void
    {
        $username = db_get_username($this->pdo, 999999);
        $this->assertNull($username);
    }

    // ============================================================================
    // GET USER PRESENCE TESTS
    // ============================================================================

    /**
     * Test that getting user presence returns full record with all fields
     */
    public function testGetUserPresenceReturnsFullRecord(): void
    {
        $userId = $this->createTestUser('testuser_getpresence1');
        
        db_upsert_presence($this->pdo, $userId, 'testuser_getpresence1', 'online');
        
        $presence = db_get_user_presence($this->pdo, $userId);
        $this->assertPresenceRecord($presence, $userId, 'testuser_getpresence1', 'online');
    }

    /**
     * Test that getting user presence returns null for non-existent user
     */
    public function testGetUserPresenceReturnsNullForNonExistentUser(): void
    {
        $presence = db_get_user_presence($this->pdo, 999999);
        $this->assertNull($presence);
    }

    // ============================================================================
    // EDGE CASES & INTEGRATION TESTS
    // ============================================================================

    /**
     * Test full presence lifecycle from online to removal
     */
    public function testFullPresenceLifecycle(): void
    {
        $userId = $this->createTestUser('testuser_lifecycle');
        
        // 1. Set online
        db_set_user_online($this->pdo, $userId, 'testuser_lifecycle');
        $this->assertPresenceRecord($this->getPresence($userId), $userId, 'testuser_lifecycle', 'online');
        
        // 2. Update last seen (heartbeat) - use deterministic timestamp delta
        $this->setLastSeenMinutesAgo($userId, 5);
        db_update_last_seen($this->pdo, $userId);
        $presence = db_get_user_presence($this->pdo, $userId);
        $this->assertPresenceRecord($presence, $userId, 'testuser_lifecycle', 'online');
        
        // 3. Change to in_game
        db_upsert_presence($this->pdo, $userId, 'testuser_lifecycle', 'in_game');
        $this->assertPresenceRecord($this->getPresence($userId), $userId, 'testuser_lifecycle', 'in_game', false);
        
        // 4. Set offline
        db_set_offline($this->pdo, $userId, 'idle');
        $this->assertPresenceRecord($this->getPresence($userId), $userId, 'testuser_lifecycle', 'idle', false);
        
        // 5. Remove presence
        db_remove_presence($this->pdo, $userId);
        $this->assertNull(db_get_user_status($this->pdo, $userId));
        $this->assertNull($this->getPresence($userId));
    }

    /**
     * Test that multiple users have isolated presence records
     */
    public function testMultipleUsersPresenceIsolation(): void
    {
        $userId1 = $this->createTestUser('user1_multi');
        $userId2 = $this->createTestUser('user2_multi');
        $userId3 = $this->createTestUser('user3_multi');
        
        db_upsert_presence($this->pdo, $userId1, 'user1_multi', 'online');
        db_upsert_presence($this->pdo, $userId2, 'user2_multi', 'in_game');
        db_upsert_presence($this->pdo, $userId3, 'user3_multi', 'idle');
        
        $onlineUsers = db_get_online_users($this->pdo);
        $this->assertIsArray($onlineUsers);
        $this->assertCount(1, $onlineUsers);
        $this->assertSame($userId1, (int)$onlineUsers[0]['user_id']);
        
        // Remove one user should not affect others
        db_remove_presence($this->pdo, $userId1);
        $this->assertNull($this->getPresence($userId1));
        $this->assertSame('in_game', db_get_user_status($this->pdo, $userId2));
        $this->assertSame('idle', db_get_user_status($this->pdo, $userId3));
        
        // Verify remaining presences still exist with correct values
        $this->assertPresenceRecord($this->getPresence($userId2), $userId2, 'user2_multi', 'in_game', false);
        $this->assertPresenceRecord($this->getPresence($userId3), $userId3, 'user3_multi', 'idle', false);
    }

    // ============================================================================
    // CANONICALIZATION TESTS
    // ============================================================================

    /**
     * Test that upserting presence canonicalizes usernames (lowercase and trimmed)
     */
    public function testUpsertPresenceCanonicalizesUsername(): void
    {
        $userId = $this->createTestUser('canonicaltest');
        
        // Test mixed case canonicalization
        $result = db_upsert_presence($this->pdo, $userId, 'TestUser_MixedCase', 'online');
        $this->assertTrue($result, 'Upsert should return true');
        $presence = $this->getPresence($userId);
        $this->assertPresenceRecord($presence, $userId, 'testuser_mixedcase', 'online', false);
        
        // Test whitespace canonicalization
        $result = db_upsert_presence($this->pdo, $userId, '  TestUser  ', 'in_game');
        $this->assertTrue($result, 'Upsert should return true');
        $presence = $this->getPresence($userId);
        $this->assertPresenceRecord($presence, $userId, 'testuser', 'in_game', false);
    }
}
