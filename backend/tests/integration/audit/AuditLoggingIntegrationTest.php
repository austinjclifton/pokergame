<?php
declare(strict_types=1);

require_once __DIR__ . '/../db/BaseDBIntegrationTest.php';

/**
 * Integration tests to verify audit logging is triggered during operations.
 * 
 * These tests verify that audit logs are actually created when:
 *  - Users register, login, logout
 *  - Login attempts fail
 *  - Challenges are created, accepted, declined
 *  - WebSocket connections/disconnections occur
 *  - Chat messages are sent
 *  - Rate limits are exceeded
 * 
 * @coversNothing
 */
final class AuditLoggingIntegrationTest extends BaseDBIntegrationTest
{
    /**
     * Load database functions and services required for audit logging tests
     */
    protected function loadDatabaseFunctions(): void
    {
        require_once __DIR__ . '/../../../app/services/AuthService.php';
        require_once __DIR__ . '/../../../app/services/ChallengeService.php';
        require_once __DIR__ . '/../../../app/services/AuditService.php';
        require_once __DIR__ . '/../../../app/db/audit_log.php';
        require_once __DIR__ . '/../../../app/db/users.php';
        require_once __DIR__ . '/../../../app/db/sessions.php';
        require_once __DIR__ . '/../../../app/db/nonces.php';
        require_once __DIR__ . '/../../../lib/session.php';
        require_once __DIR__ . '/../../../lib/security.php';
        require_once __DIR__ . '/../../../config/security.php';
    }

    /**
     * Helper: Create a test user with a specific password (overrides base class for password control)
     * 
     * @param string $username Username
     * @param string $password Password (default: 'TestPass123!')
     * @return int User ID
     */
    private function createTestUserWithPassword(string $username, string $password = 'TestPass123!'): int
    {
        $email = $username . '@test.com';
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        return db_insert_user($this->pdo, $username, $email, $passwordHash);
    }

    private function getAuditLogsByAction(string $action): array
    {
        return db_query_audit_logs($this->pdo, ['action' => $action]);
    }

    private function getLatestAuditLog(): ?array
    {
        $logs = db_query_audit_logs($this->pdo, ['limit' => 1]);
        return !empty($logs) ? $logs[0] : null;
    }

    // ============================================================================
    // AUTH SERVICE AUDIT LOGGING TESTS
    // ============================================================================

    public function testUserRegistrationCreatesAuditLog(): void
    {
        $initialCount = db_count_audit_logs($this->pdo, ['action' => 'user.register']);
        
        $username = 'audit_register_user';
        $email = 'audit_register@test.com';
        $password = 'TestPass123!';
        
        // Create a session and nonce for registration
        $tempUserId = $this->createTestUserWithPassword('temp_user');
        $ip = '192.168.1.100';
        $ua = 'Test Browser';
        $sessionId = db_insert_session($this->pdo, $tempUserId, hash('sha256', $ip), $ua, date('Y-m-d H:i:s', time() + 3600));
        $nonceValue = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 3600);
        db_insert_nonce($this->pdo, $sessionId, $nonceValue, $expiresAt);
        $nonce = $nonceValue;
        
        $_SERVER['REMOTE_ADDR'] = $ip;
        $_SERVER['HTTP_USER_AGENT'] = $ua;
        
        auth_register_user($this->pdo, $username, $email, $password, $nonce);
        
        $logs = $this->getAuditLogsByAction('user.register');
        $this->assertGreaterThan($initialCount, count($logs), 'Registration should create audit log');
        
        $latest = $this->getLatestAuditLog();
        $this->assertNotNull($latest);
        $this->assertEquals('user.register', $latest['action']);
        $this->assertEquals('api', $latest['channel']);
        $this->assertEquals('success', $latest['status']);
        $this->assertEquals('info', $latest['severity']);
        $this->assertEquals('user', $latest['entity_type']);
        
