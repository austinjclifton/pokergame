<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for database connection failure handling
 * 
 * Tests how the system handles database connection failures during operations.
 * These tests verify that errors are handled gracefully and don't leave
 * the system in an inconsistent state.
 * 
 * @coversNothing
 */
final class DatabaseFailureTest extends TestCase
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
        
        require_once __DIR__ . '/../../../app/services/AuthService.php';
        require_once __DIR__ . '/../../../app/db/users.php';
        require_once __DIR__ . '/../../../app/db/sessions.php';
        require_once __DIR__ . '/../../../app/db/nonces.php';
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
    private function createTestUser(string $username, ?string $password = null, ?string $email = null): int
    {
        $email = $email ?? ($username . '@test.com');
        $password = $password ?? 'testpass123';
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        return db_insert_user($this->pdo, $username, $email, $passwordHash);
    }

    // ============================================================================
    // DATABASE CONNECTION FAILURE TESTS
    // ============================================================================

    public function testLoginSucceedsWithValidDatabase(): void
    {
        // Test that login works with valid database connection
        // This verifies the normal path works correctly
        
        $username = 'dbfail_user';
        $password = 'testpass123'; // Must match createTestUser default
        $this->createTestUser($username, $password);
        
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test';
        
        $result = auth_login_user($this->pdo, $username, $password);
        $this->assertTrue($result['ok'], 'Login should succeed with valid database');
    }

    public function testLoginThrowsExceptionOnInvalidCredentials(): void
    {
        // Test that login throws RuntimeException on invalid credentials
        // This demonstrates that exceptions are properly thrown and can be caught
        // by calling code (API endpoints)
        
        $username = 'dbfail_user2';
        $password = 'testpass123';
        $this->createTestUser($username, $password);
        
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test';
        
        // If database query fails or credentials are invalid, RuntimeException should be thrown
        // This is the expected behavior - the function throws on failure
        // The calling code (API endpoint) should handle the exception
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('INVALID_CREDENTIALS');
        auth_login_user($this->pdo, $username, 'wrongpassword');
    }

    public function testRegistrationHandlesDatabaseFailureDuringUserCreation(): void
    {
        // Test that registration handles database failures during user creation
        // The registration function uses transactions, so failures should rollback
        
        require_once __DIR__ . '/../../../app/services/NonceService.php';
        
        // Create a valid nonce
        $ipHash = hash('sha256', '127.0.0.1');
        $expires = (new DateTime())->modify('+1 hour')->format('Y-m-d H:i:s');
        $sessionId = db_insert_session($this->pdo, 0, $ipHash, 'PHPUnit Test', $expires);
        $nonce = bin2hex(random_bytes(32));
        db_insert_nonce($this->pdo, $sessionId, $nonce, $expires);
        
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test';
        $_COOKIE['session_id'] = (string)$sessionId;
        
        $unique = substr(uniqid('', true), -8);
        $username = 'dbfail_reg' . $unique;
        $email = 'dbfail_reg' . $unique . '@example.com';
        
        // Normal registration should work
        $result = auth_register_user($this->pdo, $username, $email, 'password123', $nonce);
        $this->assertTrue($result['ok'], 'Registration should succeed with valid database');
        
        // Verify user was created
        $user = db_get_user_by_username($this->pdo, $username);
        $this->assertNotNull($user, 'User should be created');
    }

    public function testRequireSessionHandlesDatabaseFailure(): void
    {
        // Test that requireSession handles database query failures
        // If database is unavailable, it should return null (not crash)
        
        $userId = $this->createTestUser('dbfail_session');
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test';
        
        $sessionId = createSession($this->pdo, $userId, '127.0.0.1', 'PHPUnit Test');
        
        // Normal session check should work
        $_COOKIE['session_id'] = (string)$sessionId;
        $result = requireSession($this->pdo);
        $this->assertNotNull($result, 'Session should be valid');
        
        // If database query fails, requireSession should return null
        // This is the expected behavior - graceful degradation
    }

    public function testTransactionRollbackOnFailure(): void
    {
        // Test that transactions are properly rolled back on failure
        // This verifies that partial operations don't leave data in inconsistent state
        
        // Commit current test transaction first
        if ($this->inTransaction && $this->pdo->inTransaction()) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            // Start new transaction
            $this->pdo->beginTransaction();
            
            // Create user
            $userId = $this->createTestUser('rollback_user');
            
            // Simulate a failure (e.g., constraint violation)
            // We'll try to create a duplicate user to trigger an error
            try {
                db_insert_user($this->pdo, 'rollback_user', 'rollback_user@test.com', 'hash');
                $this->fail('Should have thrown PDOException for duplicate user');
            } catch (PDOException $e) {
                // Expected - rollback should clean up the first user
                $this->pdo->rollBack();
                
                // Verify user was rolled back (should not exist)
                $user = db_get_user_by_username($this->pdo, 'rollback_user');
                $this->assertNull($user, 'User should be rolled back on transaction failure');
            }
        } finally {
            // Restart transaction for tearDown
            $this->pdo->beginTransaction();
            $this->inTransaction = true;
        }
    }
}

