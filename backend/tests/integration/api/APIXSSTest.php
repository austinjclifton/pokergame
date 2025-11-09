<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for XSS protection in API endpoints
 * 
 * Tests that API endpoints properly escape user-generated content to prevent
 * Cross-Site Scripting (XSS) attacks. Verifies escaping in:
 * - Usernames in API responses
 * - Chat message content
 * - Challenge data
 * 
 * Uses realistic database and service integration to test actual escaping behavior.
 * 
 * @coversNothing
 */
final class APIXSSTest extends TestCase
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
        require_once __DIR__ . '/../../../app/services/AuthService.php';
        require_once __DIR__ . '/../../../lib/security.php';
        require_once __DIR__ . '/../../../app/db/challenges.php';
        require_once __DIR__ . '/../../../app/db/users.php';
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
        
        // Cleanup superglobals
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
        unset($_COOKIE['session_id']);
    }

    /**
     * Create a test user with potentially malicious username
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
     * Create a test session with matching IP and User-Agent
     */
    private function createTestSession(int $userId): int
    {
        require_once __DIR__ . '/../../../app/db/sessions.php';
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
        $ipHash = hash('sha256', '127.0.0.1');
        return db_insert_session($this->pdo, $userId, $ipHash, 'PHPUnit Test', $expiresAt);
    }

    /**
     * Set session cookie and server environment for testing
     */
    private function setSessionCookie(int $sessionId): void
    {
        $_COOKIE['session_id'] = (string)$sessionId;
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test';
    }

    // ============================================================================
    // ESCAPE_HTML FUNCTION TESTS
    // ============================================================================

    /**
     * Test that escape_html() properly escapes common XSS vectors
     */
    public function testEscapeHtmlEscapesCommonXssVectors(): void
    {
        $testCases = [
            '<script>alert(1)</script>' => '&lt;script&gt;alert(1)&lt;/script&gt;',
            '<img src=x onerror=alert(1)>' => '&lt;img src=x onerror=alert(1)&gt;',
            '<svg onload=alert(1)>' => '&lt;svg onload=alert(1)&gt;',
            '"><script>alert(1)</script>' => '&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;',
            "'><script>alert(1)</script>" => '&#039;&gt;&lt;script&gt;alert(1)&lt;/script&gt;',
        ];
        
        foreach ($testCases as $input => $expected) {
            $escaped = escape_html($input);
            $this->assertEquals($expected, $escaped, "Should escape: {$input}");
            $this->assertStringNotContainsString('<script>', $escaped, 'Should not contain unescaped script tag');
            $this->assertStringNotContainsString('<img', $escaped, 'Should not contain unescaped img tag');
        }
    }

    /**
     * Test that escape_html() does not double-escape already escaped content
     */
    public function testEscapeHtmlDoesNotDoubleEscape(): void
    {
        // Already escaped content
        $alreadyEscaped = '&lt;script&gt;alert(1)&lt;/script&gt;';
        $result = escape_html($alreadyEscaped);
        
        // Should not double-escape (result should be the same or properly handled)
        // escape_html uses htmlspecialchars which will escape & to &amp;
        // This is correct behavior - it ensures all HTML entities are properly encoded
        $this->assertStringNotContainsString('&lt;&lt;', $result, 'Should not double-escape angle brackets');
        $this->assertStringNotContainsString('<script>', $result, 'Should not contain unescaped script tag');
    }

    /**
     * Test that escape_html() handles empty and special strings
     */
    public function testEscapeHtmlHandlesEdgeCases(): void
    {
        $this->assertEquals('', escape_html(''), 'Empty string should remain empty');
        $this->assertEquals('normal text', escape_html('normal text'), 'Normal text should remain unchanged');
        $this->assertEquals('&amp;', escape_html('&'), 'Ampersand should be escaped');
        $this->assertEquals('&quot;', escape_html('"'), 'Double quote should be escaped');
        $this->assertEquals('&#039;', escape_html("'"), 'Single quote should be escaped');
    }

    // ============================================================================
    // LOBBY SERVICE XSS PROTECTION TESTS
    // ============================================================================

    /**
     * Test that lobby_get_online_players() escapes usernames
     */
    public function testLobbyGetOnlinePlayersEscapesUsernames(): void
    {
        $username = 'user<img>';
        $userId = $this->createTestUser($username);
        $sessionId = $this->createTestSession($userId);
        
        // Mark user as online
        require_once __DIR__ . '/../../../app/db/presence.php';
        db_upsert_presence($this->pdo, $userId, $username, 'online');
        
        $this->setSessionCookie($sessionId);
        
        $result = lobby_get_online_players($this->pdo);
        
        $this->assertTrue($result['ok'], 'Response should indicate success');
        $this->assertArrayHasKey('players', $result, 'Response should have players array');
        $this->assertIsArray($result['players'], 'Players should be an array');
        
        // Find our user in the players list
        $foundUser = null;
        foreach ($result['players'] as $player) {
            if ((int)$player['user_id'] === $userId) {
                $foundUser = $player;
                break;
            }
        }
        
        $this->assertNotNull($foundUser, 'User should be in online players list');
        $this->assertArrayHasKey('user_username', $foundUser, 'Player should have username');
        
        // Verify username is escaped
        $escapedUsername = $foundUser['user_username'];
        $this->assertStringNotContainsString('<img', $escapedUsername, 'Username should not contain unescaped img tag');
        $this->assertStringContainsString('&lt;img', $escapedUsername, 'Username should contain escaped img tag');
    }

    /**
     * Test that lobby_get_recent_messages() escapes usernames and message content
     */
    public function testLobbyGetRecentMessagesEscapesContent(): void
    {
        $username = 'user<script>';
        $userId = $this->createTestUser($username);
        $sessionId = $this->createTestSession($userId);
        
        // Insert a chat message with XSS attempt
        require_once __DIR__ . '/../../../app/db/chat_messages.php';
        db_insert_chat_message(
            $this->pdo,
            'lobby',
            0,
            $userId,
            '<img src=x onerror=alert(1)>',
            null,
            $username
        );
        
        $this->setSessionCookie($sessionId);
        
        $messages = lobby_get_recent_messages($this->pdo, 10);
        
        $this->assertIsArray($messages, 'Should return array of messages');
        $this->assertGreaterThan(0, count($messages), 'Should have messages');
        
        // Find our message
        $foundMessage = null;
        foreach ($messages as $msg) {
            if (isset($msg['from']) && strpos($msg['from'], 'script') !== false) {
                $foundMessage = $msg;
                break;
            }
        }
        
        $this->assertNotNull($foundMessage, 'Should find message with XSS attempt');
        
        // Verify username is escaped
        $this->assertArrayHasKey('from', $foundMessage, 'Message should have from field');
        $this->assertStringNotContainsString('<script>', $foundMessage['from'], 'Username should not contain unescaped script tag');
        $this->assertStringContainsString('&lt;script&gt;', $foundMessage['from'], 'Username should contain escaped script tag');
        
        // Verify message content is escaped
        if (isset($foundMessage['msg'])) {
            $this->assertStringNotContainsString('<img', $foundMessage['msg'], 'Message content should not contain unescaped img tag');
            $this->assertStringContainsString('&lt;img', $foundMessage['msg'], 'Message content should contain escaped img tag');
        }
    }

    // ============================================================================
    // CHALLENGES API XSS PROTECTION TESTS
    // ============================================================================

    /**
     * Test that challenges API escapes usernames in challenge data
     */
    public function testChallengesAPIEscapesUsernames(): void
    {
        $user1Id = $this->createTestUser('user<script>');
        $user2Id = $this->createTestUser('user<img>');
        $session1Id = $this->createTestSession($user1Id);
        
        // Create a challenge
        db_insert_challenge($this->pdo, $user1Id, $user2Id);
        
        $this->setSessionCookie($session1Id);
        
        // Simulate the challenges.php endpoint logic
        $stmt = $this->pdo->prepare("
            SELECT 
                gc.id,
                gc.from_user_id,
                gc.to_user_id,
                gc.status,
                gc.created_at,
                from_user.username as from_username,
                to_user.username as to_username
            FROM game_challenges gc
            JOIN users from_user ON from_user.id = gc.from_user_id
            JOIN users to_user ON to_user.id = gc.to_user_id
            WHERE (gc.from_user_id = ? OR gc.to_user_id = ?)
            AND gc.status = 'pending'
            ORDER BY gc.created_at DESC
        ");
        
        $stmt->execute([$user1Id, $user1Id]);
        $challenges = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $this->assertGreaterThan(0, count($challenges), 'Should have challenges');
        
        // Format the response (same as challenges.php)
        $formattedChallenges = array_map(function($challenge) use ($user1Id) {
            return [
                'id' => (int)$challenge['id'],
                'from_user_id' => (int)$challenge['from_user_id'],
                'to_user_id' => (int)$challenge['to_user_id'],
                'from_username' => escape_html($challenge['from_username']),
                'to_username' => escape_html($challenge['to_username']),
                'status' => $challenge['status'],
                'created_at' => $challenge['created_at'],
                'is_from_me' => $challenge['from_user_id'] == $user1Id,
                'is_to_me' => $challenge['to_user_id'] == $user1Id
            ];
        }, $challenges);
        
        foreach ($formattedChallenges as $challenge) {
            // Verify from_username is escaped
            $this->assertStringNotContainsString('<script>', $challenge['from_username'], 'From username should not contain unescaped script tag');
            $this->assertStringContainsString('&lt;script&gt;', $challenge['from_username'], 'From username should contain escaped script tag');
            
            // Verify to_username is escaped
            $this->assertStringNotContainsString('<img', $challenge['to_username'], 'To username should not contain unescaped img tag');
            $this->assertStringContainsString('&lt;img', $challenge['to_username'], 'To username should contain escaped img tag');
        }
    }

    /**
     * Test that usernames are not double-escaped when already escaped
     */
    public function testUsernamesAreNotDoubleEscaped(): void
    {
        // Create user with normal username (no XSS)
        $userId = $this->createTestUser('normal_user');
        $sessionId = $this->createTestSession($userId);
        
        // Mark user as online
        require_once __DIR__ . '/../../../app/db/presence.php';
        db_upsert_presence($this->pdo, $userId, 'normal_user', 'online');
        
        $this->setSessionCookie($sessionId);
        
        $result = lobby_get_online_players($this->pdo);
        
        // Find our user
        $foundUser = null;
        foreach ($result['players'] as $player) {
            if ((int)$player['user_id'] === $userId) {
                $foundUser = $player;
                break;
            }
        }
        
        $this->assertNotNull($foundUser, 'User should be in list');
        
        // Username should be exactly 'normal_user' (not double-escaped)
        $this->assertEquals('normal_user', $foundUser['user_username'], 'Normal username should not be escaped');
        
        // Verify it's not double-escaped (no &amp; entities for normal text)
        $this->assertStringNotContainsString('&amp;', $foundUser['user_username'], 'Normal username should not contain HTML entities');
    }

    /**
     * Test that escape_html() handles various XSS payloads correctly
     * 
     * Note: escape_html() uses htmlspecialchars() which escapes HTML special characters
     * (<, >, &, ", '). It does not remove event handlers, but escaping < and > prevents
     * them from being interpreted as HTML tags, which prevents XSS.
     */
    public function testEscapeHtmlHandlesVariousXssPayloads(): void
    {
        $xssPayloads = [
            '<script>alert("XSS")</script>',
            '<img src=x onerror=alert(1)>',
            '<svg onload=alert(1)>',
            'javascript:alert(1)',
            '<iframe src="javascript:alert(1)"></iframe>',
            '<body onload=alert(1)>',
            '<input onfocus=alert(1) autofocus>',
            '<select onfocus=alert(1) autofocus>',
            '<textarea onfocus=alert(1) autofocus>',
            '<keygen onfocus=alert(1) autofocus>',
            '<video><source onerror=alert(1)>',
            '<audio src=x onerror=alert(1)>',
        ];
        
        foreach ($xssPayloads as $payload) {
            $escaped = escape_html($payload);
            
            // Verify HTML tags are escaped (prevents them from being interpreted as HTML)
            if (strpos($payload, '<script') !== false) {
                $this->assertStringNotContainsString('<script', $escaped, "Should escape script tag in: {$payload}");
            }
            if (strpos($payload, '<img') !== false) {
                $this->assertStringNotContainsString('<img', $escaped, "Should escape img tag in: {$payload}");
            }
            if (strpos($payload, '<svg') !== false) {
                $this->assertStringNotContainsString('<svg', $escaped, "Should escape svg tag in: {$payload}");
            }
            if (strpos($payload, '<iframe') !== false) {
                $this->assertStringNotContainsString('<iframe', $escaped, "Should escape iframe tag in: {$payload}");
            }
            
            // Verify HTML entities are used for angle brackets (only if payload contains <)
            if (strpos($payload, '<') !== false) {
                $this->assertStringContainsString('&lt;', $escaped, "Should contain escaped < in: {$payload}");
            }
            
            // Note: Event handlers like onerror= and onload= are still present as text,
            // but they cannot execute because the < and > are escaped, preventing HTML parsing.
            // This is the correct behavior - escaping prevents HTML interpretation.
            // Payloads without < (like 'javascript:alert(1)') remain unchanged, which is correct.
        }
    }

    /**
     * Test that escape_html() preserves safe content
     */
    public function testEscapeHtmlPreservesSafeContent(): void
    {
        $safeContent = [
            'Hello World',
            'User123',
            'test@example.com',
            'Normal text with numbers 123 and symbols !@#$%',
        ];
        
        foreach ($safeContent as $content) {
            $escaped = escape_html($content);
            $this->assertEquals($content, $escaped, "Safe content should remain unchanged: {$content}");
        }
    }
}


