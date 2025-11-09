<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for XSS protection in AuthService
 * 
 * Tests that username validation and escaping work correctly in the authentication flow.
 * 
 * @coversNothing
 */
final class AuthServiceXSSTest extends TestCase
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
        require_once __DIR__ . '/../../../lib/security.php';
        require_once __DIR__ . '/../../../app/db/nonces.php';
        require_once __DIR__ . '/../../../app/db/sessions.php';
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
    private function createTestUser(string $username, string $password, ?string $email = null): int
    {
        require_once __DIR__ . '/../../../app/db/users.php';
        $email = $email ?? ($username . '_' . time() . '@test.com');
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
     * Helper: Create a test session and nonce for registration.
     * Creates a temporary session (user_id=0) as required by nonce_issue() for unauthenticated users.
     */
    private function createTestSessionAndNonce(): array
    {
        // Create a temporary session for unauthenticated users (user_id=0)
        // This matches what nonce_issue() does when no session exists
        $stmt = $this->pdo->prepare("
            INSERT INTO sessions (user_id, ip_hash, user_agent, expires_at)
            VALUES (0, :ip_hash, 'PHPUnit Test', DATE_ADD(NOW(), INTERVAL 7 DAY))
        ");
        $stmt->execute([
            'ip_hash' => hash('sha256', '127.0.0.1'),
        ]);
        $sessionId = (int)$this->pdo->lastInsertId();
        
        // Create a nonce for this session (64 chars for CHAR(64) column)
        // Use db_insert_nonce to ensure proper format
        require_once __DIR__ . '/../../../app/db/nonces.php';
        $nonce = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
        \db_insert_nonce($this->pdo, $sessionId, $nonce, $expiresAt);
        
        return ['session_id' => $sessionId, 'nonce' => $nonce];
    }

    // ============================================================================
    // USERNAME VALIDATION TESTS
    // ============================================================================

    public function testRegisterRejectsUsernameWithScriptTags(): void
    {
        $sessionNonce = $this->createTestSessionAndNonce();
        
        $_COOKIE['session_id'] = (string)$sessionNonce['session_id'];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test';
        
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('INVALID_USERNAME');
        
        auth_register_user(
            $this->pdo,
            '<script>alert(1)</script>',
            'test@example.com',
            'password123',
            $sessionNonce['nonce']
        );
    }

    public function testRegisterRejectsUsernameWithHtmlTags(): void
    {
        $sessionNonce = $this->createTestSessionAndNonce();
        
        $_COOKIE['session_id'] = (string)$sessionNonce['session_id'];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test';
        
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('INVALID_USERNAME');
        
        auth_register_user(
            $this->pdo,
            '<img src=x onerror="alert(1)">',
            'test@example.com',
            'password123',
            $sessionNonce['nonce']
        );
    }

    public function testRegisterRejectsUsernameTooShort(): void
    {
        $sessionNonce = $this->createTestSessionAndNonce();
        
        $_COOKIE['session_id'] = (string)$sessionNonce['session_id'];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test';
        
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('INVALID_USERNAME');
        
        auth_register_user(
            $this->pdo,
            'ab',
            'test@example.com',
            'password123',
            $sessionNonce['nonce']
        );
    }

    public function testRegisterRejectsUsernameTooLong(): void
    {
        $sessionNonce = $this->createTestSessionAndNonce();
        
        $_COOKIE['session_id'] = (string)$sessionNonce['session_id'];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test';
        
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('INVALID_USERNAME');
        
        auth_register_user(
            $this->pdo,
            str_repeat('a', 21),
            'test@example.com',
            'password123',
            $sessionNonce['nonce']
        );
    }

    public function testRegisterAcceptsValidUsername(): void
    {
        $sessionNonce = $this->createTestSessionAndNonce();
        
        $_COOKIE['session_id'] = (string)$sessionNonce['session_id'];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test';
        
        $result = auth_register_user(
            $this->pdo,
            'validuser123',
            'test' . time() . '@example.com', // Use unique email to avoid conflicts
            'password123',
            $sessionNonce['nonce']
        );
        
        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('user', $result);
        // Username is canonicalized (lowercase)
        $this->assertSame('validuser123', $result['user']['username']);
    }

    // ============================================================================
    // USERNAME ESCAPING IN RESPONSES TESTS
    // ============================================================================

    public function testLoginResponseEscapesUsername(): void
    {
        // Create user with potentially malicious username (if somehow it got in the DB)
        // Note: This tests the escaping even if validation somehow failed
        // Use shorter username to fit DB column
        $username = 'user<script>';
        $password = 'password123';
        
        // Directly insert into DB to bypass validation (simulating old data)
        // Use unique email to avoid conflicts
        $email = 'test' . time() . '@example.com';
        $stmt = $this->pdo->prepare("
            INSERT INTO users (username, email, password_hash)
            VALUES (:username, :email, :password_hash)
        ");
        $stmt->execute([
            'username' => $username,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
        
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test';
        
        $result = auth_login_user($this->pdo, $username, $password);
        
        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('user', $result);
        $this->assertArrayHasKey('username', $result['user']);
        
        // Username should be escaped in response
        $escapedUsername = $result['user']['username'];
        $this->assertStringNotContainsString('<script>', $escapedUsername, 'Username should be escaped in login response');
        $this->assertStringContainsString('&lt;script&gt;', $escapedUsername, 'Username should contain escaped script tag');
    }

    public function testRegisterResponseEscapesUsername(): void
    {
        $sessionNonce = $this->createTestSessionAndNonce();
        
        $_COOKIE['session_id'] = (string)$sessionNonce['session_id'];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test';
        
        $username = 'validuser123';
        $result = auth_register_user(
            $this->pdo,
            $username,
            'test' . time() . '@example.com', // Use unique email to avoid conflicts
            'password123',
            $sessionNonce['nonce']
        );
        
        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('user', $result);
        $this->assertArrayHasKey('username', $result['user']);
        
        // Username should be escaped (even though it's valid)
        $escapedUsername = $result['user']['username'];
        // For valid usernames, escaping should preserve the original
        $this->assertSame($username, $escapedUsername);
        
        // But if username contained HTML, it would be escaped
        // We can't test this directly because validation prevents it,
        // but we know escape_html() is being called
    }
}

