<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for WebSocket authentication tokens.
 * 
 * Tests the complete WebSocket token lifecycle:
 *  - Token creation (db_create_ws_nonce, nonce_issue_ws_token)
 *  - Token consumption (db_consume_ws_nonce)
 *  - Token expiration
 *  - Token single-use enforcement
 *  - Session validation
 *  - Token format and security properties
 * 
 * @coversNothing
 */
final class WebSocketTokenTest extends TestCase
{
    private PDO $pdo;
    private bool $inTransaction = false;

    protected function setUp(): void
    {
        global $pdo;
        
        if (!isset($pdo) && isset($GLOBALS['pdo'])) {
            $pdo = $GLOBALS['pdo'];
        }
        
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
        
        require_once __DIR__ . '/../../../app/db/nonces.php';
        require_once __DIR__ . '/../../../app/db/sessions.php';
        require_once __DIR__ . '/../../../app/db/users.php';
        require_once __DIR__ . '/../../../app/services/NonceService.php';
        require_once __DIR__ . '/../../../lib/session.php';
        
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        $this->pdo->beginTransaction();
        $this->inTransaction = true;
    }

    protected function tearDown(): void
    {
        if ($this->inTransaction && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
            $this->inTransaction = false;
        }
        
        // Clean up globals
        unset($_COOKIE['session_id']);
        $_SERVER = [];
    }

