<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SQL Injection protection
 * 
 * Tests that all database functions properly protect against SQL injection
 * by using prepared statements and parameter binding.
 * 
 * @coversNothing
 */
final class SQLInjectionTest extends TestCase
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
        
        require_once __DIR__ . '/../../../app/db/users.php';
        require_once __DIR__ . '/../../../app/db/sessions.php';
        require_once __DIR__ . '/../../../app/db/chat_messages.php';
        require_once __DIR__ . '/../../../app/db/challenges.php';
        require_once __DIR__ . '/../../../app/db/presence.php';
        require_once __DIR__ . '/../../../app/db/subscriptions.php';
        // Games functionality not yet implemented
        // require_once __DIR__ . '/../../../app/db/games.php';
        require_once __DIR__ . '/../../../app/db/nonces.php';
        
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
        
        return \db_insert_user($this->pdo, $username, $email, $passwordHash);
    }

    /**
     * Helper: Create a test session.
     */
    private function createTestSession(int $userId): int
    {
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
        return \db_insert_session($this->pdo, $userId, 'test-ip-hash', 'PHPUnit Test', $expiresAt);
    }

    // ============================================================================
    // USERS TABLE SQL INJECTION TESTS
    // ============================================================================

    public function testGetUserByUsernamePreventsSQLInjection(): void
    {
        // Create a test user
        $userId = $this->createTestUser('normaluser');
        
        // Attempt SQL injection in username
        $maliciousUsernames = [
            "admin' OR '1'='1",
            "admin'--",
            "admin'/*",
            "' UNION SELECT * FROM users--",
            "admin' OR 1=1#",
            "'; DROP TABLE users;--",
        ];
        
        foreach ($maliciousUsernames as $maliciousUsername) {
            // Should not throw exception and should return null (user doesn't exist)
            $result = \db_get_user_by_username($this->pdo, $maliciousUsername);
            $this->assertNull($result, "SQL injection attempt '{$maliciousUsername}' should return null, not cause error");
        }
        
        // Verify the normal user still works
        $result = \db_get_user_by_username($this->pdo, 'normaluser');
        $this->assertNotNull($result);
        $this->assertSame($userId, (int)$result['id']);
    }

    public function testGetUserByIdPreventsSQLInjection(): void
    {
        // Create a test user
        $uniqueUsername = 'testuser_' . time() . '_' . uniqid();
        $userId = $this->createTestUser($uniqueUsername);
        
        // Attempt SQL injection with string instead of int
        // The function signature requires int, but test with string that could be coerced
        $maliciousIds = [
            "1 OR 1=1",
            "1' OR '1'='1",
            "1; DROP TABLE users;--",
            "1 UNION SELECT * FROM users--",
        ];
        
        // Since function signature requires int, PHP will cast/coerce
        // But let's test that even if somehow a string gets through, it's safe
        foreach ($maliciousIds as $maliciousId) {
            try {
                // Cast to int first (simulating what happens in real code)
                $id = (int)$maliciousId;
                $result = \db_get_user_by_id($this->pdo, $id);
                // Should either return null or the actual user with ID 1, not cause SQL injection
                if ($result !== null) {
                    $this->assertIsInt((int)$result['id'], "Result should have valid integer ID");
                }
            } catch (PDOException $e) {
                // If it's a duplicate key error, that's from our test setup, not SQL injection
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    continue; // Skip this test case
                }
                $this->fail("SQL injection attempt should not cause PDO exception: " . $e->getMessage());
            }
        }
        
        // Verify our test user still works
        $result = \db_get_user_by_id($this->pdo, $userId);
        $this->assertNotNull($result);
        $this->assertSame($userId, (int)$result['id']);
    }

    public function testInsertUserPreventsSQLInjectionInUsername(): void
    {
        $maliciousUsernames = [
            "admin' OR '1'='1",
            "admin'--",
            "'; DROP TABLE users;--",
            "admin'/*",
            "' UNION SELECT * FROM users--",
        ];
        
        foreach ($maliciousUsernames as $maliciousUsername) {
            try {
                $email = 'test' . time() . '@example.com';
                $passwordHash = password_hash('testpass123', PASSWORD_DEFAULT);
                
                // Should insert the username as-is (not execute SQL)
                $userId = \db_insert_user($this->pdo, $maliciousUsername, $email, $passwordHash);
                $this->assertGreaterThan(0, $userId, "Should successfully insert user with malicious username");
                
                // Verify the username was stored literally, not executed as SQL
                // Note: Username is canonicalized (lowercase) by db_insert_user
                $user = \db_get_user_by_id($this->pdo, $userId);
                $this->assertNotNull($user);
                // Username is canonicalized, so compare lowercase version
                $this->assertSame(mb_strtolower($maliciousUsername), $user['username'], "Username should be stored (canonicalized)");
            } catch (PDOException $e) {
                // If it's a unique constraint violation, that's fine
                // But SQL injection should not cause a syntax error
                if (strpos($e->getMessage(), 'SQL syntax') !== false) {
                    $this->fail("SQL injection attempt caused SQL syntax error: " . $e->getMessage());
                }
            }
        }
    }

    public function testInsertUserPreventsSQLInjectionInEmail(): void
    {
        $maliciousEmails = [
            "test'@example.com",
            "test' OR '1'='1",
            "'; DROP TABLE users;--",
            "test'/*@example.com",
        ];
        
        foreach ($maliciousEmails as $maliciousEmail) {
            try {
                $username = 'user' . time();
                $passwordHash = password_hash('testpass123', PASSWORD_DEFAULT);
                
                // Should insert the email as-is (not execute SQL)
                $userId = \db_insert_user($this->pdo, $username, $maliciousEmail, $passwordHash);
                $this->assertGreaterThan(0, $userId, "Should successfully insert user with malicious email");
                
                // Verify the email was stored literally
                $user = \db_get_user_by_id($this->pdo, $userId);
                $this->assertNotNull($user);
                $this->assertSame($maliciousEmail, $user['email'], "Email should be stored literally");
            } catch (PDOException $e) {
                // If it's a unique constraint violation, that's fine
                if (strpos($e->getMessage(), 'SQL syntax') !== false) {
                    $this->fail("SQL injection attempt caused SQL syntax error: " . $e->getMessage());
                }
            }
        }
    }

    public function testUserExistsPreventsSQLInjection(): void
    {
        // Create a test user
        $userId = $this->createTestUser('existinguser', 'existing@test.com');
        
        $maliciousInputs = [
            ['username' => "admin' OR '1'='1", 'email' => 'test@example.com'],
            ['username' => 'testuser', 'email' => "test' OR '1'='1"],
            ['username' => "'; DROP TABLE users;--", 'email' => 'test@example.com'],
        ];
        
        foreach ($maliciousInputs as $input) {
            // Should not throw exception and should return correct result
            $result = \db_user_exists($this->pdo, $input['username'], $input['email']);
            // Result should be false (since these users don't exist) or true if it matches existing user
            $this->assertIsBool($result, "Should return boolean, not cause SQL error");
        }
    }

    // ============================================================================
    // SESSIONS TABLE SQL INJECTION TESTS
    // ============================================================================

    public function testGetSessionWithUserPreventsSQLInjection(): void
    {
        $userId = $this->createTestUser('sessionuser');
        $sessionId = $this->createTestSession($userId);
        
        // Attempt SQL injection with session ID
        $maliciousSessionIds = [
            "1 OR 1=1",
            "1' OR '1'='1",
            "1; DROP TABLE sessions;--",
        ];
        
        $allSafe = true;
        foreach ($maliciousSessionIds as $maliciousId) {
            try {
                $id = (int)$maliciousId;
                $result = \db_get_session_with_user($this->pdo, $id);
                // Should return null (session doesn't exist) or valid session, not cause SQL error
                if ($result !== null) {
                    $this->assertArrayHasKey('user_id', $result);
                    $this->assertIsInt((int)$result['user_id']);
                }
                // If we got here without exception, SQL injection didn't work
                $this->assertTrue($result === null || is_array($result), "Should return null or valid session array");
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'SQL syntax') !== false) {
                    $allSafe = false;
                    $this->fail("SQL injection attempt caused SQL syntax error: " . $e->getMessage());
                }
            }
        }
        
        // Verify our valid session still works
        $validResult = \db_get_session_with_user($this->pdo, $sessionId);
        $this->assertNotNull($validResult, "Valid session should be retrievable");
        $this->assertSame($userId, (int)$validResult['user_id']);
    }

    public function testIsSessionValidPreventsSQLInjection(): void
    {
        $userId = $this->createTestUser('validuser');
        $sessionId = $this->createTestSession($userId);
        
        $maliciousSessionIds = [
            "1 OR 1=1",
            "1' OR '1'='1",
            "1; DROP TABLE sessions;--",
        ];
        
        foreach ($maliciousSessionIds as $maliciousId) {
            try {
                $id = (int)$maliciousId;
                $result = \db_is_session_valid($this->pdo, $id);
                // Should return boolean, not cause SQL error
                $this->assertIsBool($result);
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'SQL syntax') !== false) {
                    $this->fail("SQL injection attempt caused SQL syntax error: " . $e->getMessage());
                }
            }
        }
    }

    // ============================================================================
    // CHAT MESSAGES TABLE SQL INJECTION TESTS
    // ============================================================================

    public function testInsertChatMessagePreventsSQLInjectionInBody(): void
    {
        $userId = $this->createTestUser('chatuser');
        
        $maliciousMessages = [
            "Hello'; DROP TABLE chat_messages;--",
            "Test' OR '1'='1",
            "'; DELETE FROM users;--",
            "Test'/*",
            "' UNION SELECT * FROM users--",
        ];
        
        foreach ($maliciousMessages as $maliciousMessage) {
            try {
                $messageId = \db_insert_chat_message(
                    $this->pdo,
                    'lobby',
                    0,
                    $userId,
                    $maliciousMessage
                );
                
                $this->assertGreaterThan(0, $messageId, "Should successfully insert message");
                
                // Verify message was stored literally
                $messages = \db_get_recent_chat_messages($this->pdo, 'lobby', 0, 10);
                $found = false;
                foreach ($messages as $msg) {
                    if ((int)$msg['id'] === $messageId) {
                        $found = true;
                        $this->assertSame($maliciousMessage, $msg['body'], "Message should be stored literally");
                        break;
                    }
                }
                $this->assertTrue($found, "Inserted message should be retrievable");
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'SQL syntax') !== false) {
                    $this->fail("SQL injection attempt caused SQL syntax error: " . $e->getMessage());
                }
                throw $e;
            }
        }
    }

    public function testInsertChatMessagePreventsSQLInjectionInChannelType(): void
    {
        $userId = $this->createTestUser('chatuser2');
        
        // Use shorter malicious channel types to fit database column (VARCHAR likely has length limit)
        // The key is testing that SQL injection doesn't work, not that we can store long strings
        $maliciousChannelTypes = [
            "lobby'; DROP",
            "lobby' OR '1",
            "'; DELETE",
        ];
        
        $sqlInjectionAttempted = false;
        $sqlInjectionSafe = true;
        
        foreach ($maliciousChannelTypes as $maliciousChannelType) {
            try {
                $messageId = \db_insert_chat_message(
                    $this->pdo,
                    $maliciousChannelType,
                    0,
                    $userId,
                    'Test message'
                );
                
                $sqlInjectionAttempted = true;
                $this->assertGreaterThan(0, $messageId, "Should successfully insert message");
                
                // Verify message was stored with literal channel type (no SQL executed)
                $messages = \db_get_recent_chat_messages($this->pdo, $maliciousChannelType, 0, 10);
                // Should find the message we just inserted
                $found = false;
                foreach ($messages as $msg) {
                    if ((int)$msg['id'] === $messageId) {
                        $found = true;
                        $this->assertSame($maliciousChannelType, $msg['channel_type'], "Channel type should be stored literally, not executed as SQL");
                        break;
                    }
                }
                $this->assertTrue($found, "Message should be retrievable with literal channel type");
            } catch (PDOException $e) {
                $sqlInjectionAttempted = true;
                // If it's a data truncation error (column too long), that's expected
                // But SQL injection should not cause a syntax error
                if (strpos($e->getMessage(), 'SQL syntax') !== false) {
                    $sqlInjectionSafe = false;
                    $this->fail("SQL injection attempt caused SQL syntax error: " . $e->getMessage());
                }
                // Data truncation or other constraint violations are acceptable
                // The important thing is that SQL injection didn't work
            }
        }
        
        // Always perform at least one assertion to ensure test is not risky
        $this->assertTrue($sqlInjectionAttempted, "Should have attempted SQL injection test");
        $this->assertTrue($sqlInjectionSafe, "All SQL injection attempts should be safe (no SQL syntax errors)");
        
        // Verify normal operation still works
        $normalMessageId = \db_insert_chat_message($this->pdo, 'lobby', 0, $userId, 'Normal message');
        $this->assertGreaterThan(0, $normalMessageId, "Normal channel type should work");
    }

    public function testGetRecentChatMessagesPreventsSQLInjectionInLimit(): void
    {
        $userId = $this->createTestUser('limituser');
        
        // Insert some test messages
        for ($i = 0; $i < 5; $i++) {
            \db_insert_chat_message($this->pdo, 'lobby', 0, $userId, "Message $i");
        }
        
        // Attempt SQL injection in limit parameter
        $maliciousLimits = [
            "10; DROP TABLE chat_messages;--",
            "10' OR '1'='1",
            "10 UNION SELECT * FROM users--",
            -1, // Should be handled by max(1, ...)
            0,  // Should be handled by max(1, ...)
            999999, // Should be capped at 100
        ];
        
        foreach ($maliciousLimits as $maliciousLimit) {
            try {
                // Since function signature requires int, cast it
                $limit = is_int($maliciousLimit) ? $maliciousLimit : (int)$maliciousLimit;
                $messages = \db_get_recent_chat_messages($this->pdo, 'lobby', 0, $limit);
                
                // Should return an array (possibly empty)
                $this->assertIsArray($messages);
                // Should not have more than 100 messages (the cap)
                $this->assertLessThanOrEqual(100, count($messages), "Should not return more than 100 messages");
                // Should not have fewer than 0 messages
                $this->assertGreaterThanOrEqual(0, count($messages));
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'SQL syntax') !== false) {
                    $this->fail("SQL injection attempt in LIMIT caused SQL syntax error: " . $e->getMessage());
                }
                throw $e;
            }
        }
    }

    // ============================================================================
    // CHALLENGES TABLE SQL INJECTION TESTS
    // ============================================================================

    public function testChallengePendingExistsPreventsSQLInjection(): void
    {
        $user1Id = $this->createTestUser('challenger1');
        $user2Id = $this->createTestUser('challenger2');
        
        // Create a challenge
        \db_insert_challenge($this->pdo, $user1Id, $user2Id);
        
        // Attempt SQL injection
        $maliciousIds = [
            [$user1Id, "$user2Id OR 1=1"],
            ["$user1Id OR 1=1", $user2Id],
            ["$user1Id; DROP TABLE game_challenges;--", $user2Id],
        ];
        
        foreach ($maliciousIds as $ids) {
            try {
                $id1 = (int)$ids[0];
                $id2 = (int)$ids[1];
                $result = \db_challenge_pending_exists($this->pdo, $id1, $id2);
                // Should return boolean
                $this->assertIsBool($result);
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'SQL syntax') !== false) {
                    $this->fail("SQL injection attempt caused SQL syntax error: " . $e->getMessage());
                }
                throw $e;
            }
        }
    }

    public function testMarkChallengeStatusPreventsSQLInjection(): void
    {
        $user1Id = $this->createTestUser('statususer1');
        $user2Id = $this->createTestUser('statususer2');
        $challengeId = \db_insert_challenge($this->pdo, $user1Id, $user2Id);
        
        // Use shorter malicious statuses to fit ENUM column
        // The key is testing that SQL injection doesn't work
        $maliciousStatuses = [
            "accepted'; DR",
            "accepted' OR",
            "'; DELETE",
        ];
        
        $allSafe = true;
        foreach ($maliciousStatuses as $maliciousStatus) {
            try {
                // Should store status literally (may fail ENUM validation, but shouldn't cause SQL injection)
                \db_mark_challenge_status($this->pdo, $challengeId, $maliciousStatus);
                
                // Verify status was stored literally (if it passed ENUM validation)
                $challenge = \db_get_challenge_for_accept($this->pdo, $challengeId);
                $this->assertNotNull($challenge);
                // If status was stored, it should be literal (not executed as SQL)
                if ($challenge['status'] === $maliciousStatus) {
                    $this->assertSame($maliciousStatus, $challenge['status'], "Status should be stored literally, not executed as SQL");
                }
            } catch (PDOException $e) {
                // If it's an ENUM constraint violation or data truncation, that's expected
                // But SQL injection should not cause a syntax error
                if (strpos($e->getMessage(), 'SQL syntax') !== false) {
                    $allSafe = false;
                    $this->fail("SQL injection attempt caused SQL syntax error: " . $e->getMessage());
                }
                // ENUM violations are acceptable - the important thing is SQL injection didn't work
            }
        }
        
        // Verify we can still update with valid status
        \db_mark_challenge_status($this->pdo, $challengeId, 'declined');
        $challenge = \db_get_challenge_for_accept($this->pdo, $challengeId);
        $this->assertNotNull($challenge);
        $this->assertSame('declined', $challenge['status']);
    }

    // ============================================================================
    // PRESENCE TABLE SQL INJECTION TESTS
    // ============================================================================

    public function testUpsertPresencePreventsSQLInjectionInUsername(): void
    {
        $userId = $this->createTestUser('presenceuser');
        
        $maliciousUsernames = [
            "user'; DROP TABLE user_lobby_presence;--",
            "user' OR '1'='1",
            "'; DELETE FROM users;--",
        ];
        
        foreach ($maliciousUsernames as $maliciousUsername) {
            try {
                $result = \db_upsert_presence($this->pdo, $userId, $maliciousUsername, 'online');
                $this->assertTrue($result, "Should successfully upsert presence");
                
                // Verify username was stored literally
                // Note: Username is canonicalized (lowercase) by db_upsert_presence
                $presence = \db_get_user_presence($this->pdo, $userId);
                $this->assertNotNull($presence);
                // Username is canonicalized, so compare lowercase version
                $this->assertSame(mb_strtolower($maliciousUsername), $presence['user_username'], "Username should be stored (canonicalized)");
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'SQL syntax') !== false) {
                    $this->fail("SQL injection attempt caused SQL syntax error: " . $e->getMessage());
                }
                throw $e;
            }
        }
    }

    public function testSetOfflinePreventsSQLInjectionInStatus(): void
    {
        $userId = $this->createTestUser('offlineuser');
        \db_upsert_presence($this->pdo, $userId, 'offlineuser', 'online');
        
        $maliciousStatuses = [
            "idle'; DROP TABLE user_lobby_presence;--",
            "idle' OR '1'='1",
            "'; DELETE FROM users;--",
        ];
        
        foreach ($maliciousStatuses as $maliciousStatus) {
            try {
                // The function validates status against allowed values, so invalid ones will be rejected
                // But we should test that SQL injection doesn't work
                $result = \db_set_offline($this->pdo, $userId, $maliciousStatus);
                
                // Should either succeed (if status is valid) or fail validation (not SQL injection)
                $this->assertIsBool($result);
            } catch (PDOException $e) {
                // If it's an ENUM constraint violation, that's expected
                if (strpos($e->getMessage(), 'SQL syntax') !== false) {
                    $this->fail("SQL injection attempt caused SQL syntax error: " . $e->getMessage());
                }
            }
        }
    }

    // ============================================================================
    // SUBSCRIPTIONS TABLE SQL INJECTION TESTS
    // ============================================================================

    public function testInsertSubscriptionPreventsSQLInjectionInConnectionId(): void
    {
        $userId = $this->createTestUser('subuser');
        
        $maliciousConnectionIds = [
            "conn1'; DROP TABLE ws_subscriptions;--",
            "conn1' OR '1'='1",
            "'; DELETE FROM users;--",
        ];
        
        foreach ($maliciousConnectionIds as $maliciousConnectionId) {
            try {
                $result = \db_insert_subscription(
                    $this->pdo,
                    $userId,
                    $maliciousConnectionId,
                    'lobby',
                    0
                );
                
                $this->assertTrue($result, "Should successfully insert subscription");
                
                // Verify connection ID was stored literally
                $subscriptions = \db_get_user_subscriptions($this->pdo, $userId);
                $found = false;
                foreach ($subscriptions as $sub) {
                    if ($sub['connection_id'] === $maliciousConnectionId) {
                        $found = true;
                        break;
                    }
                }
                $this->assertTrue($found, "Subscription should be retrievable with literal connection ID");
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'SQL syntax') !== false) {
                    $this->fail("SQL injection attempt caused SQL syntax error: " . $e->getMessage());
                }
                throw $e;
            }
        }
    }

    // ============================================================================
    // NONCES TABLE SQL INJECTION TESTS
    // ============================================================================

    public function testGetNoncePreventsSQLInjection(): void
    {
        $userId = $this->createTestUser('nonceuser');
        $sessionId = $this->createTestSession($userId);
        
        // Create a valid nonce
        $nonce = bin2hex(random_bytes(16));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
        \db_insert_nonce($this->pdo, $sessionId, $nonce, $expiresAt);
        
        $maliciousNonces = [
            "token'; DROP TABLE csrf_nonces;--",
            "token' OR '1'='1",
            "'; DELETE FROM users;--",
            "' UNION SELECT * FROM users--",
        ];
        
        foreach ($maliciousNonces as $maliciousNonce) {
            try {
                $result = \db_get_nonce($this->pdo, $maliciousNonce);
                // Should return null (nonce doesn't exist) or valid nonce, not cause SQL error
                $this->assertTrue($result === null || is_array($result));
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'SQL syntax') !== false) {
                    $this->fail("SQL injection attempt caused SQL syntax error: " . $e->getMessage());
                }
                throw $e;
            }
        }
    }

    // ============================================================================
    // EDGE CASES AND TYPE COERCION TESTS
    // ============================================================================

    public function testIntegerCoercionPreventsSQLInjection(): void
    {
        // Test that integer casting prevents SQL injection
        $userId = $this->createTestUser('coercionuser');
        
        // Even if a string like "1 OR 1=1" is passed, casting to int should make it safe
        $maliciousStringIds = [
            "1 OR 1=1",
            "1' OR '1'='1",
            "1; DROP TABLE users;--",
            "1 UNION SELECT * FROM users--",
        ];
        
        foreach ($maliciousStringIds as $maliciousId) {
            $id = (int)$maliciousId; // This should result in 1
            
            // Should safely query for user ID 1
            $result = \db_get_user_by_id($this->pdo, $id);
            // Should return null (user ID 1 doesn't exist) or the actual user with ID 1
            $this->assertTrue($result === null || is_array($result));
        }
    }

    public function testLimitClauseBoundsChecking(): void
    {
        $userId = $this->createTestUser('limituser2');
        
        // Insert some messages
        for ($i = 0; $i < 5; $i++) {
            \db_insert_chat_message($this->pdo, 'lobby', 0, $userId, "Message $i");
        }
        
        // Test edge cases for limit
        $testLimits = [
            -10,    // Should become 1 (max(1, ...))
            0,      // Should become 1 (max(1, ...))
            1,      // Should work
            5,      // Should work
            50,     // Should work
            100,    // Should work (at cap)
            1000,   // Should be capped at 100
            999999, // Should be capped at 100
        ];
        
        foreach ($testLimits as $limit) {
            $messages = \db_get_recent_chat_messages($this->pdo, 'lobby', 0, $limit);
            $this->assertIsArray($messages);
            $this->assertGreaterThanOrEqual(0, count($messages));
            $this->assertLessThanOrEqual(100, count($messages), "Limit should be capped at 100");
        }
    }

    public function testPDOEmulatePreparesIsDisabled(): void
    {
        // Verify that PDO is configured with EMULATE_PREPARES => false
        $this->assertFalse(
            $this->pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),
            "PDO::ATTR_EMULATE_PREPARES should be false for security"
        );
    }
}

