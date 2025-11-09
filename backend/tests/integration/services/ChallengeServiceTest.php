<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ChallengeService challenge management functionality.
 *
 * Tests challenge creation, acceptance, and cancellation/decline, including:
 *  - Successful challenge sending
 *  - Challenge acceptance (games functionality not yet implemented)
 *  - Challenge decline/cancellation
 *  - Authorization checks
 *  - Status validation
 *  - Edge cases and security considerations
 *
 * Uses the actual MySQL database for integration testing.
 * Tests use transactions for isolation - each test runs in a transaction
 * that is rolled back in tearDown to ensure test isolation.
 *
 * @coversNothing
 */
final class ChallengeServiceTest extends TestCase
{
    private PDO $pdo;
    private bool $inTransaction = false;
    private $challengeService;

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
        require_once __DIR__ . '/../../../app/services/ChallengeService.php';
        require_once __DIR__ . '/../../../app/db/challenges.php';
        require_once __DIR__ . '/../../../app/db/users.php';
        // Games functionality not yet implemented
        // require_once __DIR__ . '/../../../app/db/games.php';

        // Disable foreign key checks for tests
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        
        // Start a transaction for test isolation
        $this->pdo->beginTransaction();
        $this->inTransaction = true;

        // Create ChallengeService instance
        $this->challengeService = new ChallengeService($this->pdo);
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
     * Helper: Get challenge from database.
     */
    private function getChallenge(int $challengeId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM game_challenges WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $challengeId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // Games functionality not yet implemented
    // /**
    //  * Helper: Get game from database.
    //  */
    // private function getGame(int $gameId): ?array
    // {
    //     $stmt = $this->pdo->prepare("SELECT * FROM games WHERE id = :id LIMIT 1");
    //     $stmt->execute(['id' => $gameId]);
    //     return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    // }

    // ============================================================================
    // CHALLENGE CREATION TESTS
    // ============================================================================

    public function testSendChallengeCreatesPendingChallenge(): void
    {
        $user1Id = $this->createTestUser('challenger1');
        $user2Id = $this->createTestUser('target1');
        
        $result = $this->challengeService->send($user1Id, 'target1');
        
        $this->assertTrue($result['ok'], 'Challenge should be created successfully');
        $this->assertArrayHasKey('challenge_id', $result);
        $this->assertIsInt($result['challenge_id']);
        
        $challenge = $this->getChallenge($result['challenge_id']);
        $this->assertNotNull($challenge);
        $this->assertSame($user1Id, (int)$challenge['from_user_id']);
        $this->assertSame($user2Id, (int)$challenge['to_user_id']);
        $this->assertSame('pending', $challenge['status']);
        $this->assertNull($challenge['game_id']);
        $this->assertNull($challenge['responded_at']);
    }

    public function testSendChallengeReturnsErrorForNonExistentUser(): void
    {
        $user1Id = $this->createTestUser('challenger2');
        
        $result = $this->challengeService->send($user1Id, 'nonexistent_user_12345');
        
        $this->assertFalse($result['ok']);
        $this->assertSame('Target user not found', $result['message']);
    }

    public function testSendChallengeReturnsErrorForSelfChallenge(): void
    {
        $user1Id = $this->createTestUser('selfchallenger');
        
        $result = $this->challengeService->send($user1Id, 'selfchallenger');
        
        $this->assertFalse($result['ok']);
        $this->assertSame('Cannot challenge yourself', $result['message']);
    }

    public function testSendChallengePreventsDuplicatePendingChallenge(): void
    {
        $user1Id = $this->createTestUser('challenger3');
        $user2Id = $this->createTestUser('target2');
        
        // Send first challenge
        $result1 = $this->challengeService->send($user1Id, 'target2');
        $this->assertTrue($result1['ok']);
        
        // Try to send duplicate challenge
        $result2 = $this->challengeService->send($user1Id, 'target2');
        
        $this->assertFalse($result2['ok']);
        $this->assertSame('Challenge already pending', $result2['message']);
    }

    public function testSendChallengeAllowsNewChallengeAfterDecline(): void
    {
        $user1Id = $this->createTestUser('challenger4');
        $user2Id = $this->createTestUser('target3');
        
        // Send and decline first challenge
        $result1 = $this->challengeService->send($user1Id, 'target3');
        $challengeId = $result1['challenge_id'];
        $this->challengeService->decline($challengeId, $user2Id);
        
        // Should be able to send new challenge after decline
        $result2 = $this->challengeService->send($user1Id, 'target3');
        $this->assertTrue($result2['ok']);
        $this->assertNotSame($challengeId, $result2['challenge_id']);
    }

    public function testSendChallengeAllowsMultipleChallengesFromDifferentUsers(): void
    {
        $user1Id = $this->createTestUser('challenger5');
        $user2Id = $this->createTestUser('target4');
        $user3Id = $this->createTestUser('challenger6');
        
        // User 1 challenges user 2
        $result1 = $this->challengeService->send($user1Id, 'target4');
        $this->assertTrue($result1['ok']);
        
        // User 3 can also challenge user 2 (different challenger)
        $result2 = $this->challengeService->send($user3Id, 'target4');
        $this->assertTrue($result2['ok']);
        
        // Both challenges should exist
        $challenge1 = $this->getChallenge($result1['challenge_id']);
        $challenge2 = $this->getChallenge($result2['challenge_id']);
        
        $this->assertNotNull($challenge1);
        $this->assertNotNull($challenge2);
        $this->assertSame($user2Id, (int)$challenge1['to_user_id']);
        $this->assertSame($user2Id, (int)$challenge2['to_user_id']);
    }

    public function testSendChallengeSetsCreatedAtTimestamp(): void
    {
        $user1Id = $this->createTestUser('challenger7');
        $user2Id = $this->createTestUser('target5');
        
        $result = $this->challengeService->send($user1Id, 'target5');
        
        $challenge = $this->getChallenge($result['challenge_id']);
        $this->assertNotNull($challenge['created_at'], 'Challenge should have created_at timestamp');
        
        // Verify created_at is recent (within last minute)
        $stmt = $this->pdo->query("
            SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) as seconds_ago
            FROM game_challenges 
            WHERE id = " . $result['challenge_id']
        );
        $secondsAgo = (int)$stmt->fetch()['seconds_ago'];
        $this->assertGreaterThanOrEqual(0, $secondsAgo);
        $this->assertLessThanOrEqual(60, $secondsAgo);
    }

