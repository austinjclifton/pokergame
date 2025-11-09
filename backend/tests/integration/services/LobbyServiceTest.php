<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LobbyService functions.
 *
 * Tests the LobbyService business logic layer including:
 *  - lobby_get_online_players() - Authentication, username escaping, empty lobby
 *  - lobby_get_recent_messages() - Message retrieval, escaping, limits
 *  - lobby_record_message() - Message recording, validation, canonicalization
 *
 * Uses the actual MySQL database for integration testing.
 * Tests use transactions for isolation - each test runs in a transaction
 * that is rolled back in tearDown to ensure test isolation.
 *
 * @coversNothing
 */
final class LobbyServiceTest extends TestCase
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

        require_once __DIR__ . '/../../../app/services/LobbyService.php';
        require_once __DIR__ . '/../../../app/db/presence.php';
        require_once __DIR__ . '/../../../app/db/chat_messages.php';
        require_once __DIR__ . '/../../../app/db/users.php';
        require_once __DIR__ . '/../../../app/db/sessions.php';
        require_once __DIR__ . '/../../../lib/session.php';
        require_once __DIR__ . '/../../../lib/security.php';
        
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        $this->pdo->beginTransaction();
        $this->inTransaction = true;
        
        // Clear presence records for test isolation
        $this->pdo->exec("DELETE FROM user_lobby_presence");
        
        // Clear $_COOKIE and $_SERVER for isolated tests
        $_COOKIE = [];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test';
    }

    protected function tearDown(): void
    {
        if ($this->inTransaction && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
            $this->inTransaction = false;
        }
        $_COOKIE = [];
    }

    /**
     * Helper: Create a test user and return user ID.
     */
    private function createTestUser(string $username, ?string $email = null): int
    {
        // Make username unique to avoid conflicts
        $uniqueUsername = $username . '_' . time() . '_' . uniqid();
        $email = $email ?? ($uniqueUsername . '@test.com');
        $passwordHash = password_hash('testpass123', PASSWORD_DEFAULT);

        $stmt = $this->pdo->prepare("
            INSERT INTO users (username, email, password_hash)
            VALUES (:username, :email, :password_hash)
        ");
        $stmt->execute([
            'username' => $uniqueUsername,
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
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
        $ipHash = hash('sha256', '127.0.0.1');
        return \db_insert_session($this->pdo, $userId, $ipHash, 'PHPUnit Test', $expiresAt);
    }

    /**
     * Helper: Set session cookie for requireSession().
     */
    private function setSessionCookie(int $sessionId): void
    {
        $_COOKIE['session_id'] = (string)$sessionId;
    }

    /**
     * Helper: Get username from database.
     */
    private function getUsernameFromDb(int $userId): string
    {
        $stmt = $this->pdo->prepare("SELECT username FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['username'] : "User#$userId";
    }

    // ============================================================================
    // lobby_get_online_players() TESTS
    // ============================================================================

    public function testGetOnlinePlayersRequiresAuthentication(): void
    {
        // No session cookie set
        $_COOKIE = [];
        
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UNAUTHORIZED');
        
        lobby_get_online_players($this->pdo);
    }

    public function testGetOnlinePlayersReturnsEmptyArrayWhenNoUsersOnline(): void
    {
        $userId = $this->createTestUser('testuser');
        $sessionId = $this->createTestSession($userId);
        $this->setSessionCookie($sessionId);
        
        $result = lobby_get_online_players($this->pdo);
        
        $this->assertTrue($result['ok']);
        $this->assertIsArray($result['players']);
        $this->assertEmpty($result['players']);
    }

    public function testGetOnlinePlayersReturnsOnlineUsers(): void
    {
        $user1Id = $this->createTestUser('alice');
        $user2Id = $this->createTestUser('bob');
        $user3Id = $this->createTestUser('charlie');
        
        // Mark users as online
        \db_upsert_presence($this->pdo, $user1Id, 'alice', 'online');
        \db_upsert_presence($this->pdo, $user2Id, 'bob', 'online');
        // Don't mark charlie as online
        
        $sessionId = $this->createTestSession($user1Id);
        $this->setSessionCookie($sessionId);
        
        $result = lobby_get_online_players($this->pdo);
        
        $this->assertTrue($result['ok']);
        $this->assertCount(2, $result['players']);
        
        // Verify structure
        $usernames = array_column($result['players'], 'user_username');
        $this->assertContains('alice', $usernames);
        $this->assertContains('bob', $usernames);
        $this->assertNotContains('charlie', $usernames);
    }

    public function testGetOnlinePlayersEscapesUsernames(): void
    {
        $userId = $this->createTestUser('<script>alert(1)</script>');
        \db_upsert_presence($this->pdo, $userId, '<script>alert(1)</script>', 'online');
        
        $sessionId = $this->createTestSession($userId);
        $this->setSessionCookie($sessionId);
        
        $result = lobby_get_online_players($this->pdo);
        
        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['players']);
        
        $username = $result['players'][0]['user_username'];
        $this->assertStringNotContainsString('<script>', $username);
        $this->assertStringContainsString('&lt;script&gt;', $username);
    }

    // ============================================================================
    // lobby_get_recent_messages() TESTS
    // ============================================================================

    public function testGetRecentMessagesReturnsEmptyArrayWhenNoMessages(): void
    {
        $messages = lobby_get_recent_messages($this->pdo);
        
        $this->assertIsArray($messages);
        $this->assertEmpty($messages);
    }

    public function testGetRecentMessagesReturnsRecentMessages(): void
    {
        $userId = $this->createTestUser('testuser');
        
        // Insert messages
        \db_insert_chat_message($this->pdo, 'lobby', 0, $userId, 'Hello world', null, 'testuser');
        \db_insert_chat_message($this->pdo, 'lobby', 0, $userId, 'Second message', null, 'testuser');
        
        $messages = lobby_get_recent_messages($this->pdo);
        
        $this->assertCount(2, $messages);
        $this->assertEquals('Hello world', $messages[0]['msg']);
        $this->assertEquals('Second message', $messages[1]['msg']);
    }

    public function testGetRecentMessagesRespectsLimit(): void
    {
        $userId = $this->createTestUser('testuser');
        
        // Insert 25 messages
        for ($i = 1; $i <= 25; $i++) {
            \db_insert_chat_message($this->pdo, 'lobby', 0, $userId, "Message $i", null, 'testuser');
        }
        
        $messages = lobby_get_recent_messages($this->pdo, 10);
        
        $this->assertCount(10, $messages);
    }

    public function testGetRecentMessagesEscapesContent(): void
    {
        $userId = $this->createTestUser('testuser');
        
        \db_insert_chat_message($this->pdo, 'lobby', 0, $userId, '<img src=x onerror="alert(1)">', null, 'testuser');
        
        $messages = lobby_get_recent_messages($this->pdo);
        
        $this->assertCount(1, $messages);
        $this->assertStringNotContainsString('<img', $messages[0]['msg']);
        $this->assertStringContainsString('&lt;img', $messages[0]['msg']);
    }

    public function testGetRecentMessagesEscapesUsernames(): void
    {
        $userId = $this->createTestUser('test<script>user</script>');
        
        \db_insert_chat_message($this->pdo, 'lobby', 0, $userId, 'Hello', null, 'test<script>user</script>');
        
        $messages = lobby_get_recent_messages($this->pdo);
        
        $this->assertCount(1, $messages);
        $this->assertStringNotContainsString('<script>', $messages[0]['from']);
        $this->assertStringContainsString('&lt;script&gt;', $messages[0]['from']);
    }

    public function testGetRecentMessagesFormatsTimeCorrectly(): void
    {
        $userId = $this->createTestUser('testuser');
        
        \db_insert_chat_message($this->pdo, 'lobby', 0, $userId, 'Hello', null, 'testuser');
        
        $messages = lobby_get_recent_messages($this->pdo);
        
        $this->assertCount(1, $messages);
        $this->assertArrayHasKey('time', $messages[0]);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', $messages[0]['time']);
    }

    // ============================================================================
    // lobby_record_message() TESTS
    // ============================================================================

    public function testRecordMessageRejectsEmptyMessage(): void
    {
        $userId = $this->createTestUser('testuser');
        
        $this->expectException(InvalidArgumentException::class);
        
        lobby_record_message($this->pdo, $userId, '');
    }

    public function testRecordMessageRejectsWhitespaceOnly(): void
    {
        $userId = $this->createTestUser('testuser');
        
        $this->expectException(InvalidArgumentException::class);
        
        lobby_record_message($this->pdo, $userId, '   ');
    }

    public function testRecordMessageStoresMessage(): void
    {
        $userId = $this->createTestUser('testuser');
        $expectedUsername = $this->getUsernameFromDb($userId);
        
        $result = lobby_record_message($this->pdo, $userId, 'Hello world');
        
        $this->assertIsArray($result);
        $this->assertEquals('Hello world', $result['body']);
        // Username should be canonicalized (lowercase)
        $this->assertEquals(strtolower($expectedUsername), $result['sender_username']);
    }

    public function testRecordMessageTrimsWhitespace(): void
    {
        $userId = $this->createTestUser('testuser');
        
        $result = lobby_record_message($this->pdo, $userId, '  Hello world  ');
        
        $this->assertEquals('Hello world', $result['body']);
    }

    public function testRecordMessageCanonicalizesUsername(): void
    {
        // Create user with mixed case - use unique email to avoid conflicts
        $uniqueEmail = 'testuser_' . time() . '_' . uniqid() . '@test.com';
        $stmt = $this->pdo->prepare("
            INSERT INTO users (username, email, password_hash)
            VALUES (:username, :email, :password_hash)
        ");
        $stmt->execute([
            'username' => 'TestUser' . time() . '_' . uniqid(),
            'email' => $uniqueEmail,
            'password_hash' => password_hash('testpass123', PASSWORD_DEFAULT),
        ]);
        $userId = (int)$this->pdo->lastInsertId();
        $originalUsername = $this->getUsernameFromDb($userId);
        
        $result = lobby_record_message($this->pdo, $userId, 'Hello');
        
        // Username should be canonicalized (lowercase)
        $this->assertEquals(strtolower($originalUsername), $result['sender_username']);
        // Verify it's actually lowercased (if original had uppercase)
        if (preg_match('/[A-Z]/', $originalUsername)) {
            $this->assertNotEquals($originalUsername, $result['sender_username']);
        }
    }

    public function testRecordMessageReturnsMostRecentMessage(): void
    {
        $userId = $this->createTestUser('testuser');
        $username = $this->getUsernameFromDb($userId);
        
        // Insert first message
        \db_insert_chat_message($this->pdo, 'lobby', 0, $userId, 'First message', null, $username);
        
        // Record second message
        $result = lobby_record_message($this->pdo, $userId, 'Second message');
        
        $this->assertEquals('Second message', $result['body']);
    }

    public function testRecordMessageHandlesNonExistentUser(): void
    {
        // Use a non-existent user ID
        $nonExistentUserId = 99999;
        
        $result = lobby_record_message($this->pdo, $nonExistentUserId, 'Hello');
        
        $this->assertIsArray($result);
        // Username will be canonicalized (lowercase 'user')
        $this->assertEquals('user#99999', $result['sender_username']);
        $this->assertEquals('Hello', $result['body']);
    }
}

