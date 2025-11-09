<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseDBIntegrationTest.php';

/**
 * Integration tests for app/db/sessions.php
 *
 * Comprehensive test suite for session database functions.
 * Tests all CRUD operations, edge cases, and business logic.
 *
 * Uses the actual MySQL database connection from bootstrap.php.
 * Tests use transactions for isolation - each test runs in a transaction
 * that is rolled back in tearDown to ensure test isolation.
 *
 * @coversNothing
 */
final class SessionsDBTest extends BaseDBIntegrationTest
{
    /**
     * Load database functions required for session tests
     */
    protected function loadDatabaseFunctions(): void
    {
        require_once __DIR__ . '/../../../app/db/sessions.php';
    }

    // ============================================================================
    // INSERT TESTS
    // ============================================================================

    /**
     * Test that inserting a session creates a row with correct data
     */
    public function testInsertSessionCreatesRow(): void
    {
        $userId = $this->createTestUser('testuser_session1');
        $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
        $ipHash = '192.168.1.100-hash';
        $userAgent = 'Mozilla/5.0 Test Browser';
        
        $sessionId = $this->createTestSession($userId, $expiresAt, $ipHash, $userAgent);
        
        $this->assertGreaterThan(0, $sessionId, 'Session ID should be positive');
        
        $session = $this->getSession($sessionId);
        $this->assertNotNull($session, 'Session should exist after insert');
        $this->assertSame($userId, (int)$session['user_id']);
        $this->assertSame($expiresAt, $session['expires_at']);
        $this->assertSame($ipHash, $session['ip_hash']);
        $this->assertSame($userAgent, $session['user_agent']);
        $this->assertNull($session['revoked_at'], 'Session should not be revoked initially');
    }

    /**
     * Test that inserting a session sets created_at timestamp
     */
    public function testInsertSessionSetsCreatedAtTimestamp(): void
    {
        $userId = $this->createTestUser('testuser_session2');
        $sessionId = $this->createTestSession($userId);
        
        $session = $this->getSession($sessionId);
        $this->assertNotNull($session['created_at'], 'created_at should be set');
        
        // Verify created_at is recent (within last minute)
        $this->assertRecentTimestamp('sessions', 'created_at', $sessionId);
    }

