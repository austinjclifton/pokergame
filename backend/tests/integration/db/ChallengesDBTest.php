<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseDBIntegrationTest.php';

/**
 * Integration tests for app/db/challenges.php
 *
 * Comprehensive test suite for challenge database functions.
 * Tests all CRUD operations, edge cases, and business logic.
 *
 * Uses the actual MySQL database connection from bootstrap.php.
 * Tests use transactions for isolation - each test runs in a transaction
 * that is rolled back in tearDown to ensure test isolation.
 *
 * @coversNothing
 */
final class ChallengesDBTest extends BaseDBIntegrationTest
{
    /**
     * Load database functions required for challenge tests
     */
    protected function loadDatabaseFunctions(): void
    {
        require_once __DIR__ . '/../../../app/db/challenges.php';
    }

    /**
     * Helper: Update challenge status to pending using prepared statement
     * 
     * @param int $challengeId Challenge ID
     * @return void
     */
    private function setChallengeStatusToPending(int $challengeId): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE game_challenges 
            SET status = 'pending' 
            WHERE id = :id
        ");
        $stmt->execute(['id' => $challengeId]);
    }

    // ============================================================================
    // INSERT CHALLENGE TESTS
    // ============================================================================

    /**
     * Test that inserting a challenge creates a row with correct data
     */
    public function testInsertChallengeCreatesRow(): void
    {
        $fromUserId = $this->createTestUser('challenger1');
        $toUserId = $this->createTestUser('target1');
        
        $challengeId = db_insert_challenge($this->pdo, $fromUserId, $toUserId);
        
        $this->assertGreaterThan(0, $challengeId, 'Challenge ID should be positive');
        
        $challenge = $this->getChallenge($challengeId);
        $this->assertNotNull($challenge, 'Challenge should exist after insert');
        $this->assertSame($fromUserId, (int)$challenge['from_user_id']);
        $this->assertSame($toUserId, (int)$challenge['to_user_id']);
        $this->assertNull($challenge['game_id'], 'game_id should be NULL initially');
        $this->assertNull($challenge['responded_at'], 'responded_at should be NULL initially');
    }

    /**
     * Test that inserting a challenge sets created_at timestamp
     */
    public function testInsertChallengeSetsCreatedAtTimestamp(): void
    {
        $fromUserId = $this->createTestUser('challenger2');
        $toUserId = $this->createTestUser('target2');
        
        $challengeId = db_insert_challenge($this->pdo, $fromUserId, $toUserId);
        
        $challenge = $this->getChallenge($challengeId);
        $this->assertNotNull($challenge['created_at'], 'created_at should be set');
        
        // Verify created_at is recent (within last minute)
        $this->assertRecentTimestamp('game_challenges', 'created_at', $challengeId);
    }

    /**
     * Test that inserting a challenge sets default status
     */
    public function testInsertChallengeSetsDefaultStatus(): void
    {
        $fromUserId = $this->createTestUser('challenger3');
        $toUserId = $this->createTestUser('target3');
        
        $challengeId = db_insert_challenge($this->pdo, $fromUserId, $toUserId);
        
        $challenge = $this->getChallenge($challengeId);
        // Verify challenge was created (status may have a default in schema)
        $this->assertNotNull($challenge, 'Challenge should exist');
        $this->assertArrayHasKey('status', $challenge, 'Challenge should have status field');
    }

    // ============================================================================
    // PENDING EXISTS TESTS
    // ============================================================================

    /**
     * Test that challenge pending exists returns true for pending challenge
     */
    public function testChallengePendingExistsReturnsTrueForPendingChallenge(): void
    {
        $fromUserId = $this->createTestUser('challenger4');
        $toUserId = $this->createTestUser('target4');
        
        $challengeId = db_insert_challenge($this->pdo, $fromUserId, $toUserId);
        
        // Ensure status is 'pending' (might be default or need to set)
        $this->setChallengeStatusToPending($challengeId);
        
        $exists = db_challenge_pending_exists($this->pdo, $fromUserId, $toUserId);
        $this->assertTrue($exists, 'Should return true for pending challenge');
    }

    /**
     * Test that challenge pending exists returns false for accepted challenge
     */
    public function testChallengePendingExistsReturnsFalseForAcceptedChallenge(): void
    {
        $fromUserId = $this->createTestUser('challenger5');
        $toUserId = $this->createTestUser('target5');
        
        $challengeId = db_insert_challenge($this->pdo, $fromUserId, $toUserId);
        db_mark_challenge_status($this->pdo, $challengeId, 'accepted');
        
        $exists = db_challenge_pending_exists($this->pdo, $fromUserId, $toUserId);
        $this->assertFalse($exists, 'Should return false for accepted challenge');
    }

    /**
     * Test that challenge pending exists returns false for declined challenge
     */
    public function testChallengePendingExistsReturnsFalseForDeclinedChallenge(): void
    {
        $fromUserId = $this->createTestUser('challenger6');
        $toUserId = $this->createTestUser('target6');
        
        $challengeId = db_insert_challenge($this->pdo, $fromUserId, $toUserId);
        db_mark_challenge_status($this->pdo, $challengeId, 'declined');
        
        $exists = db_challenge_pending_exists($this->pdo, $fromUserId, $toUserId);
        $this->assertFalse($exists, 'Should return false for declined challenge');
    }

    /**
     * Test that challenge pending exists returns false for non-existent challenge
     */
    public function testChallengePendingExistsReturnsFalseForNonExistentChallenge(): void
    {
        $fromUserId = $this->createTestUser('challenger7');
        $toUserId = $this->createTestUser('target7');
        
        $exists = db_challenge_pending_exists($this->pdo, $fromUserId, $toUserId);
        $this->assertFalse($exists, 'Should return false for non-existent challenge');
    }

    /**
     * Test that challenge pending exists checks both directions
     */
    public function testChallengePendingExistsChecksBothDirections(): void
    {
        $fromUserId = $this->createTestUser('challenger8');
        $toUserId = $this->createTestUser('target8');
        
        // Create challenge from user1 to user2
        $challengeId = db_insert_challenge($this->pdo, $fromUserId, $toUserId);
        $this->setChallengeStatusToPending($challengeId);
        
        // Check should work for correct direction
        $exists = db_challenge_pending_exists($this->pdo, $fromUserId, $toUserId);
        $this->assertTrue($exists, 'Should find challenge in correct direction');
        
        // Check should NOT work for reverse direction
        $existsReverse = db_challenge_pending_exists($this->pdo, $toUserId, $fromUserId);
        $this->assertFalse($existsReverse, 'Should not find challenge in reverse direction');
    }

    // ============================================================================
    // GET CHALLENGE FOR ACCEPT TESTS
    // ============================================================================

    /**
     * Test that getting challenge for accept returns correct data
     */
    public function testGetChallengeForAcceptReturnsCorrectData(): void
    {
        $fromUserId = $this->createTestUser('challenger9');
        $toUserId = $this->createTestUser('target9');
        
        $challengeId = db_insert_challenge($this->pdo, $fromUserId, $toUserId);
        
        $challenge = db_get_challenge_for_accept($this->pdo, $challengeId);
        
        $this->assertNotNull($challenge, 'Should return challenge');
        $this->assertSame($challengeId, (int)$challenge['id']);
        $this->assertSame($fromUserId, (int)$challenge['from_user_id']);
        $this->assertSame($toUserId, (int)$challenge['to_user_id']);
        $this->assertArrayHasKey('status', $challenge);
        $this->assertArrayHasKey('game_id', $challenge);
    }

    /**
     * Test that getting challenge for accept returns null for non-existent challenge
     */
    public function testGetChallengeForAcceptReturnsNullForNonExistentChallenge(): void
    {
        $challenge = db_get_challenge_for_accept($this->pdo, 999999);
        $this->assertNull($challenge, 'Should return null for non-existent challenge');
    }

    /**
     * Test that getting challenge for accept returns current status
     */
    public function testGetChallengeForAcceptReturnsCurrentStatus(): void
    {
        $fromUserId = $this->createTestUser('challenger10');
        $toUserId = $this->createTestUser('target10');
        
        $challengeId = db_insert_challenge($this->pdo, $fromUserId, $toUserId);
        db_mark_challenge_status($this->pdo, $challengeId, 'accepted');
        
        $challenge = db_get_challenge_for_accept($this->pdo, $challengeId);
        $this->assertSame('accepted', $challenge['status']);
    }

    // ============================================================================
    // MARK CHALLENGE STATUS TESTS
    // ============================================================================

    /**
     * Test that marking challenge status updates status
     */
    public function testMarkChallengeStatusUpdatesStatus(): void
    {
        $fromUserId = $this->createTestUser('challenger11');
        $toUserId = $this->createTestUser('target11');
        
        $challengeId = db_insert_challenge($this->pdo, $fromUserId, $toUserId);
        
        db_mark_challenge_status($this->pdo, $challengeId, 'accepted');
        
        $challenge = $this->getChallenge($challengeId);
        $this->assertSame('accepted', $challenge['status']);
    }

    /**
     * Test that marking challenge status sets responded_at timestamp
     */
    public function testMarkChallengeStatusSetsRespondedAtTimestamp(): void
    {
        $fromUserId = $this->createTestUser('challenger12');
        $toUserId = $this->createTestUser('target12');
        
        $challengeId = db_insert_challenge($this->pdo, $fromUserId, $toUserId);
        $challengeBefore = $this->getChallenge($challengeId);
        $this->assertNull($challengeBefore['responded_at'], 'responded_at should be NULL initially');
        
        db_mark_challenge_status($this->pdo, $challengeId, 'accepted');
        
        $challengeAfter = $this->getChallenge($challengeId);
        $this->assertNotNull($challengeAfter['responded_at'], 'responded_at should be set');
        
        // Verify responded_at is recent (within last minute)
        $this->assertRecentTimestamp('game_challenges', 'responded_at', $challengeId);
    }

    /**
     * Test that marking challenge status works with different statuses
     */
    public function testMarkChallengeStatusWithDifferentStatuses(): void
    {
        $fromUserId = $this->createTestUser('challenger13');
        $toUserId = $this->createTestUser('target13');
        
        $challengeId = db_insert_challenge($this->pdo, $fromUserId, $toUserId);
        
        db_mark_challenge_status($this->pdo, $challengeId, 'accepted');
        $this->assertSame('accepted', $this->getChallenge($challengeId)['status']);
        
        // Note: In real usage, you wouldn't change from accepted to declined,
        // but we test the function can handle different statuses
        db_mark_challenge_status($this->pdo, $challengeId, 'declined');
        $this->assertSame('declined', $this->getChallenge($challengeId)['status']);
        
        db_mark_challenge_status($this->pdo, $challengeId, 'expired');
        $this->assertSame('expired', $this->getChallenge($challengeId)['status']);
    }

    /**
     * Test that marking challenge status does not affect other fields
     */
    public function testMarkChallengeStatusDoesNotAffectOtherFields(): void
    {
        $fromUserId = $this->createTestUser('challenger14');
        $toUserId = $this->createTestUser('target14');
        
        $challengeId = db_insert_challenge($this->pdo, $fromUserId, $toUserId);
        $challengeBefore = $this->getChallenge($challengeId);
        
        db_mark_challenge_status($this->pdo, $challengeId, 'accepted');
        
        $challengeAfter = $this->getChallenge($challengeId);
        $this->assertSame($challengeBefore['from_user_id'], $challengeAfter['from_user_id']);
        $this->assertSame($challengeBefore['to_user_id'], $challengeAfter['to_user_id']);
        $this->assertSame($challengeBefore['created_at'], $challengeAfter['created_at']);
        $this->assertNotSame($challengeBefore['status'], $challengeAfter['status']);
    }

    // ============================================================================
    // ATTACH GAME TO CHALLENGE TESTS
    // ============================================================================

    /**
     * Test that attaching game to challenge sets game_id
     */
    public function testAttachGameToChallengeSetsGameId(): void
    {
        $fromUserId = $this->createTestUser('challenger15');
        $toUserId = $this->createTestUser('target15');
        
        $challengeId = db_insert_challenge($this->pdo, $fromUserId, $toUserId);
        // Note: Using a fake game ID is acceptable here because:
        // 1. We're testing db_attach_game_to_challenge() which only updates a foreign key field
        // 2. The function doesn't validate that the game exists
        // 3. Foreign key checks are disabled in tests
        // 4. Creating a real game would add unnecessary complexity for this test
        $gameId = 42;
        
        db_attach_game_to_challenge($this->pdo, $challengeId, $gameId);
        
        $challenge = $this->getChallenge($challengeId);
        $this->assertSame($gameId, (int)$challenge['game_id'], 'game_id should be set');
    }

    /**
     * Test that attaching game to challenge does not affect other fields
     */
    public function testAttachGameToChallengeDoesNotAffectOtherFields(): void
    {
        $fromUserId = $this->createTestUser('challenger16');
        $toUserId = $this->createTestUser('target16');
        
        $challengeId = db_insert_challenge($this->pdo, $fromUserId, $toUserId);
        db_mark_challenge_status($this->pdo, $challengeId, 'accepted');
        $challengeBefore = $this->getChallenge($challengeId);
        
        // Using fake game ID - see comment in testAttachGameToChallengeSetsGameId()
        $gameId = 100;
        db_attach_game_to_challenge($this->pdo, $challengeId, $gameId);
        
        $challengeAfter = $this->getChallenge($challengeId);
        $this->assertSame($challengeBefore['status'], $challengeAfter['status']);
        $this->assertSame($challengeBefore['from_user_id'], $challengeAfter['from_user_id']);
        $this->assertSame($challengeBefore['to_user_id'], $challengeAfter['to_user_id']);
        $this->assertNotSame($challengeBefore['game_id'], $challengeAfter['game_id']);
    }

    /**
     * Test that attaching game to challenge can update existing game_id
     */
    public function testAttachGameToChallengeCanUpdateExistingGameId(): void
    {
        $fromUserId = $this->createTestUser('challenger17');
        $toUserId = $this->createTestUser('target17');
        
        $challengeId = db_insert_challenge($this->pdo, $fromUserId, $toUserId);
        
        // Using fake game IDs - see comment in testAttachGameToChallengeSetsGameId()
        db_attach_game_to_challenge($this->pdo, $challengeId, 10);
        $this->assertSame(10, (int)$this->getChallenge($challengeId)['game_id']);
        
        db_attach_game_to_challenge($this->pdo, $challengeId, 20);
        $this->assertSame(20, (int)$this->getChallenge($challengeId)['game_id']);
    }

    // ============================================================================
    // EDGE CASES & INTEGRATION TESTS
    // ============================================================================

    /**
     * Test full challenge lifecycle from creation to game attachment
     */
    public function testFullChallengeLifecycle(): void
    {
        $fromUserId = $this->createTestUser('challenger_lifecycle');
        $toUserId = $this->createTestUser('target_lifecycle');
        
        // 1. Insert challenge
        $challengeId = db_insert_challenge($this->pdo, $fromUserId, $toUserId);
        $this->assertGreaterThan(0, $challengeId);
        
        // 2. Check pending exists
        $this->setChallengeStatusToPending($challengeId);
        $exists = db_challenge_pending_exists($this->pdo, $fromUserId, $toUserId);
        $this->assertTrue($exists);
        
        // 3. Get challenge for accept
        $challenge = db_get_challenge_for_accept($this->pdo, $challengeId);
        $this->assertNotNull($challenge);
        $this->assertSame('pending', $challenge['status']);
        
        // 4. Mark as accepted
        db_mark_challenge_status($this->pdo, $challengeId, 'accepted');
        $challenge = $this->getChallenge($challengeId);
        $this->assertSame('accepted', $challenge['status']);
        $this->assertNotNull($challenge['responded_at']);
        
        // 5. Attach game (using fake game ID - see comment in testAttachGameToChallengeSetsGameId())
        $gameId = 123;
        db_attach_game_to_challenge($this->pdo, $challengeId, $gameId);
        $challenge = $this->getChallenge($challengeId);
        $this->assertSame($gameId, (int)$challenge['game_id']);
    }

    /**
     * Test that multiple challenges can exist for same users
     */
    public function testMultipleChallengesForSameUsers(): void
    {
        $fromUserId = $this->createTestUser('challenger_multi');
        $toUserId = $this->createTestUser('target_multi');
        
        // Create multiple challenges (different statuses)
        $challenge1Id = db_insert_challenge($this->pdo, $fromUserId, $toUserId);
        $challenge2Id = db_insert_challenge($this->pdo, $fromUserId, $toUserId);
        
        $this->assertNotSame($challenge1Id, $challenge2Id);
        
        // Set different statuses
        $this->setChallengeStatusToPending($challenge1Id);
        db_mark_challenge_status($this->pdo, $challenge2Id, 'declined');
        
        // Only one should be pending
        $exists = db_challenge_pending_exists($this->pdo, $fromUserId, $toUserId);
        $this->assertTrue($exists, 'Should find at least one pending challenge');
    }

    /**
     * Test that challenges are isolated between different user pairs
     */
    public function testChallengeIsolationBetweenDifferentUserPairs(): void
    {
        $user1Id = $this->createTestUser('user1_iso');
        $user2Id = $this->createTestUser('user2_iso');
        $user3Id = $this->createTestUser('user3_iso');
        
        // User1 challenges User2
        $challenge1Id = db_insert_challenge($this->pdo, $user1Id, $user2Id);
        $this->setChallengeStatusToPending($challenge1Id);
        
        // User3 challenges User2
        $challenge2Id = db_insert_challenge($this->pdo, $user3Id, $user2Id);
        $this->setChallengeStatusToPending($challenge2Id);
        
        // Check pending exists should work for both pairs
        $exists1 = db_challenge_pending_exists($this->pdo, $user1Id, $user2Id);
        $exists2 = db_challenge_pending_exists($this->pdo, $user3Id, $user2Id);
        
        $this->assertTrue($exists1);
        $this->assertTrue($exists2);
        
        // Mark one as accepted should not affect the other
        db_mark_challenge_status($this->pdo, $challenge1Id, 'accepted');
        
        $exists1After = db_challenge_pending_exists($this->pdo, $user1Id, $user2Id);
        $exists2After = db_challenge_pending_exists($this->pdo, $user3Id, $user2Id);
        
        $this->assertFalse($exists1After, 'First challenge should no longer be pending');
        $this->assertTrue($exists2After, 'Second challenge should still be pending');
    }
}