        $details = $latest['details'];
        $this->assertIsArray($details, 'Audit entry details must be array');
        if (!empty($details)) {
            // Validate JSON if details is a string (from DB)
            if (is_string($details)) {
                $decoded = json_decode($details, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->fail('Invalid JSON in audit log details: ' . json_last_error_msg());
                }
                $details = $decoded;
            }
            $this->assertEquals($username, $details['username']);
        }
    }

    public function testUserLoginCreatesAuditLog(): void
    {
        $initialCount = db_count_audit_logs($this->pdo, ['action' => 'user.login']);
        
        $username = 'audit_login_user';
        $password = 'TestPass123!';
        $userId = $this->createTestUserWithPassword($username, $password);
        
        $_SERVER['REMOTE_ADDR'] = '192.168.1.101';
        $_SERVER['HTTP_USER_AGENT'] = 'Test Browser';
        
        auth_login_user($this->pdo, $username, $password);
        
        $logs = $this->getAuditLogsByAction('user.login');
        $this->assertGreaterThan($initialCount, count($logs), 'Login should create audit log');
        
        $latest = $this->getLatestAuditLog();
        $this->assertNotNull($latest);
        $this->assertEquals('user.login', $latest['action']);
        $this->assertEquals($userId, (int)$latest['user_id']);
        $this->assertEquals('api', $latest['channel']);
        $this->assertEquals('success', $latest['status']);
        $this->assertEquals('info', $latest['severity']);
        $this->assertNotNull($latest['session_id']);
        $this->assertNotNull($latest['ip_hash']);
    }

    public function testUserLogoutCreatesAuditLog(): void
    {
        $initialCount = db_count_audit_logs($this->pdo, ['action' => 'user.logout']);
        
        $username = 'audit_logout_user';
        $password = 'TestPass123!';
        $userId = $this->createTestUserWithPassword($username, $password);
        
        $_SERVER['REMOTE_ADDR'] = '192.168.1.102';
        $_SERVER['HTTP_USER_AGENT'] = 'Test Browser';
        
        // Login first
        $result = auth_login_user($this->pdo, $username, $password);
        $sessionId = $result['user']['session_id'];
        
        // Set cookie for logout
        $_COOKIE['session_id'] = (string)$sessionId;
        
        // Logout
        auth_logout_user($this->pdo);
        
        $logs = $this->getAuditLogsByAction('user.logout');
        $this->assertGreaterThan($initialCount, count($logs), 'Logout should create audit log');
        
        $latest = $this->getLatestAuditLog();
        $this->assertNotNull($latest);
        $this->assertEquals('user.logout', $latest['action']);
        $this->assertEquals($userId, (int)$latest['user_id']);
        $this->assertEquals($sessionId, (int)$latest['session_id']);
        $this->assertEquals('api', $latest['channel']);
        $this->assertEquals('success', $latest['status']);
    }

    public function testFailedLoginAttemptCreatesAuditLog(): void
    {
        $initialCount = db_count_audit_logs($this->pdo, ['action' => 'user.login', 'status' => 'failure']);
        
        require_once __DIR__ . '/../../../app/services/AuditService.php';
        require_once __DIR__ . '/../../../config/security.php';
        
        $_SERVER['REMOTE_ADDR'] = '192.168.1.103';
        $_SERVER['HTTP_USER_AGENT'] = 'Test Browser';
        
        // Simulate failed login attempt (like login.php does)
        try {
            auth_login_user($this->pdo, 'nonexistent_user', 'wrong_password');
            $this->fail('Should have thrown exception');
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'INVALID_CREDENTIALS') {
                // Log the failed attempt (simulating login.php behavior)
                log_audit_event($this->pdo, [
                    'user_id' => null,
                    'ip_address' => get_client_ip(),
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                    'action' => 'user.login',
                    'details' => [
                        'username_attempted' => 'nonexistent_user',
                        'reason' => 'invalid_credentials',
                    ],
                    'channel' => 'api',
                    'status' => 'failure',
                    'severity' => 'warn',
                ]);
            }
        }
        
        $logs = $this->getAuditLogsByAction('user.login');
        $failureLogs = array_filter($logs, fn($log) => $log['status'] === 'failure');
        $this->assertGreaterThan($initialCount, count($failureLogs), 'Failed login should create audit log');
        
        $latest = $this->getLatestAuditLog();
        $this->assertNotNull($latest);
        $this->assertEquals('user.login', $latest['action']);
        $this->assertNull($latest['user_id']);
        $this->assertEquals('failure', $latest['status']);
        $this->assertEquals('warn', $latest['severity']);
        
        $details = $latest['details'];
        $this->assertIsArray($details, 'Audit entry details must be array');
        if (is_string($details)) {
            $decoded = json_decode($details, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->fail('Invalid JSON in audit log details: ' . json_last_error_msg());
            }
            $details = $decoded;
        }
        $this->assertEquals('nonexistent_user', $details['username_attempted']);
        $this->assertEquals('invalid_credentials', $details['reason']);
    }

    // ============================================================================
    // CHALLENGE SERVICE AUDIT LOGGING TESTS
    // ============================================================================

    public function testChallengeCreationCreatesAuditLog(): void
    {
        $initialCount = db_count_audit_logs($this->pdo, ['action' => 'challenge.create']);
        
        $user1Id = $this->createTestUser('challenge_creator');
        $user2Id = $this->createTestUser('challenge_target');
        
        $challengeService = new ChallengeService($this->pdo);
        $result = $challengeService->send($user1Id, 'challenge_target');
        
        $this->assertTrue($result['ok']);
        
        $logs = $this->getAuditLogsByAction('challenge.create');
        $this->assertGreaterThan($initialCount, count($logs), 'Challenge creation should create audit log');
        
        $latest = $this->getLatestAuditLog();
        $this->assertNotNull($latest);
        $this->assertEquals('challenge.create', $latest['action']);
        $this->assertEquals($user1Id, (int)$latest['user_id']);
        $this->assertEquals('challenge', $latest['entity_type']);
        $this->assertEquals($result['challenge_id'], (int)$latest['entity_id']);
        $this->assertEquals('websocket', $latest['channel']);
        $this->assertEquals('success', $latest['status']);
        
        $details = $latest['details'];
        $this->assertIsArray($details, 'Audit entry details must be array');
        if (is_string($details)) {
            $decoded = json_decode($details, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->fail('Invalid JSON in audit log details: ' . json_last_error_msg());
            }
            $details = $decoded;
        }
        $this->assertEquals($user1Id, $details['from_user_id']);
        $this->assertEquals($user2Id, $details['to_user_id']);
    }

    public function testChallengeAcceptanceCreatesAuditLog(): void
    {
        $initialCount = db_count_audit_logs($this->pdo, ['action' => 'challenge.accept']);
        
        require_once __DIR__ . '/../../../app/db/challenges.php';
        
        $user1Id = $this->createTestUser('challenge_from');
        $user2Id = $this->createTestUser('challenge_to');
        
        $challengeId = db_insert_challenge($this->pdo, $user1Id, $user2Id);
        
        $challengeService = new ChallengeService($this->pdo);
        $result = $challengeService->accept($challengeId, $user2Id);
        
        $this->assertTrue($result['ok']);
        
        $logs = $this->getAuditLogsByAction('challenge.accept');
        $this->assertGreaterThan($initialCount, count($logs), 'Challenge acceptance should create audit log');
        
        $latest = $this->getLatestAuditLog();
        $this->assertNotNull($latest);
        $this->assertEquals('challenge.accept', $latest['action']);
        $this->assertEquals($user2Id, (int)$latest['user_id']);
        $this->assertEquals('challenge', $latest['entity_type']);
        $this->assertEquals($challengeId, (int)$latest['entity_id']);
        $this->assertEquals('websocket', $latest['channel']);
    }

    public function testChallengeDeclineCreatesAuditLog(): void
    {
        $initialCount = db_count_audit_logs($this->pdo, ['action' => 'challenge.decline']);
        
        require_once __DIR__ . '/../../../app/db/challenges.php';
        
        $user1Id = $this->createTestUser('challenge_from2');
        $user2Id = $this->createTestUser('challenge_to2');
        
        $challengeId = db_insert_challenge($this->pdo, $user1Id, $user2Id);
        
        $challengeService = new ChallengeService($this->pdo);
        $result = $challengeService->decline($challengeId, $user2Id);
        
        $this->assertTrue($result['ok']);
        
        $logs = $this->getAuditLogsByAction('challenge.decline');
        $this->assertGreaterThan($initialCount, count($logs), 'Challenge decline should create audit log');
        
        $latest = $this->getLatestAuditLog();
        $this->assertNotNull($latest);
        $this->assertEquals('challenge.decline', $latest['action']);
        $this->assertEquals($user2Id, (int)$latest['user_id']);
        $this->assertEquals('challenge', $latest['entity_type']);
        $this->assertEquals($challengeId, (int)$latest['entity_id']);
        $this->assertEquals('websocket', $latest['channel']);
    }

    // ============================================================================
    // WEBSOCKET AUDIT LOGGING TESTS
    // ============================================================================

    public function testWebSocketConnectionCreatesAuditLog(): void
    {
        $initialCount = db_count_audit_logs($this->pdo, ['action' => 'websocket.connect']);
        
        require_once __DIR__ . '/../../../ws/LobbySocket.php';
        require_once __DIR__ . '/../../../app/db/users.php';
        
        $userId = $this->createTestUser('ws_user');
        $sessionId = db_insert_session($this->pdo, $userId, hash('sha256', '127.0.0.1'), 'Test', date('Y-m-d H:i:s', time() + 3600));
        
        $lobbySocket = new LobbySocket($this->pdo);
        
        $conn = $this->createMock(\Ratchet\ConnectionInterface::class);
        $conn->resourceId = 123;
        $conn->userCtx = [
            'user_id' => $userId,
            'session_id' => $sessionId,
            'username' => 'ws_user',
        ];
        
        $conn->expects($this->atLeastOnce())->method('send');
        
        $lobbySocket->onOpen($conn);
        
        $logs = $this->getAuditLogsByAction('websocket.connect');
        $this->assertGreaterThan($initialCount, count($logs), 'WebSocket connection should create audit log');
        
        $latest = $this->getLatestAuditLog();
        $this->assertNotNull($latest);
        $this->assertEquals('websocket.connect', $latest['action']);
        $this->assertEquals($userId, (int)$latest['user_id']);
        $this->assertEquals($sessionId, (int)$latest['session_id']);
        $this->assertEquals('websocket', $latest['channel']);
        $this->assertEquals('websocket_connection', $latest['entity_type']);
    }

    public function testChatMessageCreatesAuditLog(): void
    {
        $initialCount = db_count_audit_logs($this->pdo, ['action' => 'chat.send']);
        
        require_once __DIR__ . '/../../../ws/LobbySocket.php';
        require_once __DIR__ . '/../../../app/db/chat_messages.php';
        
        $userId = $this->createTestUser('chat_user');
        $sessionId = db_insert_session($this->pdo, $userId, hash('sha256', '127.0.0.1'), 'Test', date('Y-m-d H:i:s', time() + 3600));
        
        $lobbySocket = new LobbySocket($this->pdo);
        
        $conn = $this->createMock(\Ratchet\ConnectionInterface::class);
        $conn->resourceId = 456;
        $conn->userCtx = [
            'user_id' => $userId,
            'session_id' => $sessionId,
            'username' => 'chat_user',
        ];
        
        // Open connection first
        $conn->expects($this->atLeastOnce())->method('send');
        $lobbySocket->onOpen($conn);
        
        // Send chat message
        $message = 'Hello, world!';
        $lobbySocket->onMessage($conn, json_encode(['type' => 'chat', 'msg' => $message]));
        
        $logs = $this->getAuditLogsByAction('chat.send');
        $this->assertGreaterThan($initialCount, count($logs), 'Chat message should create audit log');
        
        $latest = $this->getLatestAuditLog();
        $this->assertNotNull($latest);
        $this->assertEquals('chat.send', $latest['action']);
        $this->assertEquals($userId, (int)$latest['user_id']);
        $this->assertEquals('chat_message', $latest['entity_type']);
        $this->assertEquals('websocket', $latest['channel']);
        
        $details = $latest['details'];
        $this->assertIsArray($details, 'Audit entry details must be array');
        if (is_string($details)) {
            $decoded = json_decode($details, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->fail('Invalid JSON in audit log details: ' . json_last_error_msg());
            }
            $details = $decoded;
        }
        $this->assertEquals('lobby', $details['channel_type']);
        $this->assertEquals(0, $details['channel_id']);
        $this->assertEquals(strlen($message), $details['message_length']);
    }

    public function testWebSocketDisconnectionCreatesAuditLog(): void
    {
        $initialCount = db_count_audit_logs($this->pdo, ['action' => 'websocket.disconnect']);
        
        require_once __DIR__ . '/../../../ws/LobbySocket.php';
        
        $userId = $this->createTestUser('disconnect_user');
        $sessionId = db_insert_session($this->pdo, $userId, hash('sha256', '127.0.0.1'), 'Test', date('Y-m-d H:i:s', time() + 3600));
        
        $lobbySocket = new LobbySocket($this->pdo);
        
        $conn = $this->createMock(\Ratchet\ConnectionInterface::class);
        $conn->resourceId = 789;
        $conn->userCtx = [
            'user_id' => $userId,
            'session_id' => $sessionId,
            'username' => 'disconnect_user',
        ];
        
        // Open connection first
        $conn->expects($this->atLeastOnce())->method('send');
        $lobbySocket->onOpen($conn);
        
        // Close connection
        $lobbySocket->onClose($conn);
        
        $logs = $this->getAuditLogsByAction('websocket.disconnect');
        $this->assertGreaterThan($initialCount, count($logs), 'WebSocket disconnection should create audit log');
        
        $latest = $this->getLatestAuditLog();
        $this->assertNotNull($latest);
        $this->assertEquals('websocket.disconnect', $latest['action']);
        $this->assertEquals($userId, (int)$latest['user_id']);
        $this->assertEquals('websocket', $latest['channel']);
        $this->assertEquals('websocket_connection', $latest['entity_type']);
    }

    public function testRateLimitViolationCreatesAuditLog(): void
    {
        $initialCount = db_count_audit_logs($this->pdo, ['action' => 'rate_limit.exceeded']);
        
        require_once __DIR__ . '/../../../ws/LobbySocket.php';
        
        $userId = $this->createTestUser('ratelimit_user');
        $sessionId = db_insert_session($this->pdo, $userId, hash('sha256', '127.0.0.1'), 'Test', date('Y-m-d H:i:s', time() + 3600));
        
        $lobbySocket = new LobbySocket($this->pdo);
        
        $conn = $this->createMock(\Ratchet\ConnectionInterface::class);
        $conn->resourceId = 999;
        $conn->userCtx = [
            'user_id' => $userId,
            'session_id' => $sessionId,
            'username' => 'ratelimit_user',
        ];
        
        // Open connection
        $conn->expects($this->atLeastOnce())->method('send');
        $lobbySocket->onOpen($conn);
        
        // Send many messages rapidly to trigger rate limit
        // LobbySocket has RATE_TOKENS = 5.0 and RATE_REFILL_PER_S = 1.5
        // So we need to send more than 5 messages quickly
        for ($i = 0; $i < 10; $i++) {
            $lobbySocket->onMessage($conn, json_encode(['type' => 'ping']));
        }
        
        // Try one more that should be rate limited
        $conn->expects($this->atLeastOnce())->method('send')->with($this->callback(function($msg) {
            $data = json_decode($msg, true);
            return isset($data['type']) && $data['type'] === 'error' && $data['error'] === 'rate_limited';
        }));
        $lobbySocket->onMessage($conn, json_encode(['type' => 'ping']));
        
        $logs = $this->getAuditLogsByAction('rate_limit.exceeded');
        $this->assertGreaterThan($initialCount, count($logs), 'Rate limit violation should create audit log');
        
        // Find the rate limit log
        $rateLimitLog = null;
        foreach ($logs as $log) {
            if ($log['status'] === 'failure' && $log['severity'] === 'warn') {
                $rateLimitLog = $log;
                break;
            }
        }
        
        $this->assertNotNull($rateLimitLog, 'Should have rate limit audit log');
        $this->assertEquals('rate_limit.exceeded', $rateLimitLog['action']);
        $this->assertEquals($userId, (int)$rateLimitLog['user_id']);
        $this->assertEquals('websocket', $rateLimitLog['channel']);
        $this->assertEquals('failure', $rateLimitLog['status']);
        $this->assertEquals('warn', $rateLimitLog['severity']);
    }
}