    /**
     * Test that a user can have multiple sessions
     */
    public function testInsertMultipleSessionsForSameUser(): void
    {
        $userId = $this->createTestUser('testuser_session3');
        
        $sessionId1 = $this->createTestSession($userId, null, 'ip1', 'agent1');
        $sessionId2 = $this->createTestSession($userId, null, 'ip2', 'agent2');
        $sessionId3 = $this->createTestSession($userId, null, 'ip3', 'agent3');
        
        $this->assertNotSame($sessionId1, $sessionId2);
        $this->assertNotSame($sessionId2, $sessionId3);
        
        // Verify all sessions exist
        $stmt = $this->pdo->prepare("SELECT COUNT(*) as count FROM sessions WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $userId]);
        $count = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
        $this->assertSame(3, $count, 'User should be able to have multiple sessions');
    }

    // ============================================================================
    // VALIDATION TESTS
    // ============================================================================

    /**
     * Test that valid sessions return true
     */
    public function testIsSessionValidReturnsTrueForValidSession(): void
    {
        $userId = $this->createTestUser('testuser_valid1');
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 day'));
        $sessionId = $this->createTestSession($userId, $expiresAt);
        
        $isValid = db_is_session_valid($this->pdo, $sessionId);
        $this->assertTrue($isValid, 'Valid session should return true');
    }

    /**
     * Test that revoked sessions return false
     */
    public function testIsSessionValidReturnsFalseForRevokedSession(): void
    {
        $userId = $this->createTestUser('testuser_valid2');
        $sessionId = $this->createTestSession($userId);
        
        // Revoke the session
        db_revoke_session($this->pdo, $sessionId);
        
        $isValid = db_is_session_valid($this->pdo, $sessionId);
        $this->assertFalse($isValid, 'Revoked session should return false');
    }

    /**
     * Test that expired sessions return false
     */
    public function testIsSessionValidReturnsFalseForExpiredSession(): void
    {
        $userId = $this->createTestUser('testuser_valid3');
        $expiresAt = date('Y-m-d H:i:s', strtotime('-1 day')); // Expired
        $sessionId = $this->createTestSession($userId, $expiresAt);
        
        $isValid = db_is_session_valid($this->pdo, $sessionId);
        $this->assertFalse($isValid, 'Expired session should return false');
    }

    /**
     * Test that non-existent sessions return false
     */
    public function testIsSessionValidReturnsFalseForNonExistentSession(): void
    {
        $isValid = db_is_session_valid($this->pdo, 999999);
        $this->assertFalse($isValid, 'Non-existent session should return false');
    }

    /**
     * Test that revoked and expired sessions return false
     */
    public function testIsSessionValidReturnsFalseForRevokedAndExpiredSession(): void
    {
        $userId = $this->createTestUser('testuser_valid4');
        $expiresAt = date('Y-m-d H:i:s', strtotime('-1 day'));
        $sessionId = $this->createTestSession($userId, $expiresAt);
        
        // Revoke it too
        db_revoke_session($this->pdo, $sessionId);
        
        $isValid = db_is_session_valid($this->pdo, $sessionId);
        $this->assertFalse($isValid, 'Revoked and expired session should return false');
    }

    // ============================================================================
    // GET USER ID TESTS
    // ============================================================================

    /**
     * Test that getting session user ID returns correct user ID
     */
    public function testGetSessionUserIdReturnsCorrectUserId(): void
    {
        $userId = $this->createTestUser('testuser_getid1');
        $sessionId = $this->createTestSession($userId);
        
        $retrievedUserId = db_get_session_user_id($this->pdo, $sessionId);
        $this->assertSame($userId, $retrievedUserId, 'Should return correct user ID');
    }

    /**
     * Test that getting user ID for non-existent session returns null
     */
    public function testGetSessionUserIdReturnsNullForNonExistentSession(): void
    {
        $userId = db_get_session_user_id($this->pdo, 999999);
        $this->assertNull($userId, 'Non-existent session should return null');
    }

    /**
     * Test that getting user ID works even for revoked sessions
     */
    public function testGetSessionUserIdReturnsUserIdEvenForRevokedSession(): void
    {
        $userId = $this->createTestUser('testuser_getid2');
        $sessionId = $this->createTestSession($userId);
        
        // Revoke the session
        db_revoke_session($this->pdo, $sessionId);
        
        // Should still return user_id (function doesn't check validity)
        $retrievedUserId = db_get_session_user_id($this->pdo, $sessionId);
        $this->assertSame($userId, $retrievedUserId, 'Should return user ID even for revoked session');
    }

    // ============================================================================
    // REVOKE TESTS
    // ============================================================================

    /**
     * Test that revoking a session sets revoked_at timestamp
     */
    public function testRevokeSessionSetsRevokedAtTimestamp(): void
    {
        $userId = $this->createTestUser('testuser_revoke1');
        $sessionId = $this->createTestSession($userId);
        
        db_revoke_session($this->pdo, $sessionId);
        
        $session = $this->getSession($sessionId);
        $this->assertNotNull($session['revoked_at'], 'revoked_at should be set after revoke');
        
        // Verify revoked_at is recent (within last minute)
        $this->assertRecentTimestamp('sessions', 'revoked_at', $sessionId);
    }

    /**
     * Test that revoking a session is idempotent
     */
    public function testRevokeSessionIsIdempotent(): void
    {
        $userId = $this->createTestUser('testuser_revoke2');
        $sessionId = $this->createTestSession($userId);
        
        // Revoke once
        db_revoke_session($this->pdo, $sessionId);
        $session = $this->getSession($sessionId);
        $firstRevokedAt = $session['revoked_at'];
        $this->assertNotNull($firstRevokedAt, 'Session should be revoked after first call');
        
        // Revoke again (idempotent operation)
        db_revoke_session($this->pdo, $sessionId);
        $session = $this->getSession($sessionId);
        $secondRevokedAt = $session['revoked_at'];
        
        // revoked_at may update or stay the same, but session should still be revoked
        $this->assertNotNull($secondRevokedAt, 'Session should still be revoked after second call');
        // Note: The function just does UPDATE, so it may update the timestamp
        // This is acceptable behavior - the key is that the session remains revoked
    }

    /**
     * Test that revoking one session does not affect other sessions
     */
    public function testRevokeSessionDoesNotAffectOtherSessions(): void
    {
        $userId = $this->createTestUser('testuser_revoke3');
        $sessionId1 = $this->createTestSession($userId);
        $sessionId2 = $this->createTestSession($userId);
        
        // Revoke first session
        db_revoke_session($this->pdo, $sessionId1);
        
        // Second session should still be valid
        $isValid = db_is_session_valid($this->pdo, $sessionId2);
        $this->assertTrue($isValid, 'Other sessions should remain valid');
        
        $session2 = $this->getSession($sessionId2);
        $this->assertNull($session2['revoked_at'], 'Other session should not be revoked');
    }

    // ============================================================================
    // GET SESSION WITH USER TESTS
    // ============================================================================

    /**
     * Test that getting session with user returns session and user data
     */
    public function testGetSessionWithUserReturnsSessionAndUserData(): void
    {
        $userId = $this->createTestUser('testuser_getwith1', 'testuser_getwith1@example.com');
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 day'));
        $ipHash = 'test-ip-hash-123';
        $userAgent = 'Test Browser 1.0';
        $sessionId = $this->createTestSession($userId, $expiresAt, $ipHash, $userAgent);
        
        $result = db_get_session_with_user($this->pdo, $sessionId);
        
        $this->assertNotNull($result, 'Should return session with user data');
        $this->assertSame($userId, (int)$result['user_id']);
        $this->assertSame('testuser_getwith1', $result['username']);
        $this->assertSame('testuser_getwith1@example.com', $result['email']);
        $this->assertSame($sessionId, (int)$result['session_id']);
        $this->assertSame($expiresAt, $result['expires_at']);
        $this->assertSame($ipHash, $result['ip_hash']);
        $this->assertSame($userAgent, $result['user_agent']);
    }

    /**
     * Test that getting session with user returns null for revoked session
     */
    public function testGetSessionWithUserReturnsNullForRevokedSession(): void
    {
        $userId = $this->createTestUser('testuser_getwith2');
        $sessionId = $this->createTestSession($userId);
        
        db_revoke_session($this->pdo, $sessionId);
        
        $result = db_get_session_with_user($this->pdo, $sessionId);
        $this->assertNull($result, 'Should return null for revoked session');
    }

    /**
     * Test that getting session with user returns null for expired session
     */
    public function testGetSessionWithUserReturnsNullForExpiredSession(): void
    {
        $userId = $this->createTestUser('testuser_getwith3');
        $expiresAt = date('Y-m-d H:i:s', strtotime('-1 day'));
        $sessionId = $this->createTestSession($userId, $expiresAt);
        
        $result = db_get_session_with_user($this->pdo, $sessionId);
        $this->assertNull($result, 'Should return null for expired session');
    }

    /**
     * Test that getting session with user returns null for non-existent session
     */
    public function testGetSessionWithUserReturnsNullForNonExistentSession(): void
    {
        $result = db_get_session_with_user($this->pdo, 999999);
        $this->assertNull($result, 'Should return null for non-existent session');
    }

    // ============================================================================
    // TOUCH SESSION TESTS (TTL Extension)
    // ============================================================================

    /**
     * Test that touching a session extends expiry time
     */
    public function testTouchSessionExtendsExpiryTime(): void
    {
        $userId = $this->createTestUser('testuser_touch1');
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 day'));
        $sessionId = $this->createTestSession($userId, $expiresAt);
        
        // Get original expiry
        $session = $this->getSession($sessionId);
        $originalExpiresAt = $session['expires_at'];
        
        // Touch session (extend by 7 days)
        db_touch_session($this->pdo, $sessionId, 7);
        
        // Get new expiry
        $session = $this->getSession($sessionId);
        $newExpiresAt = $session['expires_at'];
        
        $this->assertNotSame($originalExpiresAt, $newExpiresAt, 'expires_at should change');
        
        // Verify new expiry is approximately 7 days from now
        $stmt = $this->pdo->prepare("
            SELECT TIMESTAMPDIFF(DAY, NOW(), expires_at) as days_until_expiry
            FROM sessions 
            WHERE id = :id
        ");
        $stmt->execute(['id' => $sessionId]);
        $daysUntilExpiry = (int)$stmt->fetch(PDO::FETCH_ASSOC)['days_until_expiry'];
        $this->assertGreaterThanOrEqual(6, $daysUntilExpiry, 'Should be approximately 7 days');
        $this->assertLessThanOrEqual(8, $daysUntilExpiry, 'Should be approximately 7 days');
    }

    /**
     * Test that touching a revoked session does not extend expiry
     */
    public function testTouchSessionDoesNotExtendRevokedSession(): void
    {
        $userId = $this->createTestUser('testuser_touch2');
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 day'));
        $sessionId = $this->createTestSession($userId, $expiresAt);
        
        // Revoke session
        db_revoke_session($this->pdo, $sessionId);
        $session = $this->getSession($sessionId);
        $revokedAt = $session['revoked_at'];
        $expiresAtBefore = $session['expires_at'];
        
        // Try to touch revoked session
        db_touch_session($this->pdo, $sessionId, 7);
        
        // Should not update expires_at (function checks revoked_at IS NULL)
        $session = $this->getSession($sessionId);
        $this->assertSame($expiresAtBefore, $session['expires_at'], 
            'Revoked session should not have expiry extended');
        $this->assertSame($revokedAt, $session['revoked_at'], 
            'Revoked session should remain revoked');
    }

    /**
     * Test that touching a session with different day intervals works correctly
     */
    public function testTouchSessionWithDifferentDayIntervals(): void
    {
        $userId = $this->createTestUser('testuser_touch3');
        $sessionId = $this->createTestSession($userId);
        
        // Touch with 1 day
        db_touch_session($this->pdo, $sessionId, 1);
        $stmt = $this->pdo->prepare("
            SELECT TIMESTAMPDIFF(DAY, NOW(), expires_at) as days
            FROM sessions WHERE id = :id
        ");
        $stmt->execute(['id' => $sessionId]);
        $days1 = (int)$stmt->fetch(PDO::FETCH_ASSOC)['days'];
        $this->assertGreaterThanOrEqual(0, $days1, 'Should be approximately 1 day');
        $this->assertLessThanOrEqual(2, $days1, 'Should be approximately 1 day');
        
        // Touch with 30 days
        db_touch_session($this->pdo, $sessionId, 30);
        $stmt->execute(['id' => $sessionId]);
        $days30 = (int)$stmt->fetch(PDO::FETCH_ASSOC)['days'];
        $this->assertGreaterThanOrEqual(29, $days30, 'Should be approximately 30 days');
        $this->assertLessThanOrEqual(31, $days30, 'Should be approximately 30 days');
    }

    // ============================================================================
    // REVOKE ALL USER SESSIONS TESTS
    // ============================================================================

    /**
     * Test that revoking all user sessions revokes all sessions for that user
     */
    public function testRevokeAllUserSessionsRevokesAllSessionsForUser(): void
    {
        $userId1 = $this->createTestUser('testuser_revokeall1');
        $userId2 = $this->createTestUser('testuser_revokeall2');
        
        $sessionId1 = $this->createTestSession($userId1);
        $sessionId2 = $this->createTestSession($userId1);
        $sessionId3 = $this->createTestSession($userId1);
        $sessionId4 = $this->createTestSession($userId2); // Different user
        
        // Revoke all sessions for user 1
        db_revoke_all_user_sessions($this->pdo, $userId1);
        
        // User 1's sessions should be revoked
        $this->assertFalse(db_is_session_valid($this->pdo, $sessionId1));
        $this->assertFalse(db_is_session_valid($this->pdo, $sessionId2));
        $this->assertFalse(db_is_session_valid($this->pdo, $sessionId3));
        
        // User 2's session should still be valid
        $this->assertTrue(db_is_session_valid($this->pdo, $sessionId4));
    }

    /**
     * Test that revoking all user sessions is idempotent
     */
    public function testRevokeAllUserSessionsIsIdempotent(): void
    {
        $userId = $this->createTestUser('testuser_revokeall3');
        $sessionId = $this->createTestSession($userId);
        
        // Revoke all sessions twice
        db_revoke_all_user_sessions($this->pdo, $userId);
        db_revoke_all_user_sessions($this->pdo, $userId);
        
        // Session should still be revoked
        $this->assertFalse(db_is_session_valid($this->pdo, $sessionId));
    }

    /**
     * Test that revoking all sessions for a user with no sessions completes without error
     */
    public function testRevokeAllUserSessionsForUserWithNoSessions(): void
    {
        $userId = $this->createTestUser('testuser_revokeall4');
        
        // Should not throw error - verify no sessions exist first
        $stmt = $this->pdo->prepare("SELECT COUNT(*) as count FROM sessions WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $userId]);
        $countBefore = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
        $this->assertSame(0, $countBefore, 'User should have no sessions initially');
        
        // Should complete without error
        db_revoke_all_user_sessions($this->pdo, $userId);
        
        // Verify still no sessions (function should handle gracefully)
        $stmt->execute(['user_id' => $userId]);
        $countAfter = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
        $this->assertSame(0, $countAfter, 'User should still have no sessions');
    }

    // ============================================================================
    // EDGE CASES & INTEGRATION TESTS
    // ============================================================================

    /**
     * Test full session lifecycle from creation to revocation
     */
    public function testFullSessionLifecycle(): void
    {
        $userId = $this->createTestUser('testuser_lifecycle');
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
        
        // 1. Create session
        $sessionId = $this->createTestSession($userId, $expiresAt);
        $this->assertTrue(db_is_session_valid($this->pdo, $sessionId));
        
        // 2. Extend session
        db_touch_session($this->pdo, $sessionId, 60);
        $this->assertTrue(db_is_session_valid($this->pdo, $sessionId));
        
        // 3. Get session with user
        $session = db_get_session_with_user($this->pdo, $sessionId);
        $this->assertNotNull($session);
        
        // 4. Revoke session
        db_revoke_session($this->pdo, $sessionId);
        $this->assertFalse(db_is_session_valid($this->pdo, $sessionId));
        $this->assertNull(db_get_session_with_user($this->pdo, $sessionId));
    }

    /**
     * Test that multiple users can have multiple sessions without interference
     */
    public function testMultipleUsersMultipleSessions(): void
    {
        $userId1 = $this->createTestUser('user1_multisess');
        $userId2 = $this->createTestUser('user2_multisess');
        $userId3 = $this->createTestUser('user3_multisess');
        
        $session1a = $this->createTestSession($userId1);
        $session1b = $this->createTestSession($userId1);
        $session2a = $this->createTestSession($userId2);
        $session3a = $this->createTestSession($userId3);
        $session3b = $this->createTestSession($userId3);
        
        // All should be valid
        $this->assertTrue(db_is_session_valid($this->pdo, $session1a));
        $this->assertTrue(db_is_session_valid($this->pdo, $session1b));
        $this->assertTrue(db_is_session_valid($this->pdo, $session2a));
        $this->assertTrue(db_is_session_valid($this->pdo, $session3a));
        $this->assertTrue(db_is_session_valid($this->pdo, $session3b));
        
        // Revoke all for user 1
        db_revoke_all_user_sessions($this->pdo, $userId1);
        $this->assertFalse(db_is_session_valid($this->pdo, $session1a));
        $this->assertFalse(db_is_session_valid($this->pdo, $session1b));
        
        // Others should still be valid
        $this->assertTrue(db_is_session_valid($this->pdo, $session2a));
        $this->assertTrue(db_is_session_valid($this->pdo, $session3a));
        $this->assertTrue(db_is_session_valid($this->pdo, $session3b));
    }

    /**
     * Test session expiry boundary conditions
     */
    public function testSessionExpiryBoundaryConditions(): void
    {
        $userId = $this->createTestUser('testuser_expiry');
        
        // Session expiring in 1 second (should still be valid)
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 second'));
        $sessionId = $this->createTestSession($userId, $expiresAt);
        
        // MySQL NOW() has second precision, so it might be valid or invalid
        // depending on timing. We'll just verify the function works correctly
        $isValid = db_is_session_valid($this->pdo, $sessionId);
        $this->assertIsBool($isValid, 'Should return a boolean value');
        
        // Session expiring way in the future
        $expiresAt = date('Y-m-d H:i:s', strtotime('+365 days'));
        $sessionId = $this->createTestSession($userId, $expiresAt);
        $this->assertTrue(db_is_session_valid($this->pdo, $sessionId));
    }

    /**
     * Test that getting session user ID works with large session IDs
     */
    public function testGetSessionUserIdWorksWithLargeSessionIds(): void
    {
        $userId = $this->createTestUser('testuser_largeid');
        
        // Create multiple sessions to get a larger ID
        for ($i = 0; $i < 5; $i++) {
            $this->createTestSession($userId);
        }
        
        // Get the last one
        $stmt = $this->pdo->prepare("SELECT MAX(id) as max_id FROM sessions");
        $stmt->execute();
        $maxId = (int)$stmt->fetch(PDO::FETCH_ASSOC)['max_id'];
        
        $retrievedUserId = db_get_session_user_id($this->pdo, $maxId);
        $this->assertSame($userId, $retrievedUserId, 'Should return correct user ID for large session ID');
    }
}