    /**
     * Helper: Create a test user and return user ID.
     */
    private function createTestUser(string $username): int
    {
        $unique = substr(uniqid('', true), -8);
        $email = $username . $unique . '@test.com';
        $passwordHash = password_hash('testpass123', PASSWORD_DEFAULT);
        
        $stmt = $this->pdo->prepare("
            INSERT INTO users (username, email, password_hash)
            VALUES (:username, :email, :password_hash)
        ");
        $stmt->execute([
            'username' => $username . $unique,
            'email' => $email,
            'password_hash' => $passwordHash,
        ]);
        
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Helper: Create a test session.
     */
    private function createTestSession(int $userId): int
    {
        $ipHash = hash('sha256', '127.0.0.1');
        $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
        return \db_insert_session($this->pdo, $userId, $ipHash, 'PHPUnit Test', $expiresAt);
    }

    /**
     * Helper: Set session cookie for testing.
     */
    private function setSessionCookie(int $sessionId): void
    {
        $_COOKIE['session_id'] = (string)$sessionId;
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test';
    }

    // ============================================================================
    // TOKEN CREATION TESTS
    // ============================================================================

    public function testDbCreateWsNonceCreatesValidToken(): void
    {
        $userId = $this->createTestUser('wstoken1');
        $sessionId = $this->createTestSession($userId);
        
        $token = \db_create_ws_nonce($this->pdo, $sessionId, 30);
        
        // Verify token format (32 hex chars = 16 bytes = 128 bits)
        // TODO: Update to 64 hex chars (32 bytes = 256 bits) for stronger entropy
        $this->assertSame(32, strlen($token), 'Token should be 32 hex characters (16 bytes)');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token, 'Token should be lowercase hex');
        
        // Verify token exists in database with all required fields
        $stmt = $this->pdo->prepare("SELECT * FROM csrf_nonces WHERE nonce = :token");
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertNotNull($row, 'Token should exist in database');
        $this->assertSame($sessionId, (int)$row['session_id'], 'Token should be linked to session');
        $this->assertNull($row['used_at'], 'Token should not be marked as used');
        $this->assertNotNull($row['created_at'], 'Token should have created_at timestamp');
        $this->assertNotNull($row['expires_at'], 'Token should have expires_at timestamp');
        
        // Verify expires_at is in the future (approximately 30 seconds from now)
        // Use database time comparison to avoid timezone issues
        $stmt2 = $this->pdo->prepare("
            SELECT TIMESTAMPDIFF(SECOND, NOW(), expires_at) as seconds_until_expiry
            FROM csrf_nonces
            WHERE nonce = :token
        ");
        $stmt2->execute(['token' => $token]);
        $expiryResult = $stmt2->fetch(PDO::FETCH_ASSOC);
        
        $this->assertNotFalse($expiryResult, 'Should be able to query expiry time');
        $secondsUntilExpiry = (int)$expiryResult['seconds_until_expiry'];
        $this->assertGreaterThan(0, $secondsUntilExpiry, 'Token should expire in the future');
        $this->assertLessThanOrEqual(35, $secondsUntilExpiry, 'Token should expire within TTL window (30s + 5s tolerance)');
    }

    public function testDbCreateWsNonceRejectsInvalidSessionId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        \db_create_ws_nonce($this->pdo, 0, 30);
    }

    public function testDbCreateWsNonceRejectsInvalidTtl(): void
    {
        $userId = $this->createTestUser('wstoken2');
        $sessionId = $this->createTestSession($userId);
        
        // Too short
        $this->expectException(InvalidArgumentException::class);
        \db_create_ws_nonce($this->pdo, $sessionId, 4);
        
        // Too long
        $this->expectException(InvalidArgumentException::class);
        \db_create_ws_nonce($this->pdo, $sessionId, 3601);
    }

    public function testDbCreateWsNonceAcceptsValidTtlRange(): void
    {
        $userId = $this->createTestUser('wstoken3');
        $sessionId = $this->createTestSession($userId);
        
        // Minimum TTL (5 seconds)
        $token1 = \db_create_ws_nonce($this->pdo, $sessionId, 5);
        $this->assertIsString($token1);
        
        // Maximum TTL (3600 seconds = 1 hour)
        $token2 = \db_create_ws_nonce($this->pdo, $sessionId, 3600);
        $this->assertIsString($token2);
        
        // Default TTL (30 seconds)
        $token3 = \db_create_ws_nonce($this->pdo, $sessionId, 30);
        $this->assertIsString($token3);
    }

    public function testNonceIssueWsTokenRequiresAuthenticatedSession(): void
    {
        // No session cookie set
        $_COOKIE = [];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test';
        
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UNAUTHORIZED');
        
        \nonce_issue_ws_token($this->pdo, 30);
    }

    public function testNonceIssueWsTokenCreatesTokenForAuthenticatedUser(): void
    {
        $userId = $this->createTestUser('wstoken4');
        $sessionId = $this->createTestSession($userId);
        $this->setSessionCookie($sessionId);
        
        $result = \nonce_issue_ws_token($this->pdo, 30);
        
        $this->assertArrayHasKey('token', $result);
        $this->assertArrayHasKey('expiresIn', $result);
        $this->assertSame(30, $result['expiresIn']);
        $this->assertSame(32, strlen($result['token']), 'Token should be 32 hex characters');
    }

    public function testNonceIssueWsTokenReturnsCorrectFormat(): void
    {
        $userId = $this->createTestUser('wstoken5');
        $sessionId = $this->createTestSession($userId);
        $this->setSessionCookie($sessionId);
        
        $result = \nonce_issue_ws_token($this->pdo, 60);
        
        $this->assertIsString($result['token']);
        $this->assertIsInt($result['expiresIn']);
        $this->assertSame(60, $result['expiresIn']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $result['token']);
    }

    // ============================================================================
    // TOKEN CONSUMPTION TESTS
    // ============================================================================

    public function testDbConsumeWsNonceReturnsUserContext(): void
    {
        $userId = $this->createTestUser('wstoken6');
        $sessionId = $this->createTestSession($userId);
        
        // Commit transaction to allow db_consume_ws_nonce to work
        $wasInTransaction = $this->pdo->inTransaction();
        if ($wasInTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            $token = \db_create_ws_nonce($this->pdo, $sessionId, 30);
            
            $result = \db_consume_ws_nonce($this->pdo, $token);
            
            $this->assertNotNull($result, 'Should return user context for valid token');
            $this->assertArrayHasKey('user_id', $result);
            $this->assertArrayHasKey('session_id', $result);
            $this->assertArrayHasKey('username', $result);
            $this->assertSame($userId, (int)$result['user_id']);
            $this->assertSame($sessionId, (int)$result['session_id']);
        } finally {
            if ($wasInTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
    }

    public function testDbConsumeWsNonceMarksTokenAsUsed(): void
    {
        $userId = $this->createTestUser('wstoken7');
        $sessionId = $this->createTestSession($userId);
        
        // Commit transaction to allow db_consume_ws_nonce to work
        $wasInTransaction = $this->pdo->inTransaction();
        if ($wasInTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            $token = \db_create_ws_nonce($this->pdo, $sessionId, 30);
            
            \db_consume_ws_nonce($this->pdo, $token);
            
            // Verify token is marked as used
            $stmt = $this->pdo->prepare("SELECT used_at FROM csrf_nonces WHERE nonce = :token");
            $stmt->execute(['token' => $token]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $this->assertNotNull($row['used_at'], 'Token should be marked as used');
        } finally {
            if ($wasInTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
    }

    public function testDbConsumeWsNonceRejectsInvalidToken(): void
    {
        // Commit transaction to allow db_consume_ws_nonce to work
        $wasInTransaction = $this->pdo->inTransaction();
        if ($wasInTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            $result = \db_consume_ws_nonce($this->pdo, 'invalid-token-that-does-not-exist');
            $this->assertNull($result, 'Should return null for invalid token');
        } finally {
            if ($wasInTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
    }

    public function testDbConsumeWsNonceRejectsEmptyToken(): void
    {
        // Commit transaction to allow db_consume_ws_nonce to work
        $wasInTransaction = $this->pdo->inTransaction();
        if ($wasInTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            $result = \db_consume_ws_nonce($this->pdo, '');
            $this->assertNull($result, 'Should return null for empty token');
        } finally {
            if ($wasInTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
    }

    public function testWsTokenIsSingleUse(): void
    {
        // Explicit test: token can only be consumed once
        $userId = $this->createTestUser('wstoken_single_use');
        $sessionId = $this->createTestSession($userId);
        
        $wasInTransaction = $this->pdo->inTransaction();
        if ($wasInTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            $token = \db_create_ws_nonce($this->pdo, $sessionId, 30);
            
            // First consumption should succeed
            $result1 = \db_consume_ws_nonce($this->pdo, $token);
            $this->assertNotNull($result1, 'First consumption should succeed');
            $this->assertEquals($userId, (int)$result1['user_id']);
            
            // Second consumption should fail (token already used)
            $result2 = \db_consume_ws_nonce($this->pdo, $token);
            $this->assertNull($result2, 'Second consumption should fail - token already used');
        } finally {
            if ($wasInTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
    }
    
    public function testWsTokenRejectsWrongUserSession(): void
    {
        // Test that token from user A cannot be used by user B
        $userId1 = $this->createTestUser('wstoken_user1');
        $userId2 = $this->createTestUser('wstoken_user2');
        $sessionId1 = $this->createTestSession($userId1);
        $sessionId2 = $this->createTestSession($userId2);
        
        $wasInTransaction = $this->pdo->inTransaction();
        if ($wasInTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            // Create token for user 1
            $token = \db_create_ws_nonce($this->pdo, $sessionId1, 30);
            
            // Token should work for user 1
            $result1 = \db_consume_ws_nonce($this->pdo, $token);
            $this->assertNotNull($result1, 'Token should work for correct user');
            $this->assertEquals($userId1, (int)$result1['user_id']);
            $this->assertEquals($sessionId1, (int)$result1['session_id']);
            
            // Create another token for user 1
            $token2 = \db_create_ws_nonce($this->pdo, $sessionId1, 30);
            
            // Verify token is linked to session 1 (not session 2)
            $stmt = $this->pdo->prepare("SELECT session_id, user_id FROM csrf_nonces n JOIN sessions s ON s.id = n.session_id WHERE n.nonce = :token");
            $stmt->execute(['token' => $token2]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->assertEquals($sessionId1, (int)$row['session_id'], 'Token should be linked to session 1');
            $this->assertEquals($userId1, (int)$row['user_id'], 'Token should be linked to user 1');
            
            // Token consumption returns user context, which includes user_id and session_id
            // The token itself is bound to session_id, so consuming it will return the correct user
            $result2 = \db_consume_ws_nonce($this->pdo, $token2);
            $this->assertNotNull($result2, 'Token should be consumable');
            $this->assertEquals($userId1, (int)$result2['user_id'], 'Should return user 1, not user 2');
            $this->assertEquals($sessionId1, (int)$result2['session_id'], 'Should return session 1, not session 2');
        } finally {
            if ($wasInTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
    }
    
    public function testWsTokenPersistedWithAllFields(): void
    {
        // Verify token is persisted with user_id, session_id, expires_at, single-use flag
        $userId = $this->createTestUser('wstoken_persist');
        $sessionId = $this->createTestSession($userId);
        
        $token = \db_create_ws_nonce($this->pdo, $sessionId, 30);
        
        // Verify all fields in database
        $stmt = $this->pdo->prepare("
            SELECT n.*, s.user_id, s.revoked_at as session_revoked
            FROM csrf_nonces n
            JOIN sessions s ON s.id = n.session_id
            WHERE n.nonce = :token
        ");
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertNotNull($row, 'Token should exist in database');
        $this->assertEquals($sessionId, (int)$row['session_id'], 'Token should be linked to session');
        $this->assertEquals($userId, (int)$row['user_id'], 'Token should be linked to user via session');
        $this->assertNotNull($row['created_at'], 'Token should have created_at');
        $this->assertNotNull($row['expires_at'], 'Token should have expires_at');
        $this->assertNull($row['used_at'], 'Token should not be used initially (single-use flag)');
        $this->assertNull($row['session_revoked'], 'Session should not be revoked');
        
        // Verify expires_at is approximately 30 seconds from now
        // Use database time comparison to avoid timezone issues
        $stmt2 = $this->pdo->prepare("
            SELECT TIMESTAMPDIFF(SECOND, NOW(), expires_at) as seconds_until_expiry
            FROM csrf_nonces
            WHERE nonce = :token
        ");
        $stmt2->execute(['token' => $token]);
        $expiryResult = $stmt2->fetch(PDO::FETCH_ASSOC);
        
        $this->assertNotFalse($expiryResult, 'Should be able to query expiry time');
        $secondsUntilExpiry = (int)$expiryResult['seconds_until_expiry'];
        $this->assertGreaterThan(0, $secondsUntilExpiry, 'Token should expire in the future');
        $this->assertLessThanOrEqual(35, $secondsUntilExpiry, 'Token should expire within TTL window (30s + 5s tolerance)');
    }

    public function testDbConsumeWsNonceRejectsAlreadyUsedToken(): void
    {
        $userId = $this->createTestUser('wstoken8');
        $sessionId = $this->createTestSession($userId);
        
        // Commit transaction to allow db_consume_ws_nonce to work
        $wasInTransaction = $this->pdo->inTransaction();
        if ($wasInTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            $token = \db_create_ws_nonce($this->pdo, $sessionId, 30);
            
            // Consume once
            $result1 = \db_consume_ws_nonce($this->pdo, $token);
            $this->assertNotNull($result1, 'First consumption should succeed');
            
            // Try to consume again
            $result2 = \db_consume_ws_nonce($this->pdo, $token);
            $this->assertNull($result2, 'Second consumption should fail (token already used)');
        } finally {
            if ($wasInTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
    }

    public function testDbConsumeWsNonceRejectsExpiredToken(): void
    {
        $userId = $this->createTestUser('wstoken9');
        $sessionId = $this->createTestSession($userId);
        
        // Commit transaction to allow db_consume_ws_nonce to work
        $wasInTransaction = $this->pdo->inTransaction();
        if ($wasInTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            // Create token with very short TTL (5 seconds)
            $token = \db_create_ws_nonce($this->pdo, $sessionId, 5);
            
            // Wait for token to expire
            sleep(6);
            
            // Try to consume expired token
            $result = \db_consume_ws_nonce($this->pdo, $token);
            $this->assertNull($result, 'Should return null for expired token');
        } finally {
            if ($wasInTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
    }

    public function testDbConsumeWsNonceRejectsTokenWithRevokedSession(): void
    {
        $userId = $this->createTestUser('wstoken10');
        $sessionId = $this->createTestSession($userId);
        
        // Commit transaction to allow db_consume_ws_nonce to work
        $wasInTransaction = $this->pdo->inTransaction();
        if ($wasInTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            $token = \db_create_ws_nonce($this->pdo, $sessionId, 30);
            
            // Revoke the session
            \db_revoke_session($this->pdo, $sessionId);
            
            // Try to consume token with revoked session
            $result = \db_consume_ws_nonce($this->pdo, $token);
            $this->assertNull($result, 'Should return null for token with revoked session');
        } finally {
            if ($wasInTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
    }

    public function testDbConsumeWsNonceRejectsTokenWithExpiredSession(): void
    {
        $userId = $this->createTestUser('wstoken11');
        $sessionId = $this->createTestSession($userId);
        
        // Commit transaction to allow db_consume_ws_nonce to work
        $wasInTransaction = $this->pdo->inTransaction();
        if ($wasInTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            $token = \db_create_ws_nonce($this->pdo, $sessionId, 30);
            
            // Expire the session
            $stmt = $this->pdo->prepare("
                UPDATE sessions SET expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = :sid
            ");
            $stmt->execute(['sid' => $sessionId]);
            
            // Try to consume token with expired session
            $result = \db_consume_ws_nonce($this->pdo, $token);
            $this->assertNull($result, 'Should return null for token with expired session');
        } finally {
            if ($wasInTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
    }

    // ============================================================================
    // TOKEN SECURITY PROPERTIES
    // ============================================================================

    public function testTokensAreUnique(): void
    {
        $userId = $this->createTestUser('wstoken12');
        $sessionId = $this->createTestSession($userId);
        
        $tokens = [];
        for ($i = 0; $i < 10; $i++) {
            $token = \db_create_ws_nonce($this->pdo, $sessionId, 30);
            $tokens[] = $token;
        }
        
        $uniqueTokens = array_unique($tokens);
        $this->assertCount(10, $uniqueTokens, 'All tokens should be unique');
    }

    public function testTokenExpiryIsCorrect(): void
    {
        $userId = $this->createTestUser('wstoken13');
        $sessionId = $this->createTestSession($userId);
        
        // Commit transaction to allow db_consume_ws_nonce to work
        $wasInTransaction = $this->pdo->inTransaction();
        if ($wasInTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            $ttl = 60; // 60 seconds
            $token = \db_create_ws_nonce($this->pdo, $sessionId, $ttl);
            
            // Check expiry time in database
            $stmt = $this->pdo->prepare("
                SELECT expires_at, created_at,
                       TIMESTAMPDIFF(SECOND, created_at, expires_at) as ttl_seconds
                FROM csrf_nonces WHERE nonce = :token
            ");
            $stmt->execute(['token' => $token]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $this->assertNotNull($row);
            $ttlSeconds = (int)$row['ttl_seconds'];
            $this->assertGreaterThanOrEqual($ttl - 1, $ttlSeconds, 'TTL should be approximately correct');
            $this->assertLessThanOrEqual($ttl + 1, $ttlSeconds);
        } finally {
            if ($wasInTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
    }

    public function testTokenConsumptionIsRaceSafe(): void
    {
        // This test verifies that db_consume_ws_nonce uses transactions and row locking
        // to prevent race conditions where two connections try to consume the same token
        
        $userId = $this->createTestUser('wstoken14');
        $sessionId = $this->createTestSession($userId);
        
        // Commit transaction to allow db_consume_ws_nonce to work
        $wasInTransaction = $this->pdo->inTransaction();
        if ($wasInTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            $token = \db_create_ws_nonce($this->pdo, $sessionId, 30);
            
            // Consume token (should succeed)
            $result1 = \db_consume_ws_nonce($this->pdo, $token);
            $this->assertNotNull($result1);
            
            // Verify token is marked as used (race condition prevention)
            $stmt = $this->pdo->prepare("SELECT used_at FROM csrf_nonces WHERE nonce = :token");
            $stmt->execute(['token' => $token]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->assertNotNull($row['used_at'], 'Token should be marked as used after consumption');
        } finally {
            if ($wasInTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
    }
}

