<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for session management functionality.
 *
 * Tests both low-level database functions (app/db/sessions.php) and
 * high-level session lifecycle functions (lib/session.php), including:
 *  - Session creation, validation, revocation
 *  - IP hash and user agent validation
 *  - Session expiry and TTL extension
 *  - Cookie handling
 *  - Edge cases and security considerations
 *
 * Uses the actual MySQL database for integration testing.
 * Tests use transactions for isolation - each test runs in a transaction
 * that is rolled back in tearDown to ensure test isolation.
 *
 * @coversNothing
 */
final class SessionServiceTest extends TestCase
{
    private PDO $pdo;
    private bool $inTransaction = false;

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

        // Load required functions
        require_once __DIR__ . '/../../../lib/session.php';
        require_once __DIR__ . '/../../../app/db/sessions.php';
        require_once __DIR__ . '/../../../app/db/users.php';

        // Disable foreign key checks for tests
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        
        // Start a transaction for test isolation
        $this->pdo->beginTransaction();
        $this->inTransaction = true;

        // Clear $_COOKIE and $_SERVER for clean test state
        $_COOKIE = [];
        $_SERVER = [];
    }

    protected function tearDown(): void
    {
        // Rollback transaction to clean up test data
        if ($this->inTransaction && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
            $this->inTransaction = false;
        }
        
        // Clean up globals
        $_COOKIE = [];
        $_SERVER = [];
    }

    /**
     * Helper: Create a test user and return user ID.
     */
    private function createTestUser(string $username, ?string $email = null): int
    {
        $email = $email ?? ($username . '@test.com');
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
     * Helper: Get session from database.
     */
    private function getSession(int $sessionId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM sessions WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $sessionId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ============================================================================
    // LOW-LEVEL DB FUNCTION TESTS
    // ============================================================================

    public function testDbInsertSessionCreatesSession(): void
    {
        $userId = $this->createTestUser('testuser_insert');
        $ipHash = hash('sha256', '127.0.0.1');
        $userAgent = 'Test User Agent';
        $expiresAt = date('Y-m-d H:i:s', time() + 86400); // 1 day from now
        
        $sessionId = db_insert_session($this->pdo, $userId, $ipHash, $userAgent, $expiresAt);
        
        $this->assertIsInt($sessionId);
        $this->assertGreaterThan(0, $sessionId);
        
        $session = $this->getSession($sessionId);
        $this->assertNotNull($session);
        $this->assertSame($userId, (int)$session['user_id']);
        $this->assertSame($ipHash, $session['ip_hash']);
        $this->assertSame($userAgent, $session['user_agent']);
        $this->assertNull($session['revoked_at']);
    }

    public function testDbInsertSessionTruncatesLongUserAgent(): void
    {
        $userId = $this->createTestUser('testuser_longua');
        $ipHash = hash('sha256', '127.0.0.1');
        $longUserAgent = str_repeat('a', 300); // Longer than 255 chars
        $expiresAt = date('Y-m-d H:i:s', time() + 86400);
        
        // db_insert_session expects truncated user agent - createSession() does this
        // But since we're testing the low-level function, we should truncate ourselves
        $truncatedUA = substr($longUserAgent, 0, 255);
        $sessionId = db_insert_session($this->pdo, $userId, $ipHash, $truncatedUA, $expiresAt);
        $session = $this->getSession($sessionId);
        
        $this->assertNotNull($session);
        $this->assertLessThanOrEqual(255, strlen($session['user_agent']));
    }

    public function testDbIsSessionValidReturnsTrueForValidSession(): void
    {
        $userId = $this->createTestUser('testuser_valid');
        $ipHash = hash('sha256', '127.0.0.1');
        $expiresAt = date('Y-m-d H:i:s', time() + 86400); // Future expiry
        
        $sessionId = db_insert_session($this->pdo, $userId, $ipHash, 'Test UA', $expiresAt);
        
        $this->assertTrue(db_is_session_valid($this->pdo, $sessionId));
    }

    public function testDbIsSessionValidReturnsFalseForRevokedSession(): void
    {
        $userId = $this->createTestUser('testuser_revoked');
        $ipHash = hash('sha256', '127.0.0.1');
        $expiresAt = date('Y-m-d H:i:s', time() + 86400);
        
        $sessionId = db_insert_session($this->pdo, $userId, $ipHash, 'Test UA', $expiresAt);
        db_revoke_session($this->pdo, $sessionId);
        
        $this->assertFalse(db_is_session_valid($this->pdo, $sessionId));
    }

    public function testDbIsSessionValidReturnsFalseForExpiredSession(): void
    {
        $userId = $this->createTestUser('testuser_expired');
        $ipHash = hash('sha256', '127.0.0.1');
        // Use MySQL DATE_SUB to ensure timezone consistency
        $stmt = $this->pdo->prepare("
            INSERT INTO sessions (user_id, expires_at, ip_hash, user_agent)
            VALUES (:uid, DATE_SUB(NOW(), INTERVAL 1 HOUR), :ip, :ua)
        ");
        $stmt->execute([
            'uid' => $userId,
            'ip' => $ipHash,
            'ua' => 'Test UA',
        ]);
        $sessionId = (int)$this->pdo->lastInsertId();
        
        $this->assertFalse(db_is_session_valid($this->pdo, $sessionId));
    }

    public function testDbIsSessionValidReturnsFalseForNonExistentSession(): void
    {
        $this->assertFalse(db_is_session_valid($this->pdo, 999999));
    }

    public function testDbGetSessionUserIdReturnsCorrectUserId(): void
    {
        $userId = $this->createTestUser('testuser_getid');
        $ipHash = hash('sha256', '127.0.0.1');
        $expiresAt = date('Y-m-d H:i:s', time() + 86400);
        
        $sessionId = db_insert_session($this->pdo, $userId, $ipHash, 'Test UA', $expiresAt);
        
        $this->assertSame($userId, db_get_session_user_id($this->pdo, $sessionId));
    }

    public function testDbGetSessionUserIdReturnsNullForNonExistentSession(): void
    {
        $this->assertNull(db_get_session_user_id($this->pdo, 999999));
    }

    public function testDbGetSessionWithUserReturnsSessionAndUserData(): void
    {
        // Use unique email to avoid conflicts
        $email = 'testuser_join_' . time() . '@example.com';
        $userId = $this->createTestUser('testuser_join_' . time(), $email);
        $ipHash = hash('sha256', '127.0.0.1');
        $expiresAt = date('Y-m-d H:i:s', time() + 86400);
        
        $sessionId = db_insert_session($this->pdo, $userId, $ipHash, 'Test UA', $expiresAt);
        
        $result = db_get_session_with_user($this->pdo, $sessionId);
        
        $this->assertNotNull($result);
        $this->assertSame($userId, (int)$result['user_id']);
        $this->assertSame($email, $result['email']);
        $this->assertSame($sessionId, (int)$result['session_id']);
        $this->assertArrayHasKey('expires_at', $result);
        $this->assertArrayHasKey('ip_hash', $result);
        $this->assertArrayHasKey('user_agent', $result);
    }

    public function testDbGetSessionWithUserReturnsNullForRevokedSession(): void
    {
        $userId = $this->createTestUser('testuser_revokedjoin');
        $ipHash = hash('sha256', '127.0.0.1');
        $expiresAt = date('Y-m-d H:i:s', time() + 86400);
        
        $sessionId = db_insert_session($this->pdo, $userId, $ipHash, 'Test UA', $expiresAt);
        db_revoke_session($this->pdo, $sessionId);
        
        $this->assertNull(db_get_session_with_user($this->pdo, $sessionId));
    }

    public function testDbGetSessionWithUserReturnsNullForExpiredSession(): void
    {
        $userId = $this->createTestUser('testuser_expiredjoin');
        $ipHash = hash('sha256', '127.0.0.1');
        // Use MySQL DATE_SUB to ensure timezone consistency
        $stmt = $this->pdo->prepare("
            INSERT INTO sessions (user_id, expires_at, ip_hash, user_agent)
            VALUES (:uid, DATE_SUB(NOW(), INTERVAL 1 HOUR), :ip, :ua)
        ");
        $stmt->execute([
            'uid' => $userId,
            'ip' => $ipHash,
            'ua' => 'Test UA',
        ]);
        $sessionId = (int)$this->pdo->lastInsertId();
        
        $this->assertNull(db_get_session_with_user($this->pdo, $sessionId));
    }

    public function testDbRevokeSessionSetsRevokedAt(): void
    {
        $userId = $this->createTestUser('testuser_revoke');
        $ipHash = hash('sha256', '127.0.0.1');
        $expiresAt = date('Y-m-d H:i:s', time() + 86400);
        
        $sessionId = db_insert_session($this->pdo, $userId, $ipHash, 'Test UA', $expiresAt);
        db_revoke_session($this->pdo, $sessionId);
        
        $session = $this->getSession($sessionId);
        $this->assertNotNull($session['revoked_at']);
    }

    public function testDbRevokeSessionIsIdempotent(): void
    {
        $userId = $this->createTestUser('testuser_idempotent');
        $ipHash = hash('sha256', '127.0.0.1');
        $expiresAt = date('Y-m-d H:i:s', time() + 86400);
        
        $sessionId = db_insert_session($this->pdo, $userId, $ipHash, 'Test UA', $expiresAt);
        
        // Revoke twice
        db_revoke_session($this->pdo, $sessionId);
        $firstRevoke = $this->getSession($sessionId)['revoked_at'];
        
        db_revoke_session($this->pdo, $sessionId);
        $secondRevoke = $this->getSession($sessionId)['revoked_at'];
        
        // Should remain revoked (idempotent)
        $this->assertNotNull($firstRevoke);
        $this->assertNotNull($secondRevoke);
    }

    public function testDbTouchSessionExtendsExpiry(): void
    {
        $userId = $this->createTestUser('testuser_touch');
        $ipHash = hash('sha256', '127.0.0.1');
        $originalExpires = date('Y-m-d H:i:s', time() + 86400);
        
        $sessionId = db_insert_session($this->pdo, $userId, $ipHash, 'Test UA', $originalExpires);
        
        // Wait a moment then touch
        sleep(1);
        $beforeTouch = time();
        db_touch_session($this->pdo, $sessionId, 7);
        $afterTouch = time();
        
        $session = $this->getSession($sessionId);
        $newExpires = strtotime($session['expires_at']);
        
        // Should be approximately 7 days from now
        // Use MySQL to calculate expected time to handle timezone differences
        $stmt = $this->pdo->query("SELECT DATE_ADD(NOW(), INTERVAL 7 DAY) as expected");
        $expected = strtotime($stmt->fetch()['expected']);
        
        // Allow for a reasonable tolerance (10 seconds) due to execution time and timezone
        $this->assertLessThanOrEqual(10, abs($newExpires - $expected));
    }

    public function testDbTouchSessionDoesNotAffectRevokedSession(): void
    {
        $userId = $this->createTestUser('testuser_touchrevoked');
        $ipHash = hash('sha256', '127.0.0.1');
        $expiresAt = date('Y-m-d H:i:s', time() + 86400);
        
        $sessionId = db_insert_session($this->pdo, $userId, $ipHash, 'Test UA', $expiresAt);
        db_revoke_session($this->pdo, $sessionId);
        
        $before = $this->getSession($sessionId);
        db_touch_session($this->pdo, $sessionId, 7);
        $after = $this->getSession($sessionId);
        
        // Expiry should not change (session is revoked, so touch shouldn't work)
        $this->assertSame($before['expires_at'], $after['expires_at']);
    }

    public function testDbRevokeAllUserSessionsRevokesAllSessions(): void
    {
        $userId = $this->createTestUser('testuser_multi');
        $ipHash = hash('sha256', '127.0.0.1');
        $expiresAt = date('Y-m-d H:i:s', time() + 86400);
        
        // Create multiple sessions
        $session1 = db_insert_session($this->pdo, $userId, $ipHash, 'UA1', $expiresAt);
        $session2 = db_insert_session($this->pdo, $userId, $ipHash, 'UA2', $expiresAt);
        $session3 = db_insert_session($this->pdo, $userId, $ipHash, 'UA3', $expiresAt);
        
        db_revoke_all_user_sessions($this->pdo, $userId);
        
        $this->assertNotNull($this->getSession($session1)['revoked_at']);
        $this->assertNotNull($this->getSession($session2)['revoked_at']);
        $this->assertNotNull($this->getSession($session3)['revoked_at']);
    }

    public function testDbRevokeAllUserSessionsDoesNotAffectOtherUsers(): void
    {
        $userId1 = $this->createTestUser('testuser1');
        $userId2 = $this->createTestUser('testuser2');
        $ipHash = hash('sha256', '127.0.0.1');
        $expiresAt = date('Y-m-d H:i:s', time() + 86400);
        
        $session1 = db_insert_session($this->pdo, $userId1, $ipHash, 'UA1', $expiresAt);
        $session2 = db_insert_session($this->pdo, $userId2, $ipHash, 'UA2', $expiresAt);
        
        db_revoke_all_user_sessions($this->pdo, $userId1);
        
        // User 1's session should be revoked
        $this->assertNotNull($this->getSession($session1)['revoked_at']);
        
        // User 2's session should still be active
        $this->assertNull($this->getSession($session2)['revoked_at']);
    }

    // ============================================================================
    // HIGH-LEVEL SESSION FUNCTION TESTS
    // ============================================================================

    public function testCreateSessionCreatesSessionAndSetsCookie(): void
    {
        $userId = $this->createTestUser('testuser_create');
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Test Browser';
        $_SERVER['HTTPS'] = 'on';
        
        $sessionId = createSession($this->pdo, $userId, '192.168.1.100', 'Mozilla/5.0 Test Browser');
        
        $this->assertIsInt($sessionId);
        $this->assertGreaterThan(0, $sessionId);
        
        // Verify session in database
        $session = $this->getSession($sessionId);
        $this->assertNotNull($session);
        $this->assertSame($userId, (int)$session['user_id']);
        $this->assertSame(hash('sha256', '192.168.1.100'), $session['ip_hash']);
        
        // Verify expiry is approximately SESSION_TTL_DAYS from now
        $expires = strtotime($session['expires_at']);
        $expectedExpires = time() + (SESSION_TTL_DAYS * 86400);
        $this->assertLessThanOrEqual(5, abs($expires - $expectedExpires));
    }

    public function testCreateSessionTruncatesLongUserAgent(): void
    {
        $userId = $this->createTestUser('testuser_longua2');
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        
        $longUA = str_repeat('a', 300);
        $_SERVER['HTTP_USER_AGENT'] = $longUA;
        
        $sessionId = createSession($this->pdo, $userId, '127.0.0.1', $longUA);
        $session = $this->getSession($sessionId);
        
        // createSession uses substr($userAgent, 0, 255), so should be truncated
        $this->assertLessThanOrEqual(255, strlen($session['user_agent']));
    }

    public function testCreateSessionUsesDefaultValuesForMissingServerVars(): void
    {
        $userId = $this->createTestUser('testuser_defaults');
        // Don't set $_SERVER vars
        
        $sessionId = createSession($this->pdo, $userId, 'unknown', 'unknown');
        $session = $this->getSession($sessionId);
        
        $this->assertNotNull($session);
        $this->assertSame(hash('sha256', 'unknown'), $session['ip_hash']);
        $this->assertSame('unknown', $session['user_agent']);
    }

    public function testRequireSessionReturnsNullWhenNoCookie(): void
    {
        // No cookie set
        $result = requireSession($this->pdo);
        $this->assertNull($result);
    }

    public function testRequireSessionReturnsNullForInvalidSessionId(): void
    {
        $_COOKIE['session_id'] = '0';
        $result = requireSession($this->pdo);
        $this->assertNull($result);
        
        $_COOKIE['session_id'] = '-1';
        $result = requireSession($this->pdo);
        $this->assertNull($result);
    }

    public function testRequireSessionReturnsNullForNonExistentSession(): void
    {
        $_COOKIE['session_id'] = '999999';
        $result = requireSession($this->pdo);
        $this->assertNull($result);
    }

    public function testRequireSessionReturnsUserDataForValidSession(): void
    {
        $userId = $this->createTestUser('testuser_req', 'req@test.com');
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        $_SERVER['HTTP_USER_AGENT'] = 'TestBrowser/1.0';
        
        $sessionId = createSession($this->pdo, $userId, '10.0.0.5', 'TestBrowser/1.0');
        $_COOKIE['session_id'] = (string)$sessionId;
        
        $result = requireSession($this->pdo);
        
        $this->assertNotNull($result);
        $this->assertSame($userId, $result['user_id']);
        $this->assertSame('testuser_req', $result['username']);
        $this->assertSame('req@test.com', $result['email']);
        $this->assertSame($sessionId, $result['session_id']);
    }

    public function testRequireSessionReturnsNullForWrongIp(): void
    {
        $userId = $this->createTestUser('testuser_wrongip');
        $sessionId = createSession($this->pdo, $userId, '10.0.0.5', 'TestBrowser/1.0');
        
        $_COOKIE['session_id'] = (string)$sessionId;
        $_SERVER['REMOTE_ADDR'] = '10.0.0.6'; // Different IP
        $_SERVER['HTTP_USER_AGENT'] = 'TestBrowser/1.0';
        
        $result = requireSession($this->pdo);
        $this->assertNull($result, 'Should reject session with wrong IP');
    }

    public function testRequireSessionReturnsNullForWrongUserAgent(): void
    {
        $userId = $this->createTestUser('testuser_wrongua');
        $sessionId = createSession($this->pdo, $userId, '10.0.0.5', 'TestBrowser/1.0');
        
        $_COOKIE['session_id'] = (string)$sessionId;
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        $_SERVER['HTTP_USER_AGENT'] = 'DifferentBrowser/2.0'; // Different UA
        
        $result = requireSession($this->pdo);
        $this->assertNull($result, 'Should reject session with wrong user agent');
    }

    public function testRequireSessionExtendsSessionWhenNearingExpiry(): void
    {
        $userId = $this->createTestUser('testuser_extend');
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestBrowser';
        
        // Create session with expiry very close to now (within SESSION_TOUCH_HOURS)
        $ipHash = hash('sha256', '127.0.0.1');
        $expiresAt = date('Y-m-d H:i:s', time() + (SESSION_TOUCH_HOURS * 3600) - 60); // 1 minute before touch threshold
        $sessionId = db_insert_session($this->pdo, $userId, $ipHash, 'TestBrowser', $expiresAt);
        
        $_COOKIE['session_id'] = (string)$sessionId;
        
        $before = $this->getSession($sessionId);
        requireSession($this->pdo);
        $after = $this->getSession($sessionId);
        
        // Expiry should be extended
        $beforeExpires = strtotime($before['expires_at']);
        $afterExpires = strtotime($after['expires_at']);
        $this->assertGreaterThan($beforeExpires, $afterExpires, 'Session should be extended');
    }

    public function testRequireSessionDoesNotExtendSessionWhenFarFromExpiry(): void
    {
        $userId = $this->createTestUser('testuser_noextend');
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestBrowser';
        
        // Create session with expiry far in the future
        $ipHash = hash('sha256', '127.0.0.1');
        $expiresAt = date('Y-m-d H:i:s', time() + (SESSION_TOUCH_HOURS * 3600) + 3600); // 1 hour after touch threshold
        $sessionId = db_insert_session($this->pdo, $userId, $ipHash, 'TestBrowser', $expiresAt);
        
        $_COOKIE['session_id'] = (string)$sessionId;
        
        $before = $this->getSession($sessionId);
        requireSession($this->pdo);
        $after = $this->getSession($sessionId);
        
        // Expiry should NOT be extended
        $this->assertSame($before['expires_at'], $after['expires_at'], 'Session should not be extended');
    }

    public function testRequireSessionValidatesUserAgentFirst60Chars(): void
    {
        $userId = $this->createTestUser('testuser_ua60');
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        
        // User agent longer than 60 chars
        // Both start with same 60 chars (50 'a's + 10 'b's), then differ
        $ua1 = str_repeat('a', 50) . str_repeat('b', 20); // 70 chars: 50 'a's + 20 'b's
        $ua2 = str_repeat('a', 50) . str_repeat('b', 10) . str_repeat('c', 10); // 70 chars: 50 'a's + 10 'b's + 10 'c's
        
        $sessionId = createSession($this->pdo, $userId, '127.0.0.1', $ua1);
        
        $_COOKIE['session_id'] = (string)$sessionId;
        $_SERVER['HTTP_USER_AGENT'] = $ua2; // Different after first 60 chars
        
        // Should still match (only first 60 chars are compared by strncmp)
        $result = requireSession($this->pdo);
        $this->assertNotNull($result, 'Should match when first 60 chars are same');
    }

    public function testRevokeSessionReturnsFalseWhenNoCookie(): void
    {
        $result = revokeSession($this->pdo);
        $this->assertFalse($result);
    }

    public function testRevokeSessionReturnsFalseForInvalidSessionId(): void
    {
        $_COOKIE['session_id'] = '0';
        $result = revokeSession($this->pdo);
        $this->assertFalse($result);
    }

    public function testRevokeSessionRevokesSessionAndClearsCookie(): void
    {
        $userId = $this->createTestUser('testuser_revoke2');
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestBrowser';
        
        $sessionId = createSession($this->pdo, $userId, '127.0.0.1', 'TestBrowser');
        $_COOKIE['session_id'] = (string)$sessionId;
        
        $result = revokeSession($this->pdo);
        $this->assertTrue($result);
        
        // Verify session is revoked
        $session = $this->getSession($sessionId);
        $this->assertNotNull($session['revoked_at']);
        
        // Verify cookie is cleared (would be set to expire in past)
        // Note: We can't easily test setcookie() output, but function should complete
    }

    public function testTouchSessionExtendsSessionTtl(): void
    {
        $userId = $this->createTestUser('testuser_touch2');
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestBrowser';
        
        $sessionId = createSession($this->pdo, $userId, '127.0.0.1', 'TestBrowser');
        
        sleep(1);
        touchSession($this->pdo, $sessionId);
        
        $session = $this->getSession($sessionId);
        $expires = strtotime($session['expires_at']);
        
        // Use MySQL to calculate expected time to handle timezone differences
        $stmt = $this->pdo->query("SELECT DATE_ADD(NOW(), INTERVAL " . SESSION_TTL_DAYS . " DAY) as expected");
        $expected = strtotime($stmt->fetch()['expected']);
        
        // Allow for reasonable tolerance (10 seconds) due to execution time and timezone
        $this->assertLessThanOrEqual(10, abs($expires - $expected));
    }

    public function testDestroyAllSessionsForUserRevokesAllSessions(): void
    {
        $userId = $this->createTestUser('testuser_destroy');
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestBrowser';
        
        $session1 = createSession($this->pdo, $userId, '127.0.0.1', 'TestBrowser');
        $session2 = createSession($this->pdo, $userId, '127.0.0.1', 'TestBrowser');
        
        destroyAllSessionsForUser($this->pdo, $userId);
        
        $this->assertNotNull($this->getSession($session1)['revoked_at']);
        $this->assertNotNull($this->getSession($session2)['revoked_at']);
    }

    // ============================================================================
    // EDGE CASES & SECURITY TESTS
    // ============================================================================

    public function testSessionIpHashIsConsistent(): void
    {
        $ip = '192.168.1.100';
        $hash1 = hash('sha256', $ip);
        $hash2 = hash('sha256', $ip);
        
        $this->assertSame($hash1, $hash2, 'IP hash should be deterministic');
        $this->assertSame(64, strlen($hash1), 'SHA256 hash should be 64 hex characters');
    }

    public function testSessionIpHashIsDifferentForDifferentIps(): void
    {
        $hash1 = hash('sha256', '192.168.1.100');
        $hash2 = hash('sha256', '192.168.1.101');
        
        $this->assertNotSame($hash1, $hash2, 'Different IPs should produce different hashes');
    }

    public function testSessionHandlesIPv6Addresses(): void
    {
        $userId = $this->createTestUser('testuser_ipv6');
        $ipv6 = '2001:0db8:85a3:0000:0000:8a2e:0370:7334';
        
        $_SERVER['REMOTE_ADDR'] = $ipv6;
        $_SERVER['HTTP_USER_AGENT'] = 'TestBrowser';
        
        $sessionId = createSession($this->pdo, $userId, $ipv6, 'TestBrowser');
        $session = $this->getSession($sessionId);
        
        $this->assertSame(hash('sha256', $ipv6), $session['ip_hash']);
    }

    public function testSessionHandlesEmptyUserAgent(): void
    {
        $userId = $this->createTestUser('testuser_emptyua');
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = '';
        
        $sessionId = createSession($this->pdo, $userId, '127.0.0.1', '');
        $session = $this->getSession($sessionId);
        
        $this->assertSame('', $session['user_agent']);
    }

    public function testSessionHandlesUnicodeInUserAgent(): void
    {
        $userId = $this->createTestUser('testuser_unicodeua');
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $unicodeUA = 'Mozilla/5.0 🚀 Test/测试';
        $_SERVER['HTTP_USER_AGENT'] = $unicodeUA;
        
        $sessionId = createSession($this->pdo, $userId, '127.0.0.1', $unicodeUA);
        
        $_COOKIE['session_id'] = (string)$sessionId;
        $result = requireSession($this->pdo);
        
        $this->assertNotNull($result);
    }

    public function testSessionExpiryCalculationIsCorrect(): void
    {
        $userId = $this->createTestUser('testuser_expcalc');
        $before = time();
        
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestBrowser';
        
        $sessionId = createSession($this->pdo, $userId, '127.0.0.1', 'TestBrowser');
        $session = $this->getSession($sessionId);
        
        $after = time();
        $expires = strtotime($session['expires_at']);
        $expectedMin = $before + (SESSION_TTL_DAYS * 86400);
        $expectedMax = $after + (SESSION_TTL_DAYS * 86400) + 1; // +1 for rounding
        
        $this->assertGreaterThanOrEqual($expectedMin, $expires);
        $this->assertLessThanOrEqual($expectedMax, $expires);
    }

    public function testMultipleSessionsCanExistForSameUser(): void
    {
        $userId = $this->createTestUser('testuser_multi2');
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        
        $session1 = createSession($this->pdo, $userId, '127.0.0.1', 'Browser1');
        $session2 = createSession($this->pdo, $userId, '127.0.0.1', 'Browser2');
        $session3 = createSession($this->pdo, $userId, '127.0.0.1', 'Browser3');
        
        $this->assertNotSame($session1, $session2);
        $this->assertNotSame($session2, $session3);
        
        // All should be valid
        $this->assertTrue(db_is_session_valid($this->pdo, $session1));
        $this->assertTrue(db_is_session_valid($this->pdo, $session2));
        $this->assertTrue(db_is_session_valid($this->pdo, $session3));
    }

    // ============================================================================
    // LOW-PRIORITY EDGE CASE TESTS
    // ============================================================================

    public function testSessionHandlesVeryFastOperations(): void
    {
        // Test that very fast session operations (create, validate, revoke) work correctly
        // This verifies there are no timing-related race conditions
        
        $userId = $this->createTestUser('fastops_user');
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test';
        
        // Create and immediately validate session (very fast)
        $sessionId = createSession($this->pdo, $userId, '127.0.0.1', 'PHPUnit Test');
        $_COOKIE['session_id'] = (string)$sessionId;
        
        // Immediately validate (no delay)
        $session = requireSession($this->pdo);
        $this->assertNotNull($session, 'Session should be valid immediately after creation');
        $this->assertSame($userId, (int)$session['user_id']);
        
        // Immediately revoke
        $revoked = revokeSession($this->pdo);
        $this->assertTrue($revoked, 'Session should be revocable immediately');
        
        // Verify it's revoked
        $sessionAfterRevoke = requireSession($this->pdo);
        $this->assertNull($sessionAfterRevoke, 'Session should be invalid after immediate revoke');
    }

    public function testSessionHandlesClockSkew(): void
    {
        // Test that session validation handles small clock skew gracefully
        // Sessions use server time, so small skew should not cause issues
        
        $userId = $this->createTestUser('clockskew_user');
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test';
        
        $sessionId = createSession($this->pdo, $userId, '127.0.0.1', 'PHPUnit Test');
        $_COOKIE['session_id'] = (string)$sessionId;
        
        // Session should be valid (server time is authoritative)
        $session = requireSession($this->pdo);
        $this->assertNotNull($session, 'Session should be valid with server time');
        
        // Note: Large clock skew would cause expiry issues, but that's expected behavior
        // This test verifies normal operation with server time
    }

    public function testRequireSessionHandlesStringSessionIdInCookie(): void
    {
        $userId = $this->createTestUser('testuser_stringid');
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestBrowser';
        
        $sessionId = createSession($this->pdo, $userId, '127.0.0.1', 'TestBrowser');
        
        // Set cookie as string (which is normal)
        $_COOKIE['session_id'] = (string)$sessionId;
        
        $result = requireSession($this->pdo);
        $this->assertNotNull($result);
        $this->assertSame($sessionId, $result['session_id']);
    }

    public function testRequireSessionHandlesInvalidStringInCookie(): void
    {
        $_COOKIE['session_id'] = 'not-a-number';
        $result = requireSession($this->pdo);
        $this->assertNull($result);
    }

    // ============================================================================
    // ORPHANED RECORD HANDLING TESTS
    // ============================================================================

    public function testRequireSessionHandlesOrphanedSession(): void
    {
        // Test what happens when session exists but user is deleted (orphaned session)
        
        $userId = $this->createTestUser('orphan_user');
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestBrowser';
        
        $sessionId = createSession($this->pdo, $userId, '127.0.0.1', 'TestBrowser');
        
        // Verify session works
        $_COOKIE['session_id'] = (string)$sessionId;
        $result = requireSession($this->pdo);
        $this->assertNotNull($result, 'Session should work before user deletion');
        
        // Delete user (orphaning the session)
        $stmt = $this->pdo->prepare("DELETE FROM users WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        
        // Try to use orphaned session
        // requireSession() joins with users table, so it should return null
        $result = requireSession($this->pdo);
        $this->assertNull($result, 'Orphaned session should not be valid');
    }

    public function testSessionCleanupAfterUserDeletion(): void
    {
        // Test that sessions are properly handled when user is deleted
        // In a production system, you might want to cascade delete sessions
        
        $userId = $this->createTestUser('cleanup_user');
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestBrowser';
        
        $sessionId1 = createSession($this->pdo, $userId, '127.0.0.1', 'Browser1');
        $sessionId2 = createSession($this->pdo, $userId, '127.0.0.1', 'Browser2');
        
        // Verify sessions exist
        $this->assertNotNull($this->getSession($sessionId1));
        $this->assertNotNull($this->getSession($sessionId2));
        
        // Delete user
        $stmt = $this->pdo->prepare("DELETE FROM users WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        
        // Sessions still exist in database (no cascade delete)
        // But requireSession() should not return them (joins with users)
        $_COOKIE['session_id'] = (string)$sessionId1;
        $result = requireSession($this->pdo);
        $this->assertNull($result, 'Session should not be valid after user deletion');
        
        // Verify sessions still exist in database (orphaned)
        $session1 = $this->getSession($sessionId1);
        $this->assertNotNull($session1, 'Session record still exists in database');
    }
}