    // ============================================================================
    // CHALLENGE ACCEPTANCE TESTS
    // ============================================================================

    public function testAcceptChallengeUpdatesStatus(): void
    {
        $user1Id = $this->createTestUser('challenger8');
        $user2Id = $this->createTestUser('target6');
        
        // Create challenge
        $result = $this->challengeService->send($user1Id, 'target6');
        $challengeId = $result['challenge_id'];
        
        // Accept challenge
        $acceptResult = $this->challengeService->accept($challengeId, $user2Id);
        
        $this->assertTrue($acceptResult['ok']);
        
        // Verify challenge status updated
        $challenge = $this->getChallenge($challengeId);
        $this->assertSame('accepted', $challenge['status']);
        $this->assertNotNull($challenge['responded_at']);
        
        // Games functionality not yet implemented
        // When games are implemented, verify game creation here
    }

    public function testAcceptChallengeReturnsErrorForNonExistentChallenge(): void
    {
        $user1Id = $this->createTestUser('challenger9');
        
        $result = $this->challengeService->accept(999999, $user1Id);
        
        $this->assertFalse($result['ok']);
        $this->assertSame('Challenge not found', $result['message']);
    }

    public function testAcceptChallengeReturnsErrorWhenNotAuthorized(): void
    {
        $user1Id = $this->createTestUser('challenger10');
        $user2Id = $this->createTestUser('target7');
        $user3Id = $this->createTestUser('unauthorized');
        
        // User 1 challenges user 2
        $result = $this->challengeService->send($user1Id, 'target7');
        $challengeId = $result['challenge_id'];
        
        // User 3 tries to accept (not authorized)
        $acceptResult = $this->challengeService->accept($challengeId, $user3Id);
        
        $this->assertFalse($acceptResult['ok']);
        $this->assertSame('Not authorized to accept this challenge', $acceptResult['message']);
        
        // Challenge should still be pending
        $challenge = $this->getChallenge($challengeId);
        $this->assertSame('pending', $challenge['status']);
    }

    public function testAcceptChallengeReturnsErrorWhenChallengeAlreadyAccepted(): void
    {
        $user1Id = $this->createTestUser('challenger11');
        $user2Id = $this->createTestUser('target8');
        
        // Create and accept challenge
        $result = $this->challengeService->send($user1Id, 'target8');
        $challengeId = $result['challenge_id'];
        $this->challengeService->accept($challengeId, $user2Id);
        
        // Try to accept again
        $acceptResult = $this->challengeService->accept($challengeId, $user2Id);
        
        $this->assertFalse($acceptResult['ok']);
        $this->assertSame('Challenge is not pending', $acceptResult['message']);
    }

