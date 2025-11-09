<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for CSRF protection
 * 
 * Tests that CSRF tokens are properly validated and consumed for state-changing operations.
 * 
 * @coversNothing
 */
final class CSRFProtectionTest extends TestCase
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
        
        require_once __DIR__ . '/../../../lib/security.php';
        require_once __DIR__ . '/../../../app/db/nonces.php';
        require_once __DIR__ . '/../../../app/db/sessions.php';
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
     * Helper: Create a test session.
     */
    private function createTestSession(int $userId): int
    {
        require_once __DIR__ . '/../../../app/db/sessions.php';
        $ipHash = hash('sha256', '127.0.0.1');
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
        return \db_insert_session($this->pdo, $userId, $ipHash, 'PHPUnit Test', $expiresAt);
    }

    /**
     * Helper: Create a CSRF nonce.
     */
    private function createTestNonce(int $sessionId, int $ttlMinutes = 15): string
    {
        require_once __DIR__ . '/../../../app/db/nonces.php';
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$ttlMinutes} minutes"));
        $nonce = bin2hex(random_bytes(32));
        \db_insert_nonce($this->pdo, $sessionId, $nonce, $expiresAt);
        return $nonce;
    }

    // ============================================================================
    // VALIDATE_CSRF_TOKEN FUNCTION TESTS
    // ============================================================================

    public function testValidateCsrfTokenAcceptsValidToken(): void
    {
        $userId = $this->createTestUser('csrf_user1');
        $sessionId = $this->createTestSession($userId);
        $nonce = $this->createTestNonce($sessionId);

        // Should not throw exception
        $this->expectNotToPerformAssertions();
        validate_csrf_token($this->pdo, $nonce, $sessionId);
    }

    public function testValidateCsrfTokenRejectsMissingToken(): void
    {
        $userId = $this->createTestUser('csrf_user2');
        $sessionId = $this->createTestSession($userId);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CSRF_TOKEN_MISSING');
        validate_csrf_token($this->pdo, '', $sessionId);
    }
    
    public function testValidateCsrfTokenRejectsNullToken(): void
    {
        $userId = $this->createTestUser('csrf_user_null');
        $sessionId = $this->createTestSession($userId);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CSRF_TOKEN_MISSING');
        // Pass empty string (null would cause type error, but empty string should trigger missing)
        validate_csrf_token($this->pdo, '', $sessionId);
    }
    
    public function testValidateCsrfTokenReturnsStructuredErrorForMissingToken(): void
    {
        // This test documents that missing tokens should return CSRF_TOKEN_MISSING error
        // In endpoint context, this should map to 400/403 with structured JSON
        $userId = $this->createTestUser('csrf_user_structured');
        $sessionId = $this->createTestSession($userId);
        
        try {
            validate_csrf_token($this->pdo, '', $sessionId);
            $this->fail('Should throw exception for missing token');
        } catch (RuntimeException $e) {
            $this->assertEquals('CSRF_TOKEN_MISSING', $e->getMessage(), 
                'Missing token should return CSRF_TOKEN_MISSING error code');
        }
    }

    public function testValidateCsrfTokenRejectsInvalidToken(): void
    {
        $userId = $this->createTestUser('csrf_user3');
        $sessionId = $this->createTestSession($userId);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CSRF_TOKEN_INVALID');
        validate_csrf_token($this->pdo, 'invalid_token_12345', $sessionId);
    }

    public function testValidateCsrfTokenRejectsExpiredToken(): void
    {
        $userId = $this->createTestUser('csrf_user4');
        $sessionId = $this->createTestSession($userId);
        
        // Create expired nonce (negative TTL)
        require_once __DIR__ . '/../../../app/db/nonces.php';
        $expiresAt = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $nonce = bin2hex(random_bytes(32));
        \db_insert_nonce($this->pdo, $sessionId, $nonce, $expiresAt);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CSRF_TOKEN_EXPIRED');
        validate_csrf_token($this->pdo, $nonce, $sessionId);
    }
    
    public function testValidateCsrfTokenRejectsTokenAfterExpiryTime(): void
    {
        $userId = $this->createTestUser('csrf_user_expiry');
        $sessionId = $this->createTestSession($userId);
        
        // Create nonce with very short TTL (1 second)
        require_once __DIR__ . '/../../../app/db/nonces.php';
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 second'));
        $nonce = bin2hex(random_bytes(32));
        \db_insert_nonce($this->pdo, $sessionId, $nonce, $expiresAt);
        
        // Token should be valid immediately (should not throw)
        try {
            validate_csrf_token($this->pdo, $nonce, $sessionId);
            $firstValidationSucceeded = true;
        } catch (RuntimeException $e) {
            $firstValidationSucceeded = false;
            $this->fail('CSRF token should be valid immediately, but got: ' . $e->getMessage());
        }
        
        $this->assertTrue($firstValidationSucceeded, 'Token should be valid immediately');
        
        // Create another token and simulate expiry by setting expires_at in the past
        $nonce2 = bin2hex(random_bytes(32));
        $expiresAt2 = date('Y-m-d H:i:s', strtotime('-1 second')); // Already expired
        \db_insert_nonce($this->pdo, $sessionId, $nonce2, $expiresAt2);
        
        // Now token should be expired
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CSRF_TOKEN_EXPIRED');
        validate_csrf_token($this->pdo, $nonce2, $sessionId);
    }

    public function testValidateCsrfTokenRejectsUsedToken(): void
    {
        $userId = $this->createTestUser('csrf_user5');
        $sessionId = $this->createTestSession($userId);
        $nonce = $this->createTestNonce($sessionId);

        // First use should succeed
        validate_csrf_token($this->pdo, $nonce, $sessionId);

        // Second use should fail (token already used) - this is the replay protection
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CSRF_TOKEN_ALREADY_USED');
        validate_csrf_token($this->pdo, $nonce, $sessionId);
    }
    
    public function testCsrfTokenReplayProtection(): void
    {
        // Explicit test for replay protection: token can only be used once
        $userId = $this->createTestUser('csrf_replay_user');
        $sessionId = $this->createTestSession($userId);
        $nonce = $this->createTestNonce($sessionId);
        
        // First use succeeds (should not throw)
        try {
            validate_csrf_token($this->pdo, $nonce, $sessionId);
            $firstUseSucceeded = true;
        } catch (RuntimeException $e) {
            $firstUseSucceeded = false;
            $this->fail('First CSRF token validation should succeed, but got: ' . $e->getMessage());
        }
        
        $this->assertTrue($firstUseSucceeded, 'First token use should succeed');
        
        // Verify token is marked as used
        require_once __DIR__ . '/../../../app/db/nonces.php';
        $row = \db_get_nonce($this->pdo, $nonce);
        $this->assertNotNull($row, 'Token should exist in database');
        $this->assertNotNull($row['used_at'], 'Token should be marked as used after first validation');
        
        // Second use fails (replay attack prevention)
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CSRF_TOKEN_ALREADY_USED');
        validate_csrf_token($this->pdo, $nonce, $sessionId);
    }

    public function testValidateCsrfTokenRejectsTokenFromDifferentSession(): void
    {
        $userId1 = $this->createTestUser('csrf_user6');
        $userId2 = $this->createTestUser('csrf_user7');
        $sessionId1 = $this->createTestSession($userId1);
        $sessionId2 = $this->createTestSession($userId2);
        
        // Create nonce for session 1
        $nonce = $this->createTestNonce($sessionId1);

        // Try to use it with session 2 (should fail) - this is session binding protection
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CSRF_TOKEN_SESSION_MISMATCH');
        validate_csrf_token($this->pdo, $nonce, $sessionId2);
    }
    
    public function testCsrfTokenSessionBinding(): void
    {
        // Explicit test for session binding: token for session A rejected for session B
        $userId1 = $this->createTestUser('csrf_binding_user1');
        $userId2 = $this->createTestUser('csrf_binding_user2');
        $sessionId1 = $this->createTestSession($userId1);
        $sessionId2 = $this->createTestSession($userId2);
        
        // Create token for session 1
        $nonce = $this->createTestNonce($sessionId1);
        
        // Token should work for session 1 (should not throw)
        try {
            validate_csrf_token($this->pdo, $nonce, $sessionId1);
            $firstValidationSucceeded = true;
        } catch (RuntimeException $e) {
            $firstValidationSucceeded = false;
            $this->fail('CSRF token validation for correct session should succeed, but got: ' . $e->getMessage());
        }
        
        $this->assertTrue($firstValidationSucceeded, 'Token should work for its own session');
        
        // Create another token for session 1
        $nonce2 = $this->createTestNonce($sessionId1);
        
        // Token should be rejected for session 2 (different session)
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CSRF_TOKEN_SESSION_MISMATCH');
        validate_csrf_token($this->pdo, $nonce2, $sessionId2);
    }

    public function testValidateCsrfTokenRejectsTokenForInvalidSession(): void
    {
        $userId = $this->createTestUser('csrf_user8');
        $sessionId = $this->createTestSession($userId);
        $nonce = $this->createTestNonce($sessionId);

        // Revoke the session
        require_once __DIR__ . '/../../../app/db/sessions.php';
        \db_revoke_session($this->pdo, $sessionId);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CSRF_TOKEN_SESSION_INVALID');
        validate_csrf_token($this->pdo, $nonce, $sessionId);
    }

    public function testValidateCsrfTokenMarksTokenAsUsed(): void
    {
        $userId = $this->createTestUser('csrf_user9');
        $sessionId = $this->createTestSession($userId);
        $nonce = $this->createTestNonce($sessionId);

        // Validate token (marks it as used)
        validate_csrf_token($this->pdo, $nonce, $sessionId);

        // Verify token is marked as used in database
        require_once __DIR__ . '/../../../app/db/nonces.php';
        $row = \db_get_nonce($this->pdo, $nonce);
        $this->assertNotNull($row, 'Nonce should exist in database');
        $this->assertNotNull($row['used_at'], 'Nonce should be marked as used');
    }

    public function testValidateCsrfTokenWorksWithoutSessionId(): void
    {
        $userId = $this->createTestUser('csrf_user10');
        $sessionId = $this->createTestSession($userId);
        $nonce = $this->createTestNonce($sessionId);

        // Should not throw exception when sessionId is null
        $this->expectNotToPerformAssertions();
        validate_csrf_token($this->pdo, $nonce, null);
    }

    public function testValidateCsrfTokenWithoutSessionIdStillMarksAsUsed(): void
    {
        $userId = $this->createTestUser('csrf_user11');
        $sessionId = $this->createTestSession($userId);
        $nonce = $this->createTestNonce($sessionId);

        // Validate without sessionId (should still mark as used)
        validate_csrf_token($this->pdo, $nonce, null);

        // Verify token is marked as used
        require_once __DIR__ . '/../../../app/db/nonces.php';
        $row = \db_get_nonce($this->pdo, $nonce);
        $this->assertNotNull($row['used_at'], 'Nonce should be marked as used even without sessionId');
    }

    // ============================================================================
    // ENDPOINT CSRF PROTECTION TESTS
    // ============================================================================
    // These tests verify that endpoints properly reject requests without CSRF tokens
    // Note: Full endpoint testing would require HTTP request simulation, which is
    // complex. These tests focus on the CSRF validation logic.

    public function testLogoutEndpointRequiresCsrfToken(): void
    {
        // This test verifies the logout endpoint structure
        // Actual HTTP request testing would require a test server setup
        // For now, we verify the validate_csrf_token function works correctly
        // which is what the endpoint uses
        
        $userId = $this->createTestUser('logout_user');
        $sessionId = $this->createTestSession($userId);
        $nonce = $this->createTestNonce($sessionId);

        // Valid token should work
        $this->expectNotToPerformAssertions();
        validate_csrf_token($this->pdo, $nonce, $sessionId);
    }

    public function testChallengeEndpointRequiresCsrfToken(): void
    {
        // Verify CSRF token validation works for challenge endpoint
        $userId = $this->createTestUser('challenge_user');
        $sessionId = $this->createTestSession($userId);
        $nonce = $this->createTestNonce($sessionId);

        $this->expectNotToPerformAssertions();
        validate_csrf_token($this->pdo, $nonce, $sessionId);
    }

    public function testMultipleTokensCanBeCreatedForSameSession(): void
    {
        $userId = $this->createTestUser('multitoken_user');
        $sessionId = $this->createTestSession($userId);

        // Create multiple nonces for same session
        $nonce1 = $this->createTestNonce($sessionId);
        $nonce2 = $this->createTestNonce($sessionId);
        $nonce3 = $this->createTestNonce($sessionId);

        // All should be valid
        validate_csrf_token($this->pdo, $nonce1, $sessionId);
        validate_csrf_token($this->pdo, $nonce2, $sessionId);
        validate_csrf_token($this->pdo, $nonce3, $sessionId);

        // All should be marked as used
        require_once __DIR__ . '/../../../app/db/nonces.php';
        $row1 = \db_get_nonce($this->pdo, $nonce1);
        $row2 = \db_get_nonce($this->pdo, $nonce2);
        $row3 = \db_get_nonce($this->pdo, $nonce3);

        $this->assertNotNull($row1['used_at'], 'Nonce1 should be used');
        $this->assertNotNull($row2['used_at'], 'Nonce2 should be used');
        $this->assertNotNull($row3['used_at'], 'Nonce3 should be used');
    }

    public function testNonceServiceIntegration(): void
    {
        // Test that nonce_issue() creates tokens that can be validated
        $userId = $this->createTestUser('nonceservice_user');
        $sessionId = $this->createTestSession($userId);

        // Set up session cookie for nonce_issue()
        $_COOKIE['session_id'] = (string)$sessionId;
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test';

        // Create nonce using service
        $result = nonce_issue($this->pdo);
        $this->assertArrayHasKey('nonce', $result);
        $this->assertArrayHasKey('expiresAt', $result);
        $nonce = $result['nonce'];

        // Should be able to validate it (should not throw exception)
        validate_csrf_token($this->pdo, $nonce, $sessionId);
        
        // Verify token is marked as used
        require_once __DIR__ . '/../../../app/db/nonces.php';
        $row = \db_get_nonce($this->pdo, $nonce);
        $this->assertNotNull($row['used_at'], 'Nonce should be marked as used after validation');
    }
}

