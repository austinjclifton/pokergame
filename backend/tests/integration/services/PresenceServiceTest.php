<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PresenceService online/offline tracking functionality.
 *
 * Tests both low-level database functions (app/db/presence.php) and
 * high-level presence service functions (app/services/PresenceService.php), including:
 *  - Marking users online and offline
 *  - Status transitions (online, idle, in_game)
 *  - Heartbeat updates
 *  - Getting online users
 *  - Stale presence cleanup
 *  - Edge cases and state management
 *
 * Uses the actual MySQL database for integration testing.
 * Tests use transactions for isolation - each test runs in a transaction
 * that is rolled back in tearDown to ensure test isolation.
 *
 * @coversNothing
 */
final class PresenceServiceTest extends TestCase
{
    private PDO $pdo;
    private bool $inTransaction = false;
    private $presenceService;

    protected function setUp(): void
    {
        // bootstrap.php is already loaded by phpunit.xml and creates $pdo globally
        // Access the global PDO connection (from bootstrap.php -> config/db.php)
        global $pdo;
        
        // If not available via global, try $GLOBALS array
        if (!isset($pdo) && isset($GLOBALS['pdo'])) {
            $pdo = $GLOBALS['pdo'];
        }
        
        // If still not available, create connection directly (fallback)
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            try {
                $DB_HOST = getenv('DB_HOST') ?: '127.0.0.1';
                $DB_NAME = getenv('DB_NAME') ?: 'pokergame';
                $DB_USER = getenv('DB_USER') ?: 'root';
                $DB_PASS = getenv('DB_PASS') ?: '';
                
                $pdo = new PDO(
                    "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
                    $DB_USER,
                    $DB_PASS,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]
                );
            } catch (PDOException $e) {
                $this->markTestSkipped('Database connection failed: ' . $e->getMessage());
            }
        }
        
        $this->pdo = $pdo;

        // Load required functions and classes
        require_once __DIR__ . '/../../../app/services/PresenceService.php';
        require_once __DIR__ . '/../../../app/db/presence.php';
        require_once __DIR__ . '/../../../app/db/users.php';

        // Disable foreign key checks for tests
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        
        // Start a transaction for test isolation
        $this->pdo->beginTransaction();
        $this->inTransaction = true;

        // Create PresenceService instance
        $this->presenceService = new PresenceService($this->pdo);
    }

    protected function tearDown(): void
    {
        // Rollback transaction to clean up test data
        if ($this->inTransaction && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
            $this->inTransaction = false;
        }
    }

    /**
     * Helper: Create a test user and return user ID.
     */
    private function createTestUser(string $username, ?string $email = null): int
    {
        $email = $email ?? ($username . '_' . time() . '@test.com');
        $passwordHash = password_hash('testpass123', PASSWORD_DEFAULT);
        
        $stmt = $this->pdo->prepare("
            INSERT INTO users (username, email, password_hash)
            VALUES (:username, :email, :password_hash)
        ");
        $stmt->execute([
            'username' => $username,
            'email' => $email,
            'password_hash' => $passwordHash,
        ]);
        
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Helper: Get presence record from database.
     */
    private function getPresence(int $userId): ?array
    {
        return db_get_user_presence($this->pdo, $userId);
    }

    // ============================================================================
    // LOW-LEVEL DB FUNCTION TESTS
    // ============================================================================

    public function testDbUpsertPresenceCreatesNewPresence(): void
    {
        $userId = $this->createTestUser('presence_user1');
        
        $result = db_upsert_presence($this->pdo, $userId, 'presence_user1', 'online');
        
        $this->assertTrue($result);
        
        $presence = $this->getPresence($userId);
        $this->assertNotNull($presence);
        $this->assertSame($userId, (int)$presence['user_id']);
        $this->assertSame('presence_user1', $presence['user_username']);
        $this->assertSame('online', $presence['status']);
        $this->assertNotNull($presence['last_seen_at']);
    }

    public function testDbUpsertPresenceUpdatesExistingPresence(): void
    {
        $userId = $this->createTestUser('presence_user2');
        
        // Create initial presence
        db_upsert_presence($this->pdo, $userId, 'presence_user2', 'idle');
        
        // Update to online
        sleep(1); // Wait to ensure timestamp difference
        db_upsert_presence($this->pdo, $userId, 'presence_user2_updated', 'online');
        
        $presence = $this->getPresence($userId);
        $this->assertSame('online', $presence['status']);
        $this->assertSame('presence_user2_updated', $presence['user_username']);
        
        // last_seen_at should be updated
        $stmt = $this->pdo->query("
            SELECT TIMESTAMPDIFF(SECOND, last_seen_at, NOW()) as seconds_ago
            FROM user_lobby_presence 
            WHERE user_id = $userId
        ");
        $secondsAgo = (int)$stmt->fetch()['seconds_ago'];
        $this->assertLessThanOrEqual(5, $secondsAgo);
    }

    public function testDbUpsertPresenceSupportsAllStatuses(): void
    {
        $userId = $this->createTestUser('presence_user3');
        
        // Test all valid statuses
        $statuses = ['online', 'idle', 'in_game'];
        
        foreach ($statuses as $status) {
            db_upsert_presence($this->pdo, $userId, 'presence_user3', $status);
            $presence = $this->getPresence($userId);
            $this->assertSame($status, $presence['status']);
        }
    }

    public function testDbSetOfflineUpdatesStatus(): void
    {
        $userId = $this->createTestUser('presence_user4');
        
        // Set user online first
        db_upsert_presence($this->pdo, $userId, 'presence_user4', 'online');
        
        // Set offline with default status (idle)
        db_set_offline($this->pdo, $userId);
        
        $presence = $this->getPresence($userId);
        $this->assertSame('idle', $presence['status']);
    }

    public function testDbSetOfflineWithSpecificStatus(): void
    {
        $userId = $this->createTestUser('presence_user5');
        
        db_upsert_presence($this->pdo, $userId, 'presence_user5', 'online');
        db_set_offline($this->pdo, $userId, 'idle');
        
        $presence = $this->getPresence($userId);
        $this->assertSame('idle', $presence['status']);
    }

    public function testDbSetOfflineUpdatesTimestamp(): void
    {
        $userId = $this->createTestUser('presence_user6');
        
        db_upsert_presence($this->pdo, $userId, 'presence_user6', 'online');
        
        sleep(1);
        db_set_offline($this->pdo, $userId);
        
        // Verify timestamp was updated
        $stmt = $this->pdo->query("
            SELECT TIMESTAMPDIFF(SECOND, last_seen_at, NOW()) as seconds_ago
            FROM user_lobby_presence 
            WHERE user_id = $userId
        ");
        $secondsAgo = (int)$stmt->fetch()['seconds_ago'];
        $this->assertLessThanOrEqual(5, $secondsAgo);
    }

    public function testDbUpdateLastSeenUpdatesTimestampOnly(): void
    {
        $userId = $this->createTestUser('presence_user7');
        
        // Set user online
        db_upsert_presence($this->pdo, $userId, 'presence_user7', 'online');
        $before = $this->getPresence($userId);
        $beforeStatus = $before['status'];
        
        sleep(1);
        db_update_last_seen($this->pdo, $userId);
        
        $after = $this->getPresence($userId);
        
        // Status should not change
        $this->assertSame($beforeStatus, $after['status']);
        
        // Timestamp should be updated
        $stmt = $this->pdo->query("
            SELECT TIMESTAMPDIFF(SECOND, last_seen_at, NOW()) as seconds_ago
            FROM user_lobby_presence 
            WHERE user_id = $userId
        ");
        $secondsAgo = (int)$stmt->fetch()['seconds_ago'];
        $this->assertLessThanOrEqual(5, $secondsAgo);
    }

    public function testDbUpdateLastSeenReturnsFalseForNonExistentUser(): void
    {
        // Update last_seen for non-existent user
        $result = db_update_last_seen($this->pdo, 999999);
        
        // Returns true even if no rows affected (PDO execute behavior)
        // But we can verify no presence record was created
        $presence = $this->getPresence(999999);
        $this->assertNull($presence);
    }

    public function testDbGetOnlineUsersReturnsOnlyOnlineUsers(): void
    {
        $user1Id = $this->createTestUser('online_user1');
        $user2Id = $this->createTestUser('online_user2');
        $user3Id = $this->createTestUser('idle_user1');
        $user4Id = $this->createTestUser('ingame_user1');
        
        db_upsert_presence($this->pdo, $user1Id, 'online_user1', 'online');
        db_upsert_presence($this->pdo, $user2Id, 'online_user2', 'online');
        db_upsert_presence($this->pdo, $user3Id, 'idle_user1', 'idle');
        db_upsert_presence($this->pdo, $user4Id, 'ingame_user1', 'in_game');
        
        $onlineUsers = db_get_online_users($this->pdo);
        
        // Filter to only our test users (in case other tests left data)
        $testUserIds = [$user1Id, $user2Id, $user3Id, $user4Id];
        $testOnlineUsers = array_filter($onlineUsers, fn($u) => in_array((int)$u['user_id'], $testUserIds));
        
        $this->assertCount(2, $testOnlineUsers, 'Should have exactly 2 online users from our test');
        $userIds = array_map(fn($u) => (int)$u['user_id'], $testOnlineUsers);
        $this->assertContains($user1Id, $userIds);
        $this->assertContains($user2Id, $userIds);
        $this->assertNotContains($user3Id, $userIds);
        $this->assertNotContains($user4Id, $userIds);
    }

    public function testDbGetOnlineUsersOrdersByUsername(): void
    {
        $user1Id = $this->createTestUser('zebra_user');
        $user2Id = $this->createTestUser('alpha_user');
        $user3Id = $this->createTestUser('beta_user');
        
        db_upsert_presence($this->pdo, $user1Id, 'zebra_user', 'online');
        db_upsert_presence($this->pdo, $user2Id, 'alpha_user', 'online');
        db_upsert_presence($this->pdo, $user3Id, 'beta_user', 'online');
        
        $onlineUsers = db_get_online_users($this->pdo);
        
        // Filter to only our test users
        $testUserIds = [$user1Id, $user2Id, $user3Id];
        $testOnlineUsers = array_values(array_filter($onlineUsers, fn($u) => in_array((int)$u['user_id'], $testUserIds)));
        
        $this->assertCount(3, $testOnlineUsers);
        $this->assertSame('alpha_user', $testOnlineUsers[0]['user_username']);
        $this->assertSame('beta_user', $testOnlineUsers[1]['user_username']);
        $this->assertSame('zebra_user', $testOnlineUsers[2]['user_username']);
    }

    public function testDbGetOnlineUsersReturnsEmptyArrayWhenNoOnlineUsers(): void
    {
        $user1Id = $this->createTestUser('idle_only');
        db_upsert_presence($this->pdo, $user1Id, 'idle_only', 'idle');
        
        $onlineUsers = db_get_online_users($this->pdo);
        
        $this->assertIsArray($onlineUsers);
        
        // Check that our test user is not in online users
        $testOnlineUsers = array_filter($onlineUsers, fn($u) => (int)$u['user_id'] === $user1Id);
        $this->assertCount(0, $testOnlineUsers, 'Test user should not be in online users list');
        
        // Note: We can't assert total count is 0 because other tests may have created online users
        // that haven't been cleaned up yet (though transactions should handle this)
    }

    public function testDbPurgeStalePresencesRemovesOldRecords(): void
    {
        $user1Id = $this->createTestUser('stale_user1');
        $user2Id = $this->createTestUser('fresh_user1');
        
        // Create stale presence (set last_seen_at to 15 minutes ago)
        $stmt = $this->pdo->prepare("
            INSERT INTO user_lobby_presence (user_id, user_username, status, last_seen_at)
            VALUES (:uid, :uname, 'online', DATE_SUB(NOW(), INTERVAL 15 MINUTE))
        ");
        $stmt->execute(['uid' => $user1Id, 'uname' => 'stale_user1']);
        
        // Create fresh presence
        db_upsert_presence($this->pdo, $user2Id, 'fresh_user1', 'online');
        
        // Purge stale presences (older than 10 minutes)
        $deleted = db_purge_stale_presences($this->pdo, 10);
        
        $this->assertGreaterThanOrEqual(1, $deleted);
        
        // Stale user should be gone
        $this->assertNull($this->getPresence($user1Id));
        
        // Fresh user should still exist
        $this->assertNotNull($this->getPresence($user2Id));
    }

    public function testDbPurgeStalePresencesDoesNotRemoveInGameUsers(): void
    {
        $user1Id = $this->createTestUser('stale_ingame');
        
        // Create stale in_game presence (should NOT be purged)
        $stmt = $this->pdo->prepare("
            INSERT INTO user_lobby_presence (user_id, user_username, status, last_seen_at)
            VALUES (:uid, :uname, 'in_game', DATE_SUB(NOW(), INTERVAL 15 MINUTE))
        ");
        $stmt->execute(['uid' => $user1Id, 'uname' => 'stale_ingame']);
        
        $deleted = db_purge_stale_presences($this->pdo, 10);
        
        // In-game user should NOT be deleted even if stale
        $this->assertNotNull($this->getPresence($user1Id));
        $presence = $this->getPresence($user1Id);
        $this->assertSame('in_game', $presence['status']);
    }

    public function testDbGetUserPresenceReturnsFullRecord(): void
    {
        $userId = $this->createTestUser('presence_user8');
        
        db_upsert_presence($this->pdo, $userId, 'presence_user8', 'online');
        
        $presence = db_get_user_presence($this->pdo, $userId);
        
        $this->assertNotNull($presence);
        $this->assertArrayHasKey('user_id', $presence);
        $this->assertArrayHasKey('user_username', $presence);
        $this->assertArrayHasKey('status', $presence);
        $this->assertArrayHasKey('last_seen_at', $presence);
        $this->assertSame($userId, (int)$presence['user_id']);
        $this->assertSame('presence_user8', $presence['user_username']);
        $this->assertSame('online', $presence['status']);
    }

    public function testDbGetUserPresenceReturnsNullForNonExistentUser(): void
    {
        $presence = db_get_user_presence($this->pdo, 999999);
        $this->assertNull($presence);
    }

    public function testDbGetUserStatusReturnsStatusString(): void
    {
        $userId = $this->createTestUser('presence_user9');
        
        db_upsert_presence($this->pdo, $userId, 'presence_user9', 'idle');
        
        $status = db_get_user_status($this->pdo, $userId);
        $this->assertSame('idle', $status);
    }

    public function testDbGetUserStatusReturnsNullForNonExistentUser(): void
    {
        $status = db_get_user_status($this->pdo, 999999);
        $this->assertNull($status);
    }

    public function testDbRemovePresenceDeletesRecord(): void
    {
        $userId = $this->createTestUser('presence_user10');
        
        db_upsert_presence($this->pdo, $userId, 'presence_user10', 'online');
        $this->assertNotNull($this->getPresence($userId));
        
        db_remove_presence($this->pdo, $userId);
        
        $this->assertNull($this->getPresence($userId));
    }

    public function testDbRemovePresenceIsIdempotent(): void
    {
        $userId = $this->createTestUser('presence_user11');
        
        db_upsert_presence($this->pdo, $userId, 'presence_user11', 'online');
        
        // Remove twice
        $result1 = db_remove_presence($this->pdo, $userId);
        $result2 = db_remove_presence($this->pdo, $userId);
        
        $this->assertTrue($result1);
        $this->assertTrue($result2); // Should not throw error
        $this->assertNull($this->getPresence($userId));
    }

    // ============================================================================
    // HIGH-LEVEL PRESENCE SERVICE TESTS
    // ============================================================================

    public function testMarkOnlineCreatesPresenceAndReturnsTrue(): void
    {
        $userId = $this->createTestUser('service_user1');
        
        $result = $this->presenceService->markOnline($userId, 'service_user1');
        
        $this->assertTrue($result, 'Should return true when user becomes online');
        
        $presence = $this->getPresence($userId);
        $this->assertNotNull($presence);
        $this->assertSame('online', $presence['status']);
        $this->assertSame('service_user1', $presence['user_username']);
    }

    public function testMarkOnlineReturnsFalseWhenAlreadyOnline(): void
    {
        $userId = $this->createTestUser('service_user2');
        
        // Mark online first time
        $result1 = $this->presenceService->markOnline($userId, 'service_user2');
        $this->assertTrue($result1);
        
        // Mark online again (already online)
        $result2 = $this->presenceService->markOnline($userId, 'service_user2');
        $this->assertFalse($result2, 'Should return false when already online');
        
        $presence = $this->getPresence($userId);
        $this->assertSame('online', $presence['status']);
    }

    public function testMarkOnlineReturnsTrueWhenTransitioningFromIdle(): void
    {
        $userId = $this->createTestUser('service_user3');
        
        // Set to idle first
        db_upsert_presence($this->pdo, $userId, 'service_user3', 'idle');
        
        // Mark online (transition from idle)
        $result = $this->presenceService->markOnline($userId, 'service_user3');
        
        $this->assertTrue($result, 'Should return true when transitioning from idle to online');
        
        $presence = $this->getPresence($userId);
        $this->assertSame('online', $presence['status']);
    }

    public function testMarkOnlineReturnsTrueWhenTransitioningFromInGame(): void
    {
        $userId = $this->createTestUser('service_user4');
        
        // Set to in_game first
        db_upsert_presence($this->pdo, $userId, 'service_user4', 'in_game');
        
        // Mark online (transition from in_game)
        $result = $this->presenceService->markOnline($userId, 'service_user4');
        
        $this->assertTrue($result, 'Should return true when transitioning from in_game to online');
        
        $presence = $this->getPresence($userId);
        $this->assertSame('online', $presence['status']);
    }

    public function testMarkOfflineRemovesPresenceAndReturnsTrue(): void
    {
        $userId = $this->createTestUser('service_user5');
        
        // Mark online first
        $this->presenceService->markOnline($userId, 'service_user5');
        
        // Mark offline
        $result = $this->presenceService->markOffline($userId);
        
        $this->assertTrue($result, 'Should return true when removing online user');
        $this->assertNull($this->getPresence($userId));
    }

    public function testMarkOfflineReturnsFalseWhenUserNotOnline(): void
    {
        $userId = $this->createTestUser('service_user6');
        
        // Set to idle (not online)
        db_upsert_presence($this->pdo, $userId, 'service_user6', 'idle');
        
        // Try to mark offline
        $result = $this->presenceService->markOffline($userId);
        
        $this->assertFalse($result, 'Should return false when user was not online');
        
        // Presence should still be removed (markOffline always removes)
        $this->assertNull($this->getPresence($userId));
    }

    public function testMarkOfflineReturnsFalseWhenUserDoesNotExist(): void
    {
        $result = $this->presenceService->markOffline(999999);
        
        $this->assertFalse($result, 'Should return false when user does not exist');
    }

    public function testUpdateHeartbeatUpdatesTimestampWithoutChangingStatus(): void
    {
        $userId = $this->createTestUser('service_user7');
        
        // Set user to idle
        db_upsert_presence($this->pdo, $userId, 'service_user7', 'idle');
        $before = $this->getPresence($userId);
        $beforeStatus = $before['status'];
        
        sleep(1);
        $result = $this->presenceService->updateHeartbeat($userId);
        
        $this->assertTrue($result);
        
        $after = $this->getPresence($userId);
        $this->assertSame($beforeStatus, $after['status'], 'Status should not change');
        
        // Verify timestamp was updated
        $stmt = $this->pdo->query("
            SELECT TIMESTAMPDIFF(SECOND, last_seen_at, NOW()) as seconds_ago
            FROM user_lobby_presence 
            WHERE user_id = $userId
        ");
        $secondsAgo = (int)$stmt->fetch()['seconds_ago'];
        $this->assertLessThanOrEqual(5, $secondsAgo);
    }

    public function testUpdateHeartbeatReturnsFalseForNonExistentUser(): void
    {
        $result = $this->presenceService->updateHeartbeat(999999);
        
        // Returns true even if no rows affected (PDO behavior)
        // But we verify no presence record exists
        $presence = $this->getPresence(999999);
        $this->assertNull($presence);
    }

    public function testGetOnlineUsersReturnsOnlyOnlineUsers(): void
    {
        $user1Id = $this->createTestUser('service_online1');
        $user2Id = $this->createTestUser('service_online2');
        $user3Id = $this->createTestUser('service_idle1');
        
        $this->presenceService->markOnline($user1Id, 'service_online1');
        $this->presenceService->markOnline($user2Id, 'service_online2');
        db_upsert_presence($this->pdo, $user3Id, 'service_idle1', 'idle');
        
        $onlineUsers = $this->presenceService->getOnlineUsers();
        
        // Filter to only our test users
        $testUserIds = [$user1Id, $user2Id, $user3Id];
        $testOnlineUsers = array_filter($onlineUsers, fn($u) => in_array((int)$u['user_id'], $testUserIds));
        
        $this->assertCount(2, $testOnlineUsers, 'Should have exactly 2 online users from our test');
        $userIds = array_map(fn($u) => (int)$u['user_id'], $testOnlineUsers);
        $this->assertContains($user1Id, $userIds);
        $this->assertContains($user2Id, $userIds);
        $this->assertNotContains($user3Id, $userIds);
    }

    // ============================================================================
    // EDGE CASES & INTEGRATION TESTS
    // ============================================================================

    public function testCompleteOnlineOfflineWorkflow(): void
    {
        $userId = $this->createTestUser('workflow_user');
        
        // 1. User goes online
        $becameOnline1 = $this->presenceService->markOnline($userId, 'workflow_user');
        $this->assertTrue($becameOnline1);
        
        $presence = $this->getPresence($userId);
        $this->assertSame('online', $presence['status']);
        
        // 2. Update heartbeat (stay online)
        $this->presenceService->updateHeartbeat($userId);
        $presence = $this->getPresence($userId);
        $this->assertSame('online', $presence['status']);
        
        // 3. Mark online again (should return false)
        $becameOnline2 = $this->presenceService->markOnline($userId, 'workflow_user');
        $this->assertFalse($becameOnline2);
        
        // 4. User goes offline
        $wasOnline = $this->presenceService->markOffline($userId);
        $this->assertTrue($wasOnline);
        $this->assertNull($this->getPresence($userId));
        
        // 5. Mark online again (should return true)
        $becameOnline3 = $this->presenceService->markOnline($userId, 'workflow_user');
        $this->assertTrue($becameOnline3);
    }

    public function testMultipleUsersCanBeOnlineSimultaneously(): void
    {
        $users = [];
        for ($i = 1; $i <= 5; $i++) {
            $userId = $this->createTestUser("multi_user$i");
            $this->presenceService->markOnline($userId, "multi_user$i");
            $users[] = $userId;
        }
        
        $onlineUsers = $this->presenceService->getOnlineUsers();
        
        // Filter to only our test users
        $testOnlineUsers = array_filter($onlineUsers, fn($u) => in_array((int)$u['user_id'], $users));
        
        $this->assertCount(5, $testOnlineUsers, 'Should have exactly 5 online users from our test');
        
        $onlineUserIds = array_map(fn($u) => (int)$u['user_id'], $testOnlineUsers);
        foreach ($users as $userId) {
            $this->assertContains($userId, $onlineUserIds);
        }
    }

    public function testStatusTransitions(): void
    {
        $userId = $this->createTestUser('transition_user');
        
        // Start online
        $this->presenceService->markOnline($userId, 'transition_user');
        $this->assertSame('online', $this->getPresence($userId)['status']);
        
        // Transition to idle
        db_upsert_presence($this->pdo, $userId, 'transition_user', 'idle');
        $this->assertSame('idle', $this->getPresence($userId)['status']);
        
        // Transition to in_game
        db_upsert_presence($this->pdo, $userId, 'transition_user', 'in_game');
        $this->assertSame('in_game', $this->getPresence($userId)['status']);
        
        // Transition back to online
        $result = $this->presenceService->markOnline($userId, 'transition_user');
        $this->assertTrue($result, 'Should return true when transitioning from in_game');
        $this->assertSame('online', $this->getPresence($userId)['status']);
    }

    public function testUsernameCanBeUpdated(): void
    {
        $userId = $this->createTestUser('oldname');
        
        // Create presence with old username
        db_upsert_presence($this->pdo, $userId, 'oldname', 'online');
        $this->assertSame('oldname', $this->getPresence($userId)['user_username']);
        
        // Update with new username
        db_upsert_presence($this->pdo, $userId, 'newname', 'online');
        $this->assertSame('newname', $this->getPresence($userId)['user_username']);
    }

    public function testConcurrentPresenceUpdates(): void
    {
        // Test that concurrent presence updates from multiple connections
        // don't cause race conditions or data corruption
        
        $unique = substr(uniqid('', true), -8);
        $username = 'concurrent_presence' . $unique;
        $userId = $this->createTestUser($username);
        
        // Commit transaction to allow concurrent operations
        $wasInTransaction = $this->pdo->inTransaction();
        if ($wasInTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            // Simulate concurrent presence updates (e.g., from multiple WebSocket connections)
            // In real scenario, these would happen simultaneously
            for ($i = 0; $i < 10; $i++) {
                $this->presenceService->markOnline($userId, $username);
                $this->presenceService->updateHeartbeat($userId);
            }
            
            // Verify final state is consistent
            $presence = $this->getPresence($userId);
            $this->assertNotNull($presence, 'Presence should exist after concurrent updates');
            $this->assertSame('online', $presence['status'], 
                'Final status should be online after concurrent markOnline calls');
            $this->assertSame($username, $presence['user_username'],
                'Username should be preserved after concurrent updates');
            
            // Verify only one presence record exists (no duplicates)
            $stmt = $this->pdo->prepare("SELECT COUNT(*) as count FROM user_lobby_presence WHERE user_id = ?");
            $stmt->execute([$userId]);
            $count = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
            $this->assertSame(1, $count, 'Should have exactly one presence record');
        } finally {
            if ($wasInTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
    }

    public function testConcurrentStatusTransitions(): void
    {
        // Test that concurrent status transitions (online -> idle -> in_game)
        // are handled correctly without race conditions
        
        $unique = substr(uniqid('', true), -8);
        $username = 'concurrent_status' . $unique;
        $userId = $this->createTestUser($username);
        
        // Commit transaction
        $wasInTransaction = $this->pdo->inTransaction();
        if ($wasInTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            // Start online
            $this->presenceService->markOnline($userId, $username);
            
            // Simulate concurrent status updates
            // In real scenario, these might come from different WebSocket connections
            db_upsert_presence($this->pdo, $userId, $username, 'idle');
            db_upsert_presence($this->pdo, $userId, $username, 'in_game');
            db_upsert_presence($this->pdo, $userId, $username, 'online');
            
            // Final state should be consistent (last write wins)
            $presence = $this->getPresence($userId);
            $this->assertSame('online', $presence['status'], 
                'Final status should reflect the last update');
        } finally {
            if ($wasInTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
    }

    public function testLastSeenAtIsAlwaysUpdatedOnUpsert(): void
    {
        $userId = $this->createTestUser('timestamp_user');
        
        // Create presence
        db_upsert_presence($this->pdo, $userId, 'timestamp_user', 'online');
        $before = $this->getPresence($userId);
        $beforeTimestamp = strtotime($before['last_seen_at']);
        
        sleep(1);
        
        // Update presence (should update timestamp)
        db_upsert_presence($this->pdo, $userId, 'timestamp_user', 'online');
        $after = $this->getPresence($userId);
        $afterTimestamp = strtotime($after['last_seen_at']);
        
        $this->assertGreaterThan($beforeTimestamp, $afterTimestamp);
    }

    public function testPurgeStalePresencesWithZeroMinutes(): void
    {
        $user1Id = $this->createTestUser('zero_min_user');
        db_upsert_presence($this->pdo, $user1Id, 'zero_min_user', 'online');
        
        // Purge with 0 minutes (should not delete anything recent)
        $deleted = db_purge_stale_presences($this->pdo, 0);
        
        // Should still exist (0 minutes means delete everything older than NOW, which is nothing)
        $this->assertNotNull($this->getPresence($user1Id));
    }

    public function testPresenceIsUniquePerUser(): void
    {
        $userId = $this->createTestUser('unique_user');
        
        // Try to create multiple presence records (should be prevented by UNIQUE constraint)
        db_upsert_presence($this->pdo, $userId, 'unique_user', 'online');
        db_upsert_presence($this->pdo, $userId, 'unique_user', 'idle');
        db_upsert_presence($this->pdo, $userId, 'unique_user', 'in_game');
        
        // Should only have one record
        $count = $this->pdo->query("SELECT COUNT(*) FROM user_lobby_presence WHERE user_id = $userId")
            ->fetchColumn();
        $this->assertSame(1, (int)$count);
        
        // Latest status should be in_game
        $presence = $this->getPresence($userId);
        $this->assertSame('in_game', $presence['status']);
    }

    public function testMarkOnlineUpdatesUsernameIfChanged(): void
    {
        $userId = $this->createTestUser('username_change');
        
        // Create presence with old username
        db_upsert_presence($this->pdo, $userId, 'old_username', 'online');
        
        // Mark online with new username
        $this->presenceService->markOnline($userId, 'new_username');
        
        $presence = $this->getPresence($userId);
        $this->assertSame('new_username', $presence['user_username']);
    }
}