    public function testAcceptChallengeReturnsErrorWhenChallengeAlreadyDeclined(): void
    {
        $user1Id = $this->createTestUser('challenger12');
        $user2Id = $this->createTestUser('target9');
        
        // Create and decline challenge
        $result = $this->challengeService->send($user1Id, 'target9');
        $challengeId = $result['challenge_id'];
        $this->challengeService->decline($challengeId, $user2Id);
        
        // Try to accept declined challenge
        $acceptResult = $this->challengeService->accept($challengeId, $user2Id);
        
        $this->assertFalse($acceptResult['ok']);
        $this->assertSame('Challenge is not pending', $acceptResult['message']);
    }

    public function testAcceptChallengeDoesNotAllowChallengerToAccept(): void
    {
        $user1Id = $this->createTestUser('challenger13');
        $user2Id = $this->createTestUser('target10');
        
        // User 1 challenges user 2
        $result = $this->challengeService->send($user1Id, 'target10');
        $challengeId = $result['challenge_id'];
        
        // User 1 tries to accept their own challenge
        $acceptResult = $this->challengeService->accept($challengeId, $user1Id);
        
        $this->assertFalse($acceptResult['ok']);
        $this->assertSame('Not authorized to accept this challenge', $acceptResult['message']);
    }

    // Games functionality not yet implemented
    // public function testAcceptChallengeCreatesGameWithCorrectPlayers(): void
    // {
    //     // This test will be re-enabled when games functionality is implemented
    // }

    // ============================================================================
    // CHALLENGE DECLINE TESTS
    // ============================================================================

    public function testDeclineChallengeUpdatesStatus(): void
    {
        $user1Id = $this->createTestUser('challenger15');
        $user2Id = $this->createTestUser('target12');
        
        // Create challenge
        $result = $this->challengeService->send($user1Id, 'target12');
        $challengeId = $result['challenge_id'];
        
        // Decline challenge
        $declineResult = $this->challengeService->decline($challengeId, $user2Id);
        
        $this->assertTrue($declineResult['ok']);
        
        // Verify challenge status updated
        $challenge = $this->getChallenge($challengeId);
        $this->assertSame('declined', $challenge['status']);
        $this->assertNotNull($challenge['responded_at']);
        $this->assertNull($challenge['game_id']);
    }

    public function testDeclineChallengeReturnsErrorForNonExistentChallenge(): void
    {
        $user1Id = $this->createTestUser('challenger16');
        
        $result = $this->challengeService->decline(999999, $user1Id);
        
        $this->assertFalse($result['ok']);
        $this->assertSame('Challenge not found', $result['message']);
    }

    public function testDeclineChallengeReturnsErrorWhenNotAuthorized(): void
    {
        $user1Id = $this->createTestUser('challenger17');
        $user2Id = $this->createTestUser('target13');
        $user3Id = $this->createTestUser('unauthorized2');
        
        // User 1 challenges user 2
        $result = $this->challengeService->send($user1Id, 'target13');
        $challengeId = $result['challenge_id'];
        
        // User 3 tries to decline (not authorized)
        $declineResult = $this->challengeService->decline($challengeId, $user3Id);
        
        $this->assertFalse($declineResult['ok']);
        $this->assertSame('Not authorized to decline this challenge', $declineResult['message']);
        
        // Challenge should still be pending
        $challenge = $this->getChallenge($challengeId);
        $this->assertSame('pending', $challenge['status']);
    }

    public function testDeclineChallengeReturnsErrorWhenChallengeAlreadyAccepted(): void
    {
        $user1Id = $this->createTestUser('challenger18');
        $user2Id = $this->createTestUser('target14');
        
        // Create and accept challenge
        $result = $this->challengeService->send($user1Id, 'target14');
        $challengeId = $result['challenge_id'];
        $this->challengeService->accept($challengeId, $user2Id);
        
        // Try to decline accepted challenge
        $declineResult = $this->challengeService->decline($challengeId, $user2Id);
        
        $this->assertFalse($declineResult['ok']);
        $this->assertSame('Challenge is not pending', $declineResult['message']);
    }

    public function testDeclineChallengeReturnsErrorWhenChallengeAlreadyDeclined(): void
    {
        $user1Id = $this->createTestUser('challenger19');
        $user2Id = $this->createTestUser('target15');
        
        // Create and decline challenge
        $result = $this->challengeService->send($user1Id, 'target15');
        $challengeId = $result['challenge_id'];
        $this->challengeService->decline($challengeId, $user2Id);
        
        // Try to decline again
        $declineResult = $this->challengeService->decline($challengeId, $user2Id);
        
        $this->assertFalse($declineResult['ok']);
        $this->assertSame('Challenge is not pending', $declineResult['message']);
    }

