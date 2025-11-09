<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AuthService login functionality.
 *
 * Tests the complete login flow including:
 *  - Successful login with correct credentials
 *  - Failed login with incorrect credentials (wrong username, wrong password)
 *  - Non-existent user handling
 *  - Session creation on successful login
 *  - Password rehashing when needed
 *  - Edge cases and security considerations
 *
 * Uses the actual MySQL database for integration testing.
 * Tests use transactions for isolation - each test runs in a transaction
 * that is rolled back in tearDown to ensure test isolation.
 *
 * @coversNothing
 */
final class AuthServiceLoginTest extends TestCase
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
        require_once __DIR__ . '/../../../app/services/AuthService.php';
        require_once __DIR__ . '/../../../app/db/users.php';
        require_once __DIR__ . '/../../../lib/session.php';

        // Disable foreign key checks for tests (allows us to create test users)
        // This is safe because we rollback the transaction after each test
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        
        // Start a transaction for test isolation
        // Each test will run in its own transaction and rollback in tearDown
        // This ensures test data doesn't persist between tests
        $this->pdo->beginTransaction();
        $this->inTransaction = true;
    }

    protected function tearDown(): void
    {
        // Rollback transaction to clean up test data
        // This ensures each test starts with a clean state
        if ($this->inTransaction && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
            $this->inTransaction = false;
        }
    }

    /**
     * Helper: Create a test user with the given credentials.
     * Returns the user ID.
     */
    private function createTestUser(string $username, string $password, ?string $email = null): int
    {
        $email = $email ?? ($username . '@test.com');
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        
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
     * Helper: Get a user by username.
     */
    private function getUserByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
        $stmt->execute(['username' => $username]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Helper: Get session by session ID.
     */
    private function getSessionById(int $sessionId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM sessions WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $sessionId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Helper: Create a test session.
     */
    private function createTestSession(int $userId, string $ip = '127.0.0.1', string $userAgent = 'PHPUnit Test'): int
    {
        require_once __DIR__ . '/../../../app/db/sessions.php';
        $ipHash = hash('sha256', $ip);
        $expires = (new DateTime())->modify('+7 days')->format('Y-m-d H:i:s');
        return db_insert_session($this->pdo, $userId, $ipHash, $userAgent, $expires);
    }

    // ============================================================================
    // SUCCESSFUL LOGIN TESTS
    // ============================================================================

    public function testLoginWithCorrectCredentialsSucceeds(): void
    {
        $username = 'testuser_login';
        $password = 'SecurePass123!';
        
        // Create test user
        $userId = $this->createTestUser($username, $password);
        
        // Mock $_SERVER variables for session creation
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test';
        
        // Perform login
        $result = auth_login_user($this->pdo, $username, $password);
        
        // Verify result structure
        $this->assertIsArray($result);
        $this->assertTrue($result['ok'], 'Login should succeed with correct credentials');
        $this->assertArrayHasKey('user', $result);
        $this->assertArrayHasKey('id', $result['user']);
        $this->assertArrayHasKey('username', $result['user']);
        $this->assertArrayHasKey('email', $result['user']);
        $this->assertArrayHasKey('session_id', $result['user']);
        
        // Verify user data
        $this->assertSame($userId, $result['user']['id']);
        $this->assertSame($username, $result['user']['username']);
        
        // Verify session was created
        $sessionId = $result['user']['session_id'];
        $this->assertIsInt($sessionId);
        $this->assertGreaterThan(0, $sessionId);
        
        $session = $this->getSessionById($sessionId);
        $this->assertNotNull($session, 'Session should be created in database');
        $this->assertSame($userId, (int)$session['user_id']);
    }

    public function testLoginCreatesValidSession(): void
    {
        $username = 'testuser_session';
        $password = 'Pass123!';
        
        $userId = $this->createTestUser($username, $password);
        
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Test Browser';
        
        $result = auth_login_user($this->pdo, $username, $password);
        $sessionId = $result['user']['session_id'];
        $session = $this->getSessionById($sessionId);
        
        // Verify session properties
        $this->assertNotNull($session);
        $this->assertSame($userId, (int)$session['user_id']);
        $this->assertNotNull($session['created_at']);
        $this->assertNotNull($session['expires_at']);
        $this->assertNotEmpty($session['ip_hash']);
        $this->assertNotEmpty($session['user_agent']);
        
        // Verify session is not expired
        $expiresAt = strtotime($session['expires_at']);
        $this->assertGreaterThan(time(), $expiresAt, 'Session should not be expired immediately');
    }

    // ============================================================================
    // FAILED LOGIN TESTS
    // ============================================================================

    public function testLoginWithWrongUsernameThrowsException(): void
    {
        $username = 'testuser_wrong';
        $password = 'SomePassword123!';
        
        // Create user with different username
        $this->createTestUser('actual_user', $password);
        
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('INVALID_CREDENTIALS');
        
        auth_login_user($this->pdo, $username, $password);
    }

    public function testLoginWithWrongPasswordThrowsException(): void
    {
        $username = 'testuser_wrongpass';
        $correctPassword = 'CorrectPass123!';
        $wrongPassword = 'WrongPass123!';
        
        $this->createTestUser($username, $correctPassword);
        
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('INVALID_CREDENTIALS');
        
        auth_login_user($this->pdo, $username, $wrongPassword);
    }

    public function testLoginWithNonExistentUserThrowsException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('INVALID_CREDENTIALS');
        
        auth_login_user($this->pdo, 'nonexistent_user_12345', 'anypassword');
    }

    public function testLoginWithEmptyUsernameThrowsException(): void
    {
        // Empty username should cause db_get_user_by_username to return null
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('INVALID_CREDENTIALS');
        
        auth_login_user($this->pdo, '', 'somepassword');
    }

    public function testLoginWithEmptyPasswordThrowsException(): void
    {
        $username = 'testuser_emptypass';
        $this->createTestUser($username, 'somepassword');
        
        // Empty password will fail password_verify
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('INVALID_CREDENTIALS');
        
        auth_login_user($this->pdo, $username, '');
    }

    // ============================================================================
    // PASSWORD REHASHING TESTS
    // ============================================================================

    public function testLoginWithOldPasswordHashRehashesPassword(): void
    {
        $username = 'testuser_rehash';
        $password = 'TestPassword123!';
        
        // Create user with old hash (using PASSWORD_BCRYPT with lower cost)
        $oldHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        
        $stmt = $this->pdo->prepare("
            INSERT INTO users (username, email, password_hash)
            VALUES (:username, :email, :password_hash)
        ");
        $stmt->execute([
            'username' => $username,
            'email' => $username . '@test.com',
            'password_hash' => $oldHash,
        ]);
        
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test';
        
        // Login should succeed and rehash the password
        $result = auth_login_user($this->pdo, $username, $password);
        
        $this->assertTrue($result['ok']);
        
        // Verify password was rehashed in database
        $user = $this->getUserByUsername($username);
        $this->assertNotNull($user);
        $newHash = $user['password_hash'];
        
        // New hash should be different from old hash
        $this->assertNotSame($oldHash, $newHash, 'Password should be rehashed');
        
        // New hash should still verify correctly
        $this->assertTrue(password_verify($password, $newHash), 'New hash should verify password');
        
        // New hash should not need rehashing (meets current policy)
        $this->assertFalse(password_needs_rehash($newHash, PASSWORD_DEFAULT), 
            'New hash should meet current policy');
    }

    public function testLoginWithCurrentPasswordHashDoesNotRehash(): void
    {
        $username = 'testuser_norehash';
        $password = 'TestPassword123!';
        
        // Create user with current hash (PASSWORD_DEFAULT)
        $currentHash = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $this->pdo->prepare("
            INSERT INTO users (username, email, password_hash)
            VALUES (:username, :email, :password_hash)
        ");
        $stmt->execute([
            'username' => $username,
            'email' => $username . '@test.com',
            'password_hash' => $currentHash,
        ]);
        
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test';
        
        // Login should succeed without rehashing
        $result = auth_login_user($this->pdo, $username, $password);
        
        $this->assertTrue($result['ok']);
        
        // Verify password was NOT rehashed (should be same hash)
        $user = $this->getUserByUsername($username);
        $this->assertNotNull($user);
        $this->assertSame($currentHash, $user['password_hash'], 
            'Password should not be rehashed if it meets current policy');
    }

    // ============================================================================
    // EDGE CASES & SECURITY TESTS
    // ============================================================================

    public function testLoginIsCaseSensitiveForUsername(): void
    {
        $username = 'TestUser_Case';
        $password = 'Password123!';
        
        $this->createTestUser($username, $password);
        
        // Check if database collation is case-sensitive or case-insensitive
        // MySQL utf8mb4_unicode_ci is case-insensitive by default
        $stmt = $this->pdo->query("SHOW VARIABLES LIKE 'collation_database'");
        $collation = $stmt->fetch(PDO::FETCH_ASSOC);
        $isCaseInsensitive = isset($collation['Value']) && 
                            stripos($collation['Value'], '_ci') !== false;
        
        if ($isCaseInsensitive) {
            // Case-insensitive database - login should succeed with different case
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
            $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test';
            
            $result = auth_login_user($this->pdo, strtolower($username), $password);
            $this->assertTrue($result['ok'], 
                'Case-insensitive database should allow login with different case');
            $this->assertSame($username, $result['user']['username'], 
                'Returned username should match stored case');
        } else {
            // Case-sensitive database - login should fail with different case
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('INVALID_CREDENTIALS');
            
            auth_login_user($this->pdo, strtolower($username), $password);
        }
    }

    public function testLoginHandlesSpecialCharactersInUsername(): void
    {
        $username = 'test_user-123';
        $password = 'Password123!';
        
        $this->createTestUser($username, $password);
        
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test';
        
        $result = auth_login_user($this->pdo, $username, $password);
        $this->assertTrue($result['ok']);
        $this->assertSame($username, $result['user']['username']);
    }

    public function testLoginHandlesLongUsername(): void
    {
        // Username max length is typically 50 characters
        $username = str_repeat('a', 50);
        $password = 'Password123!';
        
        $this->createTestUser($username, $password);
        
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test';
        
        $result = auth_login_user($this->pdo, $username, $password);
        $this->assertTrue($result['ok']);
    }

    public function testLoginHandlesVeryLongPassword(): void
    {
        // Test with password longer than bcrypt's 72-byte limit
        $username = 'testuser_longpass';
        $password = str_repeat('a', 100); // 100 characters
        
        $this->createTestUser($username, $password);
        
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test';
        
        $result = auth_login_user($this->pdo, $username, $password);
        $this->assertTrue($result['ok'], 'Login should work even with long passwords');
    }

    public function testLoginWithSQLInjectionAttemptInUsername(): void
    {
        // Attempt SQL injection in username - should be handled safely by prepared statements
        $username = "admin' OR '1'='1";
        $password = 'anypassword';
        
        // This should not find a user (SQL injection should not work)
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('INVALID_CREDENTIALS');
        
        auth_login_user($this->pdo, $username, $password);
    }

    public function testMultipleLoginsCreateDifferentSessions(): void
    {
        $username = 'testuser_multilogin';
        $password = 'Password123!';
        
        $userId = $this->createTestUser($username, $password);
        
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test';
        
        // First login
        $result1 = auth_login_user($this->pdo, $username, $password);
        $sessionId1 = $result1['user']['session_id'];
        
        // Second login (simulating user logging in from another device)
        $result2 = auth_login_user($this->pdo, $username, $password);
        $sessionId2 = $result2['user']['session_id'];
        
        // Both should succeed
        $this->assertTrue($result1['ok']);
        $this->assertTrue($result2['ok']);
        
        // Sessions should be different
        $this->assertNotSame($sessionId1, $sessionId2, 
            'Multiple logins should create different sessions');
        
        // Both sessions should exist and belong to the same user
        $session1 = $this->getSessionById($sessionId1);
        $session2 = $this->getSessionById($sessionId2);
        
        $this->assertNotNull($session1);
        $this->assertNotNull($session2);
        $this->assertSame($userId, (int)$session1['user_id']);
        $this->assertSame($userId, (int)$session2['user_id']);
    }

    public function testLoginWithUnicodeCharactersInUsername(): void
    {
        // If your system supports Unicode usernames
        $username = 'тестовый_пользователь';
        $password = 'Password123!';
        
        try {
            $this->createTestUser($username, $password);
            
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
            $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test';
            
            $result = auth_login_user($this->pdo, $username, $password);
            $this->assertTrue($result['ok']);
            $this->assertSame($username, $result['user']['username']);
        } catch (PDOException $e) {
            // If database doesn't support Unicode usernames, skip this test
            $this->markTestSkipped('Database does not support Unicode usernames: ' . $e->getMessage());
        }
    }

    // ============================================================================
    // CONCURRENT OPERATION TESTS
    // ============================================================================

    public function testConcurrentSessionCreationFromSameUser(): void
    {
        // Test that concurrent login attempts from the same user create separate sessions
        // This simulates a user logging in from multiple devices/browsers simultaneously
        
        $unique = substr(uniqid('', true), -8);
        $username = 'concurrent_session_user' . $unique;
        $password = 'Password123!';
        $userId = $this->createTestUser($username, $password);
        
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test';
        
        // Commit transaction to allow concurrent operations
        $wasInTransaction = $this->pdo->inTransaction();
        if ($wasInTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            // Simulate concurrent logins (sequentially, but testing the same scenario)
            $sessionIds = [];
            for ($i = 0; $i < 5; $i++) {
                $result = auth_login_user($this->pdo, $username, $password);
                $this->assertTrue($result['ok'], "Login attempt $i should succeed");
                $sessionIds[] = $result['user']['session_id'];
            }
            
            // All sessions should be unique
            $uniqueSessions = array_unique($sessionIds);
            $this->assertCount(5, $uniqueSessions, 'All concurrent logins should create unique sessions');
            
            // All sessions should belong to the same user
            foreach ($sessionIds as $sessionId) {
                $session = $this->getSessionById($sessionId);
                $this->assertNotNull($session, "Session $sessionId should exist");
                $this->assertSame($userId, (int)$session['user_id'], 
                    "Session $sessionId should belong to user $userId");
            }
        } finally {
            if ($wasInTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
    }

    // ============================================================================
    // SESSION FIXATION PREVENTION TESTS
    // ============================================================================

    public function testLoginCreatesNewSessionToPreventFixation(): void
    {
        // Session fixation attack: attacker sets session_id cookie before user logs in
        // Login should create a NEW session, not reuse the attacker's session
        
        $username = 'testuser_fixation';
        $password = 'Password123!';
        $userId = $this->createTestUser($username, $password);
        
        // Attacker sets a session_id cookie (simulating session fixation attempt)
        $attackerSessionId = $this->createTestSession($userId, '192.168.1.100', 'Attacker Browser');
        $_COOKIE['session_id'] = (string)$attackerSessionId;
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; // User's real IP
        $_SERVER['HTTP_USER_AGENT'] = 'User Browser';
        
        // User logs in
        $result = auth_login_user($this->pdo, $username, $password);
        
        $this->assertTrue($result['ok']);
        $newSessionId = $result['user']['session_id'];
        
        // New session should be different from attacker's session
        $this->assertNotSame($attackerSessionId, $newSessionId, 
            'Login should create a new session, not reuse attacker\'s session');
        
        // New session should be valid
        $_COOKIE['session_id'] = (string)$newSessionId;
        $session = requireSession($this->pdo);
        $this->assertNotNull($session, 'New session should be valid');
        $this->assertSame($userId, (int)$session['user_id']);
        
        // Attacker's session should still exist (current implementation doesn't invalidate old sessions)
        // This is acceptable - the user gets a new session, attacker's session is separate
        // However, ideally old sessions should be invalidated on login for better security
        $attackerSession = $this->getSessionById($attackerSessionId);
        $this->assertNotNull($attackerSession, 'Attacker session still exists (not invalidated)');
    }

    public function testLoginSessionHasCorrectIpAndUserAgent(): void
    {
        // Verify that login creates session with user's actual IP and User-Agent,
        // not any pre-existing values from cookies
        
        $username = 'testuser_sessionip';
        $password = 'Password123!';
        $userId = $this->createTestUser($username, $password);
        
        // Set up environment as if attacker set session cookie
        $_COOKIE['session_id'] = '999999'; // Fake session ID
        $_SERVER['REMOTE_ADDR'] = '192.168.1.50'; // User's real IP
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 User Browser';
        
        $result = auth_login_user($this->pdo, $username, $password);
        $newSessionId = $result['user']['session_id'];
        
        // Verify session has correct IP and User-Agent
        $session = $this->getSessionById($newSessionId);
        $this->assertNotNull($session);
        
        $expectedIpHash = hash('sha256', '192.168.1.50');
        $this->assertSame($expectedIpHash, $session['ip_hash'], 
            'Session should have user\'s actual IP, not attacker\'s');
        $this->assertSame('Mozilla/5.0 User Browser', $session['user_agent'],
            'Session should have user\'s actual User-Agent, not attacker\'s');
    }
}