    public function testDeclineChallengeDoesNotAllowChallengerToDecline(): void
    {
        $user1Id = $this->createTestUser('challenger20');
        $user2Id = $this->createTestUser('target16');
        
        // User 1 challenges user 2
        $result = $this->challengeService->send($user1Id, 'target16');
        $challengeId = $result['challenge_id'];
        
        // User 1 tries to decline their own challenge (should fail - only recipient can decline)
        $declineResult = $this->challengeService->decline($challengeId, $user1Id);
        
        $this->assertFalse($declineResult['ok']);
        $this->assertSame('Not authorized to decline this challenge', $declineResult['message']);
    }

    public function testDeclineChallengeSetsRespondedAtTimestamp(): void
    {
        $user1Id = $this->createTestUser('challenger21');
        $user2Id = $this->createTestUser('target17');
        
        $result = $this->challengeService->send($user1Id, 'target17');
        $challengeId = $result['challenge_id'];
        
        $this->challengeService->decline($challengeId, $user2Id);
        
        $challenge = $this->getChallenge($challengeId);
        $this->assertNotNull($challenge['responded_at'], 'Challenge should have responded_at timestamp');
        
        // Verify responded_at is recent (within last minute)
        $stmt = $this->pdo->query("
            SELECT TIMESTAMPDIFF(SECOND, responded_at, NOW()) as seconds_ago
            FROM game_challenges 
            WHERE id = $challengeId
        ");
        $secondsAgo = (int)$stmt->fetch()['seconds_ago'];
        $this->assertGreaterThanOrEqual(0, $secondsAgo);
        $this->assertLessThanOrEqual(60, $secondsAgo);
    }

    // ============================================================================
    // EDGE CASES & INTEGRATION TESTS
    // ============================================================================

    public function testMultipleChallengesCanBeAcceptedBySameUser(): void
    {
        $user1Id = $this->createTestUser('challenger22');
        $user2Id = $this->createTestUser('challenger23');
        $user3Id = $this->createTestUser('target18');
        
        // User 1 and user 2 both challenge user 3
        $result1 = $this->challengeService->send($user1Id, 'target18');
        $result2 = $this->challengeService->send($user2Id, 'target18');
        
        // User 3 can accept both (though typically only one would be accepted)
        $accept1 = $this->challengeService->accept($result1['challenge_id'], $user3Id);
        $accept2 = $this->challengeService->accept($result2['challenge_id'], $user3Id);
        
        $this->assertTrue($accept1['ok']);
        $this->assertTrue($accept2['ok']);
        // Games functionality not yet implemented
        // When games are implemented, verify different game_ids here
    }

    public function testChallengeWorkflowComplete(): void
    {
        $user1Id = $this->createTestUser('challenger24');
        $user2Id = $this->createTestUser('target19');
        
        // 1. Send challenge
        $sendResult = $this->challengeService->send($user1Id, 'target19');
        $this->assertTrue($sendResult['ok']);
        $challengeId = $sendResult['challenge_id'];
        
        $challenge = $this->getChallenge($challengeId);
        $this->assertSame('pending', $challenge['status']);
        $this->assertNull($challenge['game_id']);
        
        // 2. Accept challenge
        $acceptResult = $this->challengeService->accept($challengeId, $user2Id);
        $this->assertTrue($acceptResult['ok']);
        
        $challenge = $this->getChallenge($challengeId);
        $this->assertSame('accepted', $challenge['status']);
        $this->assertNotNull($challenge['responded_at']);
        
        // Games functionality not yet implemented
        // When games are implemented, verify game creation here
    }

    public function testChallengeWorkflowWithDecline(): void
    {
        $user1Id = $this->createTestUser('challenger25');
        $user2Id = $this->createTestUser('target20');
        
        // 1. Send challenge
        $sendResult = $this->challengeService->send($user1Id, 'target20');
        $this->assertTrue($sendResult['ok']);
        $challengeId = $sendResult['challenge_id'];
        
        // 2. Decline challenge
        $declineResult = $this->challengeService->decline($challengeId, $user2Id);
        $this->assertTrue($declineResult['ok']);
        
        $challenge = $this->getChallenge($challengeId);
        $this->assertSame('declined', $challenge['status']);
        $this->assertNull($challenge['game_id']);
        $this->assertNotNull($challenge['responded_at']);
    }

    public function testChallengeHandlesCaseInsensitiveUsernameLookup(): void
    {
        $user1Id = $this->createTestUser('challenger26');
        $user2Id = $this->createTestUser('Target21'); // Mixed case
        
        // Should work with different case (MySQL is case-insensitive by default)
        $result = $this->challengeService->send($user1Id, 'target21'); // Lowercase
        
        $this->assertTrue($result['ok']);
        $challenge = $this->getChallenge($result['challenge_id']);
        $this->assertSame($user2Id, (int)$challenge['to_user_id']);
    }

    public function testChallengeCannotBeAcceptedOrDeclinedAfterAcceptance(): void
    {
        $user1Id = $this->createTestUser('challenger27');
        $user2Id = $this->createTestUser('target22');
        
        $result = $this->challengeService->send($user1Id, 'target22');
        $challengeId = $result['challenge_id'];
        
        // Accept it
        $this->challengeService->accept($challengeId, $user2Id);
        
        // Try to accept again (should fail)
        $accept2 = $this->challengeService->accept($challengeId, $user2Id);
        $this->assertFalse($accept2['ok']);
        
        // Try to decline (should fail)
        $decline = $this->challengeService->decline($challengeId, $user2Id);
        $this->assertFalse($decline['ok']);
    }

    public function testChallengeCannotBeAcceptedOrDeclinedAfterDecline(): void
    {
        $user1Id = $this->createTestUser('challenger28');
        $user2Id = $this->createTestUser('target23');
        
        $result = $this->challengeService->send($user1Id, 'target23');
        $challengeId = $result['challenge_id'];
        
        // Decline it
        $this->challengeService->decline($challengeId, $user2Id);
        
        // Try to accept (should fail)
        $accept = $this->challengeService->accept($challengeId, $user2Id);
        $this->assertFalse($accept['ok']);
        
        // Try to decline again (should fail)
        $decline2 = $this->challengeService->decline($challengeId, $user2Id);
        $this->assertFalse($decline2['ok']);
    }

    // ============================================================================
    // CONCURRENT OPERATION TESTS
    // ============================================================================

    public function testConcurrentChallengeAcceptanceOnlyOneSucceeds(): void
    {
        // Test that if two users try to accept the same challenge simultaneously,
        // only one should succeed (race condition prevention)
        
        $unique = substr(uniqid('', true), -8);
        $user1Id = $this->createTestUser('challenger_concurrent' . $unique);
        $user2Id = $this->createTestUser('target_concurrent' . $unique);
        
        // Create challenge
        $result = $this->challengeService->send($user1Id, 'target_concurrent' . $unique);
        $challengeId = $result['challenge_id'];
        
        // Commit transaction to allow concurrent operations
        $wasInTransaction = $this->pdo->inTransaction();
        if ($wasInTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            // Simulate concurrent acceptance attempts
            // In real scenario, these would happen simultaneously, but we test sequentially
            // with the same challenge to verify only one succeeds
            
            $accept1 = $this->challengeService->accept($challengeId, $user2Id);
            $accept2 = $this->challengeService->accept($challengeId, $user2Id);
            
            // First should succeed
            $this->assertTrue($accept1['ok'], 'First acceptance should succeed');
            
            // Second should fail (challenge already accepted)
            $this->assertFalse($accept2['ok'], 'Second acceptance should fail');
            $this->assertSame('Challenge is not pending', $accept2['message']);
            
            // Verify challenge status is 'accepted'
            $challenge = $this->getChallenge($challengeId);
            $this->assertSame('accepted', $challenge['status']);
        } finally {
            // Restart transaction
            if ($wasInTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
    }

    public function testConcurrentChallengeAcceptAndDecline(): void
    {
        // Test that if user tries to accept and decline simultaneously,
        // only one operation should succeed
        
        $unique = substr(uniqid('', true), -8);
        $user1Id = $this->createTestUser('challenger_concurrent2' . $unique);
        $user2Id = $this->createTestUser('target_concurrent2' . $unique);
        
        $result = $this->challengeService->send($user1Id, 'target_concurrent2' . $unique);
        $challengeId = $result['challenge_id'];
        
        // Commit transaction
        $wasInTransaction = $this->pdo->inTransaction();
        if ($wasInTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            // Try to accept and decline (simulating concurrent operations)
            // In real scenario, these would be simultaneous
            $accept = $this->challengeService->accept($challengeId, $user2Id);
            $decline = $this->challengeService->decline($challengeId, $user2Id);
            
            // First operation should succeed
            $this->assertTrue($accept['ok'], 'Accept should succeed');
            
            // Second operation should fail (challenge no longer pending)
            $this->assertFalse($decline['ok'], 'Decline should fail after accept');
            $this->assertSame('Challenge is not pending', $decline['message']);
            
            // Verify final status
            $challenge = $this->getChallenge($challengeId);
            $this->assertSame('accepted', $challenge['status']);
        } finally {
            if ($wasInTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
    }
}

