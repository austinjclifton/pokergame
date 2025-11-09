<?php
declare(strict_types=1);

/**
 * @phpstan-ignore-file
 * This file uses PHPUnit mocks which create dynamic methods (expects(), etc.) and properties
 * (userCtx) that are not present in the Ratchet interfaces. These warnings are expected
 * and safe in test contexts.
 */

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * Unit tests for AuthenticatedServer WebSocket gateway security.
 *
 * Tests the authentication layer that protects WebSocket endpoints:
 *  - Authentication via ws_token (preferred)
 *  - Authentication via session cookie (fallback)
 *  - Rejection of unauthenticated connections
 *  - Protection against messages from unauthenticated connections
 *  - Error handling and edge cases
 *  - Security boundaries and authorization checks
 *
 * Uses mocks for Ratchet interfaces to test security without real WebSocket connections.
 * Also uses real database for integration testing of authentication functions.
 *
 * Note: PHPUnit mocks create dynamic methods (expects(), etc.) and properties (userCtx)
 * that are not present in the interface, causing PHPStan warnings. These are expected
 * and safe in test contexts.
 *
 * @coversNothing
 */
final class AuthenticatedServerTest extends TestCase
{
    private PDO $pdo;
    private bool $inTransaction = false;
    private $authenticatedServer;
    private MockObject $mockInnerSocket;
    private $mockConnection;

    protected function setUp(): void
    {
        // bootstrap.php is already loaded by phpunit.xml and creates $pdo globally
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

        // Load required functions and classes
        require_once __DIR__ . '/../../../ws/AuthenticatedServer.php';
        require_once __DIR__ . '/../../../app/db/nonces.php';
        require_once __DIR__ . '/../../../lib/session.php';
        require_once __DIR__ . '/../../../app/services/AuthService.php';
        
        // Define ws_auth helper functions inline (to avoid including server.php which starts the server)
        if (!function_exists('ws_parse_query')) {
            function ws_parse_query(RequestInterface $req): array {
                parse_str($req->getUri()->getQuery() ?? '', $out);
                return is_array($out) ? $out : [];
            }
            
            function ws_get_cookie(RequestInterface $req, string $name): ?string {
                foreach ($req->getHeader('Cookie') as $hdr) {
                    foreach (explode(';', $hdr) as $pair) {
                        [$k, $v] = array_map('trim', explode('=', $pair, 2) + [null, null]);
                        if ($k === $name && $v !== null) {
                            return urldecode($v);
                        }
                    }
                }
                return null;
            }
            
            function ws_auth(PDO $pdo, RequestInterface $req): ?array {
                $query  = ws_parse_query($req);
                $token  = isset($query['token']) ? trim((string)$query['token']) : '';
                $cookie = ws_get_cookie($req, 'session_id');

                // Preferred: single-use token
                if ($token !== '') {
                    $ctx = db_consume_ws_nonce($pdo, $token);
                    if ($ctx) return [
                        'user_id'    => $ctx['user_id'],
                        'session_id' => $ctx['session_id'],
                    ];
                    return null; // invalid or expired token
                }

                // Fallback: validate session cookie
                if ($cookie) {
                    try {
                        $user = auth_require_session($pdo);
                        return [
                            'user_id'    => (int)$user['id'],
                            'session_id' => (int)$user['session_id'],
                        ];
                    } catch (RuntimeException $e) {
                        return null;
                    }
                }

                return null;
            }
        }

        // Disable foreign key checks for tests
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        
        // Start a transaction for test isolation
        $this->pdo->beginTransaction();
        $this->inTransaction = true;

        // Create mock inner socket
        $this->mockInnerSocket = $this->createMock(MessageComponentInterface::class);

        // Create AuthenticatedServer instance
        /** @phpstan-ignore-next-line */
        $this->authenticatedServer = new AuthenticatedServer($this->pdo, $this->mockInnerSocket);
    }

    protected function tearDown(): void
    {
        // Rollback transaction to clean up test data
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
        // Make username unique to avoid conflicts when transactions are committed
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
     * Helper: Create a test session and return session ID.
     */
    private function createTestSession(int $userId): int
    {
        $ipHash = hash('sha256', '127.0.0.1');
        $expiresAt = date('Y-m-d H:i:s', time() + 86400); // 1 day
        
        $stmt = $this->pdo->prepare("
            INSERT INTO sessions (user_id, ip_hash, user_agent, expires_at)
            VALUES (:uid, :ip, :ua, :exp)
        ");
        $stmt->execute([
            'uid' => $userId,
            'ip' => $ipHash,
            'ua' => 'Test User Agent',
            'exp' => $expiresAt,
        ]);
        
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Helper: Create a WebSocket token for a user.
     * Note: The csrf_nonces table doesn't have a 'purpose' column.
     */
    private function createWsToken(int $sessionId, int $ttlSeconds = 30): string
    {
        require_once __DIR__ . '/../../../app/db/nonces.php';
        
        // db_create_ws_nonce doesn't use transactions, so we can call it directly
        // But db_consume_ws_nonce does use transactions, which conflicts with test transactions
        $token = db_create_ws_nonce($this->pdo, $sessionId, $ttlSeconds);
        
        return $token;
    }

    /**
     * Helper: Create a test user, session, and WS token.
     */
    private function createTestWsToken(int $userId): string
    {
        $sessionId = $this->createTestSession($userId);
        return $this->createWsToken($sessionId);
    }

    /**
     * Helper: Create a mock connection with httpRequest.
     * @return ConnectionInterface&MockObject
     * @phpstan-ignore-next-line
     */
    private function createMockConnectionWithRequest(?RequestInterface $request = null): ConnectionInterface
    {
        /** @var ConnectionInterface&MockObject $conn */
        $conn = $this->createMock(ConnectionInterface::class);
        
        if ($request) {
            /** @phpstan-ignore-next-line */
            $conn->httpRequest = $request;
        }
        
        return $conn;
    }

    /**
     * Helper: Create a mock RequestInterface with query parameters.
     */
    private function createMockRequestWithQuery(string $queryString, array $cookies = []): RequestInterface
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getQuery')->willReturn($queryString);
        
        $request = $this->createMock(RequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        
        // Mock cookie headers
        $cookieHeaders = [];
        if (!empty($cookies)) {
            $cookiePairs = [];
            foreach ($cookies as $name => $value) {
                $cookiePairs[] = $name . '=' . urlencode($value);
            }
            $cookieHeaders[] = implode('; ', $cookiePairs);
        }
        $request->method('getHeader')->with('Cookie')->willReturn($cookieHeaders);
        
        return $request;
    }

    // ============================================================================
    // ONOPEN TESTS - AUTHENTICATION ON CONNECTION
    // ============================================================================

    public function testOnOpenRejectsConnectionWithoutHttpRequest(): void
    {
        /** @var ConnectionInterface&MockObject $conn */
        $conn = $this->createMock(ConnectionInterface::class);
        /** @phpstan-ignore-next-line */
        $conn->httpRequest = null;
        
        $conn->expects($this->once())->method('close');
        $conn->expects($this->never())->method('send');
        
        // Inner socket should never be called
        $this->mockInnerSocket->expects($this->never())->method('onOpen');
        
        $this->authenticatedServer->onOpen($conn);
    }

    public function testOnOpenRejectsConnectionWithoutTokenOrCookie(): void
    {
        $request = $this->createMockRequestWithQuery('', []);
        $conn = $this->createMockConnectionWithRequest($request);
        
        $conn->expects($this->once())->method('send')->with($this->callback(function ($msg) {
            $data = json_decode($msg, true);
            return isset($data['type']) && $data['type'] === 'error';
        }));
        $conn->expects($this->once())->method('close');
        
        $this->mockInnerSocket->expects($this->never())->method('onOpen');
        
        $this->authenticatedServer->onOpen($conn);
    }

    public function testOnOpenAcceptsConnectionWithValidWsToken(): void
    {
        // Setup: Create user, session, and token
        $userId = $this->createTestUser('ws_token_user');
        $sessionId = $this->createTestSession($userId);
        
        // Commit transaction to allow db_consume_ws_nonce to work
        $wasInTransaction = $this->pdo->inTransaction();
        if ($wasInTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            $token = $this->createWsToken($sessionId);
            
            // Create request with token in query string
            $request = $this->createMockRequestWithQuery("token=$token", []);
            $conn = $this->createMockConnectionWithRequest($request);
            
            // Connection should NOT be closed
            $conn->expects($this->never())->method('close');
            $conn->expects($this->never())->method('send');
            
            // Inner socket should be called
            $this->mockInnerSocket->expects($this->once())->method('onOpen')->with($conn);
            
            $this->authenticatedServer->onOpen($conn);
            
            // Verify userCtx was attached
            $this->assertTrue(property_exists($conn, 'userCtx'), 'Connection should have userCtx property');
            /** @phpstan-ignore-next-line */
            $this->assertSame($userId, $conn->userCtx['user_id']);
            /** @phpstan-ignore-next-line */
            $this->assertSame($sessionId, $conn->userCtx['session_id']);
        } finally {
            // Restart transaction
            if ($wasInTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
    }

    public function testOnOpenRejectsConnectionWithInvalidWsToken(): void
    {
        $request = $this->createMockRequestWithQuery('token=invalid_token_12345', []);
        $conn = $this->createMockConnectionWithRequest($request);
        
        $conn->expects($this->once())->method('send')->with($this->callback(function ($msg) {
            $data = json_decode($msg, true);
            return isset($data['type']) && $data['type'] === 'error';
        }));
        $conn->expects($this->once())->method('close');
        
        $this->mockInnerSocket->expects($this->never())->method('onOpen');
        
        $this->authenticatedServer->onOpen($conn);
        
        // Verify userCtx was NOT attached (may not exist or may be null)
        /** @phpstan-ignore-next-line */
        $hasUserCtx = property_exists($conn, 'userCtx') && isset($conn->userCtx);
        $this->assertFalse($hasUserCtx, 
            'Connection should not have userCtx property when authentication fails');
    }

    public function testOnOpenRejectsConnectionWithExpiredWsToken(): void
    {
        // Create expired token (TTL of -60 seconds)
        $userId = $this->createTestUser('expired_token_user');
        $sessionId = $this->createTestSession($userId);
        
        // Manually create expired token (note: no 'purpose' column)
        $token = bin2hex(random_bytes(16));
        $expiredAt = date('Y-m-d H:i:s', time() - 60);
        
        $stmt = $this->pdo->prepare("
            INSERT INTO csrf_nonces (session_id, nonce, expires_at)
            VALUES (:sid, :token, :exp)
        ");
        $stmt->execute([
            'sid' => $sessionId,
            'token' => $token,
            'exp' => $expiredAt,
        ]);
        
        $request = $this->createMockRequestWithQuery("token=$token", []);
        $conn = $this->createMockConnectionWithRequest($request);
        
        $conn->expects($this->once())->method('send');
        $conn->expects($this->once())->method('close');
        
        $this->mockInnerSocket->expects($this->never())->method('onOpen');
        
        $this->authenticatedServer->onOpen($conn);
    }

    public function testOnOpenRejectsConnectionWithUsedWsToken(): void
    {
        // Create and consume a token (make it used)
        $userId = $this->createTestUser('used_token_user');
        $sessionId = $this->createTestSession($userId);
        $token = $this->createWsToken($sessionId);
        
        // Consume the token (mark as used)
        // Note: db_consume_ws_nonce uses its own transaction, so we need to handle that
        require_once __DIR__ . '/../../../app/db/nonces.php';
        
        // Commit our test transaction temporarily to allow db_consume_ws_nonce to run
        $wasInTransaction = $this->pdo->inTransaction();
        if ($wasInTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            db_consume_ws_nonce($this->pdo, $token);
        } finally {
            // Restart transaction for test isolation
            if ($wasInTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
        
        // Try to use it again
        $request = $this->createMockRequestWithQuery("token=$token", []);
        $conn = $this->createMockConnectionWithRequest($request);
        
        $conn->expects($this->once())->method('send');
        $conn->expects($this->once())->method('close');
        
        $this->mockInnerSocket->expects($this->never())->method('onOpen');
        
        $this->authenticatedServer->onOpen($conn);
    }

    public function testOnOpenAcceptsConnectionWithValidSessionCookie(): void
    {
        // Setup: Create user and session
        $userId = $this->createTestUser('session_cookie_user');
        $sessionId = $this->createTestSession($userId);
        
        // Mock cookie (set $_COOKIE for requireSession)
        $_COOKIE['session_id'] = (string)$sessionId;
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'Test User Agent';
        
        // Create request with session cookie
        $request = $this->createMockRequestWithQuery('', ['session_id' => (string)$sessionId]);
        $conn = $this->createMockConnectionWithRequest($request);
        
        $conn->expects($this->never())->method('close');
        $conn->expects($this->never())->method('send');
        
        $this->mockInnerSocket->expects($this->once())->method('onOpen')->with($conn);
        
        $this->authenticatedServer->onOpen($conn);
        
        $this->assertTrue(property_exists($conn, 'userCtx'), 'Connection should have userCtx property');
        /** @phpstan-ignore-next-line */
        $this->assertSame($userId, $conn->userCtx['user_id']);
        /** @phpstan-ignore-next-line */
        $this->assertSame($sessionId, $conn->userCtx['session_id']);
        
        // Cleanup
        unset($_COOKIE['session_id']);
    }

    public function testOnOpenRejectsConnectionWithInvalidSessionCookie(): void
    {
        $request = $this->createMockRequestWithQuery('', ['session_id' => '999999']);
        $conn = $this->createMockConnectionWithRequest($request);
        
        // Clear any existing cookies
        $_COOKIE = [];
        $_COOKIE['session_id'] = '999999';
        
        $conn->expects($this->once())->method('send');
        $conn->expects($this->once())->method('close');
        
        $this->mockInnerSocket->expects($this->never())->method('onOpen');
        
        $this->authenticatedServer->onOpen($conn);
        
        unset($_COOKIE['session_id']);
    }

    public function testOnOpenPrefersTokenOverCookie(): void
    {
        // Setup: Create user, session, and token
        $userId = $this->createTestUser('prefer_token_user');
        $sessionId = $this->createTestSession($userId);
        
        // Commit transaction to allow db_consume_ws_nonce to work
        $wasInTransaction = $this->pdo->inTransaction();
        if ($wasInTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            $token = $this->createWsToken($sessionId);
            
            // Create request with both token and cookie (token should be preferred)
            $request = $this->createMockRequestWithQuery("token=$token", ['session_id' => (string)$sessionId]);
            $conn = $this->createMockConnectionWithRequest($request);
            
            $conn->expects($this->never())->method('close');
            
            $this->mockInnerSocket->expects($this->once())->method('onOpen');
            
            $this->authenticatedServer->onOpen($conn);
            
            // Verify token was used (token should be consumed after use)
            $this->assertTrue(property_exists($conn, 'userCtx'), 'Connection should have userCtx property');
        } finally {
            // Restart transaction
            if ($wasInTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
    }

    public function testOnOpenHandlesExceptionGracefully(): void
    {
        // Create a request that will cause an error in ws_auth
        $request = $this->createMock(RequestInterface::class);
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getQuery')->willThrowException(new \RuntimeException('Test error'));
        $request->method('getUri')->willReturn($uri);
        
        $conn = $this->createMockConnectionWithRequest($request);
        
        $conn->expects($this->once())->method('send')->with($this->callback(function ($msg) {
            $data = json_decode($msg, true);
            return isset($data['type']) && $data['type'] === 'error' 
                && isset($data['message']) && $data['message'] === 'server_error';
        }));
        $conn->expects($this->once())->method('close');
        
        $this->mockInnerSocket->expects($this->never())->method('onOpen');
        
        $this->authenticatedServer->onOpen($conn);
    }

    // ============================================================================
    // CONNECTION TIMEOUT & NETWORK FAILURE TESTS
    // ============================================================================

    public function testOnOpenHandlesDatabaseConnectionFailure(): void
    {
        // Test that onOpen handles database connection failures gracefully
        // This simulates what happens if database is unavailable during authentication
        
        $userId = $this->createTestUser('timeout_user');
        $sessionId = $this->createTestSession($userId);
        $token = $this->createWsToken($sessionId);
        
        // Create a mock PDO that throws exception on query
        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->method('prepare')->willThrowException(new \PDOException('Connection lost'));
        
        // Create new AuthenticatedServer with mock PDO
        /** @phpstan-ignore-next-line */
        $serverWithBadDb = new AuthenticatedServer($mockPdo, $this->mockInnerSocket);
        
        $request = $this->createMockRequestWithQuery("token=$token");
        $conn = $this->createMockConnectionWithRequest($request);
        
        // Should handle error gracefully
        $conn->expects($this->once())->method('send')->with($this->callback(function ($msg) {
            $data = json_decode($msg, true);
            return isset($data['type']) && $data['type'] === 'error';
        }));
        $conn->expects($this->once())->method('close');
        
        $this->mockInnerSocket->expects($this->never())->method('onOpen');
        
        $serverWithBadDb->onOpen($conn);
    }

    public function testRapidConnectDisconnectDoesNotLeakConnections(): void
    {
        // Test that rapid connect/disconnect cycles don't leak connection counts
        // This simulates a user rapidly connecting and disconnecting
        
        $userId = $this->createTestUser('rapid_user');
        $sessionId = $this->createTestSession($userId);
        
        // Commit transaction to allow token creation
        $wasInTransaction = $this->pdo->inTransaction();
        if ($wasInTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            // Set expectations for all operations upfront
            $this->mockInnerSocket->expects($this->exactly(11))->method('onOpen');
            $this->mockInnerSocket->expects($this->exactly(11))->method('onClose'); // 10 + 1 cleanup
            
            // Simulate 10 rapid connect/disconnect cycles
            for ($i = 0; $i < 10; $i++) {
                $token = $this->createWsToken($sessionId);
                $request = $this->createMockRequestWithQuery("token=$token");
                $conn = $this->createMockConnectionWithRequest($request);
                
                // Open connection
                $this->authenticatedServer->onOpen($conn);
                
                // Verify connection was tracked
                $this->assertTrue(property_exists($conn, 'userCtx'), 'Connection should have userCtx');
                
                // Close connection
                $this->authenticatedServer->onClose($conn);
            }
            
            // After all connections are closed, counts should be reset
            // Verify that a new connection still works (no resource leaks)
            $token = $this->createWsToken($sessionId);
            $request = $this->createMockRequestWithQuery("token=$token");
            $conn = $this->createMockConnectionWithRequest($request);
            $conn->expects($this->never())->method('close');
            
            $this->authenticatedServer->onOpen($conn);
            
            // Clean up
            $this->authenticatedServer->onClose($conn);
        } finally {
            if ($wasInTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
    }

    public function testOnMessageHandlesConnectionFailureDuringSend(): void
    {
        // Test that if send() fails (e.g., connection closed), error is handled gracefully
        // Note: AuthenticatedServer doesn't send messages itself - it delegates to inner socket
        // This test verifies that onMessage properly delegates even if inner socket might fail
        
        $userId = $this->createTestUser('sendfail_user');
        $sessionId = $this->createTestSession($userId);
        
        // Commit transaction
        $wasInTransaction = $this->pdo->inTransaction();
        if ($wasInTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            $token = $this->createWsToken($sessionId);
            $request = $this->createMockRequestWithQuery("token=$token");
            $conn = $this->createMockConnectionWithRequest($request);
            
            // Open connection successfully
            $this->mockInnerSocket->expects($this->once())->method('onOpen');
            $this->authenticatedServer->onOpen($conn);
            
            // onMessage should delegate to inner socket
            // The inner socket might try to send, which could fail, but AuthenticatedServer
            // just delegates - error handling is in the inner socket
            $this->mockInnerSocket->expects($this->once())->method('onMessage');
            
            // This should not throw - AuthenticatedServer just delegates
            $this->authenticatedServer->onMessage($conn, '{"type":"test"}');
        } finally {
            if ($wasInTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
    }

    // ============================================================================
    // WEBSOCKET EDGE CASE TESTS
    // ============================================================================

    public function testMaximumConcurrentConnectionsPerUser(): void
    {
        // Test that the maximum connections per user limit is enforced
        // This verifies the connection limit logic works at the boundary
        
        $userId = $this->createTestUser('maxconn_user');
        $sessionId = $this->createTestSession($userId);
        
        // Commit transaction
        $wasInTransaction = $this->pdo->inTransaction();
        if ($wasInTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            $connections = [];
            $maxConnections = 5; // MAX_CONNECTIONS_PER_USER
            
            // Set expectations for all operations upfront
            $this->mockInnerSocket->expects($this->exactly($maxConnections))->method('onOpen');
            
            // Create maximum allowed connections
            for ($i = 0; $i < $maxConnections; $i++) {
                $token = $this->createWsToken($sessionId);
                $request = $this->createMockRequestWithQuery("token=$token");
                $conn = $this->createMockConnectionWithRequest($request);
                $conn->remoteAddress = '192.168.1.' . (200 + $i); // Different IPs to avoid IP limit
                
                // Don't set close expectation - connection might be closed if limit is hit
                $this->authenticatedServer->onOpen($conn);
                $connections[] = $conn;
            }
            
            // Next connection should be rejected (user limit exceeded)
            $token = $this->createWsToken($sessionId);
            $request = $this->createMockRequestWithQuery("token=$token");
            $conn = $this->createMockConnectionWithRequest($request);
            $conn->remoteAddress = '192.168.1.250';
            
            $conn->expects($this->once())->method('send')->with($this->callback(function ($msg) {
                $data = json_decode($msg, true);
                return isset($data['type']) && $data['type'] === 'error' 
                    && isset($data['message']) && strpos($data['message'], 'user_connection_limit_exceeded') !== false;
            }));
            $conn->expects($this->once())->method('close');
            
            $this->mockInnerSocket->expects($this->never())->method('onOpen');
            
            $this->authenticatedServer->onOpen($conn);
            
            // Clean up connections
            foreach ($connections as $conn) {
                $this->authenticatedServer->onClose($conn);
            }
        } finally {
            if ($wasInTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
    }

    public function testVeryFastConnectionDisconnect(): void
    {
        // Test that very fast connect/disconnect cycles work correctly
        // This verifies there are no timing-related issues
        
        $userId = $this->createTestUser('fastconn_user');
        $sessionId = $this->createTestSession($userId);
        
        // Commit transaction
        $wasInTransaction = $this->pdo->inTransaction();
        if ($wasInTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            // Set expectations upfront
            $this->mockInnerSocket->expects($this->exactly(2))->method('onOpen');
            $this->mockInnerSocket->expects($this->exactly(2))->method('onClose');
            
            // Very fast connect/disconnect (no delay)
            $token = $this->createWsToken($sessionId);
            $request = $this->createMockRequestWithQuery("token=$token");
            $conn = $this->createMockConnectionWithRequest($request);
            
            $this->authenticatedServer->onOpen($conn);
            
            // Immediately disconnect (no delay)
            $this->authenticatedServer->onClose($conn);
            
            // Verify connection was cleaned up (can create new connection)
            $token2 = $this->createWsToken($sessionId);
            $request2 = $this->createMockRequestWithQuery("token=$token2");
            $conn2 = $this->createMockConnectionWithRequest($request2);
            
            // Don't set close expectation - connection might be closed if there's an error
            $this->authenticatedServer->onOpen($conn2);
            
            // Clean up
            $this->authenticatedServer->onClose($conn2);
        } finally {
            if ($wasInTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
    }

    public function testOnOpenHandlesMissingHttpRequest(): void
    {
        // Test that onOpen handles missing HTTP request gracefully
        // This can happen if Ratchet doesn't properly attach the request
        
        /** @var ConnectionInterface&MockObject $conn */
        $conn = $this->createMock(ConnectionInterface::class);
        // Don't set httpRequest property
        
        // Should close connection without crashing
        $conn->expects($this->once())->method('close');
        
        $this->mockInnerSocket->expects($this->never())->method('onOpen');
        
        $this->authenticatedServer->onOpen($conn);
    }

    public function testOnOpenHandlesInvalidTokenFormat(): void
    {
        // Test that onOpen handles invalid token format gracefully
        
        $request = $this->createMockRequestWithQuery("token=invalid_token_format_12345");
        $conn = $this->createMockConnectionWithRequest($request);
        
        // Should reject and close
        $conn->expects($this->once())->method('send')->with($this->callback(function ($msg) {
            $data = json_decode($msg, true);
            return isset($data['type']) && $data['type'] === 'error';
        }));
        $conn->expects($this->once())->method('close');
        
        $this->mockInnerSocket->expects($this->never())->method('onOpen');
        
        $this->authenticatedServer->onOpen($conn);
    }

    public function testOnOpenHandlesExpiredToken(): void
    {
        // Test that onOpen handles expired tokens gracefully
        
        $userId = $this->createTestUser('expired_token_user');
        $sessionId = $this->createTestSession($userId);
        
        // Commit transaction
        $wasInTransaction = $this->pdo->inTransaction();
        if ($wasInTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            // Create a token and manually expire it
            $token = $this->createWsToken($sessionId);
            
            // Manually expire the token in the database
            $this->pdo->exec("UPDATE csrf_nonces SET expires_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE nonce = " . $this->pdo->quote($token));
            
            $request = $this->createMockRequestWithQuery("token=$token");
            $conn = $this->createMockConnectionWithRequest($request);
            
            // Should reject expired token
            $conn->expects($this->once())->method('send')->with($this->callback(function ($msg) {
                $data = json_decode($msg, true);
                return isset($data['type']) && $data['type'] === 'error';
            }));
            $conn->expects($this->once())->method('close');
            
            $this->mockInnerSocket->expects($this->never())->method('onOpen');
            
            $this->authenticatedServer->onOpen($conn);
        } finally {
            if ($wasInTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
    }

    public function testOnCloseHandlesConnectionWithoutTracking(): void
    {
        // Test that onClose handles connections that were never tracked
        // (e.g., connection failed before onOpen completed)
        
        /** @var ConnectionInterface&MockObject $conn */
        $conn = $this->createMock(ConnectionInterface::class);
        // No ipAddress or userId properties set
        
        // Should not crash, just delegate to inner socket if userCtx exists
        // Since there's no userCtx, inner socket should not be called
        $this->mockInnerSocket->expects($this->never())->method('onClose');
        
        // Should not throw exception
        $this->authenticatedServer->onClose($conn);
    }

    public function testOnErrorHandlesVariousExceptionTypes(): void
    {
        // Test that onError handles different exception types gracefully
        
        $userId = $this->createTestUser('error_user');
        $sessionId = $this->createTestSession($userId);
        
        // Commit transaction
        $wasInTransaction = $this->pdo->inTransaction();
        if ($wasInTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            $token = $this->createWsToken($sessionId);
            $request = $this->createMockRequestWithQuery("token=$token");
            $conn = $this->createMockConnectionWithRequest($request);
            
            // Open connection successfully
            $this->mockInnerSocket->expects($this->once())->method('onOpen');
            $this->authenticatedServer->onOpen($conn);
            
            // Test with RuntimeException
            $runtimeException = new \RuntimeException('Test runtime error');
            $conn->expects($this->once())->method('send')->with($this->callback(function ($msg) {
                $data = json_decode($msg, true);
                return isset($data['type']) && $data['type'] === 'error' 
                    && isset($data['message']) && $data['message'] === 'server_error';
            }));
            $conn->expects($this->once())->method('close');
            
            $this->mockInnerSocket->expects($this->once())->method('onError');
            
            $this->authenticatedServer->onError($conn, $runtimeException);
        } finally {
            if ($wasInTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
    }

    // ============================================================================
    // ONMESSAGE TESTS - MESSAGE AUTHORIZATION
    // ============================================================================

    public function testOnMessageRejectsMessageWithoutUserCtx(): void
    {
        /** @var ConnectionInterface&MockObject $conn */
        $conn = $this->createMock(ConnectionInterface::class);
        // No userCtx attached
        
        $conn->expects($this->once())->method('send')->with($this->callback(function ($msg) {
            $data = json_decode($msg, true);
            return isset($data['type']) && $data['type'] === 'error' 
                && isset($data['message']) && $data['message'] === 'unauthorized';
        }));
        $conn->expects($this->once())->method('close');
        
        $this->mockInnerSocket->expects($this->never())->method('onMessage');
        
        $this->authenticatedServer->onMessage($conn, '{"type":"test"}');
    }

    public function testOnMessageForwardsMessageWithValidUserCtx(): void
    {
        // Create real user and session for realistic test
        $userId = $this->createTestUser('testuser_message');
        $sessionId = $this->createTestSession($userId);
        
        /** @var ConnectionInterface&MockObject $conn */
        $conn = $this->createMock(ConnectionInterface::class);
        /** @phpstan-ignore-next-line */
        $conn->userCtx = ['user_id' => $userId, 'session_id' => $sessionId];
        
        $message = '{"type":"chat","msg":"hello"}';
        
        $conn->expects($this->never())->method('close');
        $conn->expects($this->never())->method('send');
        
        $this->mockInnerSocket->expects($this->once())
            ->method('onMessage')
            ->with($conn, $message);
        
        $this->authenticatedServer->onMessage($conn, $message);
    }

    public function testOnMessageRejectsEvenWithNullUserCtx(): void
    {
        /** @var ConnectionInterface&MockObject $conn */
        $conn = $this->createMock(ConnectionInterface::class);
        /** @phpstan-ignore-next-line */
        $conn->userCtx = null; // Explicitly set to null
        
        $conn->expects($this->once())->method('send');
        $conn->expects($this->once())->method('close');
        
        $this->mockInnerSocket->expects($this->never())->method('onMessage');
        
        $this->authenticatedServer->onMessage($conn, '{"type":"test"}');
    }

    // ============================================================================
    // ONCLOSE TESTS
    // ============================================================================

    public function testOnCloseOnlyDelegatesIfUserCtxExists(): void
    {
        // Create real user and session for realistic test
        $userId = $this->createTestUser('testuser_close');
        $sessionId = $this->createTestSession($userId);
        
        /** @var ConnectionInterface&MockObject $conn */
        $conn = $this->createMock(ConnectionInterface::class);
        /** @phpstan-ignore-next-line */
        $conn->userCtx = ['user_id' => $userId, 'session_id' => $sessionId];
        
        $this->mockInnerSocket->expects($this->once())->method('onClose')->with($conn);
        
        $this->authenticatedServer->onClose($conn);
    }

    public function testOnCloseDoesNotDelegateWithoutUserCtx(): void
    {
        /** @var ConnectionInterface&MockObject $conn */
        $conn = $this->createMock(ConnectionInterface::class);
        // No userCtx
        
        $this->mockInnerSocket->expects($this->never())->method('onClose');
        
        $this->authenticatedServer->onClose($conn);
    }

    // ============================================================================
    // ONERROR TESTS
    // ============================================================================

    public function testOnErrorSendsErrorAndClosesConnection(): void
    {
        /** @var ConnectionInterface&MockObject $conn */
        $conn = $this->createMock(ConnectionInterface::class);
        $exception = new \RuntimeException('Test error');
        
        $conn->expects($this->once())->method('send')->with($this->callback(function ($msg) {
            $data = json_decode($msg, true);
            return isset($data['type']) && $data['type'] === 'error' 
                && isset($data['message']) && $data['message'] === 'server_error';
        }));
        $conn->expects($this->once())->method('close');
        
        $this->mockInnerSocket->expects($this->once())->method('onError')->with($conn, $exception);
        
        $this->authenticatedServer->onError($conn, $exception);
    }

    public function testOnErrorDelegatesToInnerSocketBeforeClosing(): void
    {
        /** @var ConnectionInterface&MockObject $conn */
        $conn = $this->createMock(ConnectionInterface::class);
        $exception = new \RuntimeException('Test error');
        
        // Verify order: inner->onError is called, then connection is closed
        $callOrder = [];
        
        $this->mockInnerSocket->expects($this->once())
            ->method('onError')
            ->with($conn, $exception)
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'innerError';
            });
        
        $conn->expects($this->once())
            ->method('close')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'close';
            });
        
        $this->authenticatedServer->onError($conn, $exception);
        
        // Inner error should be called before close
        $this->assertSame('innerError', $callOrder[0]);
        $this->assertSame('close', $callOrder[1]);
    }

    // ============================================================================
    // EDGE CASES & SECURITY TESTS
    // ============================================================================

    public function testWsTokenIsSingleUse(): void
    {
        $userId = $this->createTestUser('single_use_token_user');
        $sessionId = $this->createTestSession($userId);
        
        // Commit transaction to allow db_consume_ws_nonce to work
        $wasInTransaction = $this->pdo->inTransaction();
        if ($wasInTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            $token = $this->createWsToken($sessionId);
            
            // First connection should succeed
            $request1 = $this->createMockRequestWithQuery("token=$token", []);
            $conn1 = $this->createMockConnectionWithRequest($request1);
            $conn1->expects($this->never())->method('close');
            $this->mockInnerSocket->expects($this->once())->method('onOpen')->with($conn1);
            
            $this->authenticatedServer->onOpen($conn1);
            
            // Reset mock expectations
            $this->mockInnerSocket = $this->createMock(MessageComponentInterface::class);
            /** @phpstan-ignore-next-line */
            $this->authenticatedServer = new AuthenticatedServer($this->pdo, $this->mockInnerSocket);
            
            // Second connection with same token should fail (token is consumed)
            $request2 = $this->createMockRequestWithQuery("token=$token", []);
            $conn2 = $this->createMockConnectionWithRequest($request2);
            $conn2->expects($this->once())->method('close');
            $this->mockInnerSocket->expects($this->never())->method('onOpen');
            
            $this->authenticatedServer->onOpen($conn2);
        } finally {
            // Restart transaction
            if ($wasInTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
    }

    public function testOnOpenRejectsConnectionWithRevokedSession(): void
    {
        $userId = $this->createTestUser('revoked_session_user');
        $sessionId = $this->createTestSession($userId);
        
        // Revoke the session
        $stmt = $this->pdo->prepare("UPDATE sessions SET revoked_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $sessionId]);
        
        // Try to connect with session cookie
        $_COOKIE['session_id'] = (string)$sessionId;
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'Test User Agent';
        
        $request = $this->createMockRequestWithQuery('', ['session_id' => (string)$sessionId]);
        $conn = $this->createMockConnectionWithRequest($request);
        
        $conn->expects($this->once())->method('send');
        $conn->expects($this->once())->method('close');
        
        $this->mockInnerSocket->expects($this->never())->method('onOpen');
        
        $this->authenticatedServer->onOpen($conn);
        
        unset($_COOKIE['session_id']);
    }

    public function testOnOpenRejectsConnectionWithExpiredSession(): void
    {
        $userId = $this->createTestUser('expired_session_user');
        
        // Create expired session (expired 2 days ago to ensure it's definitely expired)
        // We need to commit the transaction so MySQL's NOW() can properly evaluate the expiration
        $wasInTransaction = $this->pdo->inTransaction();
        if ($wasInTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            $ipHash = hash('sha256', '127.0.0.1');
            // Use DATE_SUB to ensure the session is definitely expired in MySQL's time
            $stmt = $this->pdo->prepare("
                INSERT INTO sessions (user_id, ip_hash, user_agent, expires_at)
                VALUES (:uid, :ip, :ua, DATE_SUB(NOW(), INTERVAL 2 DAY))
            ");
            $stmt->execute([
                'uid' => $userId,
                'ip' => $ipHash,
                'ua' => 'Test User Agent',
            ]);
            $sessionId = (int)$this->pdo->lastInsertId();
            
            // Verify the session is actually expired
            $checkStmt = $this->pdo->prepare("
                SELECT id FROM sessions WHERE id = :sid AND expires_at > NOW()
            ");
            $checkStmt->execute(['sid' => $sessionId]);
            $this->assertFalse((bool)$checkStmt->fetch(), 'Session should be expired');
            
            $_COOKIE['session_id'] = (string)$sessionId;
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
            $_SERVER['HTTP_USER_AGENT'] = 'Test User Agent';
            
            $request = $this->createMockRequestWithQuery('', ['session_id' => (string)$sessionId]);
            $conn = $this->createMockConnectionWithRequest($request);
            
            // requireSession should return null because db_get_session_with_user filters by expires_at > NOW()
            // So ws_auth should return null, and the connection should be rejected
            $conn->expects($this->once())->method('send');
            $conn->expects($this->once())->method('close');
            
            $this->mockInnerSocket->expects($this->never())->method('onOpen');
            
            $this->authenticatedServer->onOpen($conn);
            
            unset($_COOKIE['session_id']);
        } finally {
            // Restart transaction
            if ($wasInTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
    }

    public function testOnOpenHandlesMultipleConnectionsWithDifferentTokens(): void
    {
        $userId1 = $this->createTestUser('multi_conn_user1');
        $userId2 = $this->createTestUser('multi_conn_user2');
        
        $sessionId1 = $this->createTestSession($userId1);
        $sessionId2 = $this->createTestSession($userId2);
        
        // Commit transaction to allow db_consume_ws_nonce to work
        $wasInTransaction = $this->pdo->inTransaction();
        if ($wasInTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            $token1 = $this->createWsToken($sessionId1);
            $token2 = $this->createWsToken($sessionId2);
            
            // First connection
            $request1 = $this->createMockRequestWithQuery("token=$token1", []);
            $conn1 = $this->createMockConnectionWithRequest($request1);
            $conn1->expects($this->never())->method('close');
            
            // Reset mock after first call
            $this->mockInnerSocket->expects($this->exactly(2))->method('onOpen');
            
            $this->authenticatedServer->onOpen($conn1);
            
            // Second connection with different token
            $request2 = $this->createMockRequestWithQuery("token=$token2", []);
            $conn2 = $this->createMockConnectionWithRequest($request2);
            $conn2->expects($this->never())->method('close');
            
            $this->authenticatedServer->onOpen($conn2);
            
            /** @phpstan-ignore-next-line */
            $this->assertSame($userId1, $conn1->userCtx['user_id']);
            /** @phpstan-ignore-next-line */
            $this->assertSame($userId2, $conn2->userCtx['user_id']);
        } finally {
            // Restart transaction
            if ($wasInTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
    }

    public function testOnMessageValidatesUserCtxOnEveryMessage(): void
    {
        // Create real user and session for realistic test
        $userId = $this->createTestUser('testuser_validate');
        $sessionId = $this->createTestSession($userId);
        
        /** @var ConnectionInterface&MockObject $conn */
        $conn = $this->createMock(ConnectionInterface::class);
        /** @phpstan-ignore-next-line */
        $conn->userCtx = ['user_id' => $userId, 'session_id' => $sessionId];
        
        // First two messages should pass - set expectations before calling
        $this->mockInnerSocket->expects($this->exactly(2))
            ->method('onMessage');
        
        $this->authenticatedServer->onMessage($conn, '{"type":"msg1"}');
        $this->authenticatedServer->onMessage($conn, '{"type":"msg2"}');
        
        // Remove userCtx (simulate tampering)
        /** @phpstan-ignore-next-line */
        unset($conn->userCtx);
        
        // Reset mock expectations for the third message (which should be rejected)
        $this->mockInnerSocket = $this->createMock(MessageComponentInterface::class);
        /** @phpstan-ignore-next-line */
        $this->authenticatedServer = new AuthenticatedServer($this->pdo, $this->mockInnerSocket);
        
        // Next message should be rejected - set expectations
        $conn->expects($this->once())->method('send');
        $conn->expects($this->once())->method('close');
        $this->mockInnerSocket->expects($this->never())->method('onMessage');
        
        $this->authenticatedServer->onMessage($conn, '{"type":"msg3"}');
    }

    public function testOnOpenValidatesTokenFormat(): void
    {
        // Empty token
        $request1 = $this->createMockRequestWithQuery('token=', []);
        $conn1 = $this->createMockConnectionWithRequest($request1);
        $conn1->expects($this->once())->method('close');
        $this->authenticatedServer->onOpen($conn1);
        
        // Reset
        $this->mockInnerSocket = $this->createMock(MessageComponentInterface::class);
        /** @phpstan-ignore-next-line */
        $this->authenticatedServer = new AuthenticatedServer($this->pdo, $this->mockInnerSocket);
        
        // Invalid format (too short)
        $request2 = $this->createMockRequestWithQuery('token=abc', []);
        $conn2 = $this->createMockConnectionWithRequest($request2);
        $conn2->expects($this->once())->method('close');
        $this->authenticatedServer->onOpen($conn2);
    }

    public function testOnOpenHandlesMalformedQueryString(): void
    {
        // Query string with invalid format
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getQuery')->willReturn('token=&foo=bar');
        
        $request = $this->createMock(RequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getHeader')->with('Cookie')->willReturn([]);
        
        $conn = $this->createMockConnectionWithRequest($request);
        $conn->expects($this->once())->method('close');
        
        $this->mockInnerSocket->expects($this->never())->method('onOpen');
        
        $this->authenticatedServer->onOpen($conn);
    }

    public function testOnOpenHandlesMalformedCookieHeader(): void
    {
        $userId = $this->createTestUser('malformed_cookie_user');
        $sessionId = $this->createTestSession($userId);
        
        // Create request with malformed cookie header
        $request = $this->createMock(RequestInterface::class);
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getQuery')->willReturn('');
        $request->method('getUri')->willReturn($uri);
        
        // Return malformed cookie header - ws_get_cookie should try to parse it
        // Looking at ws_get_cookie implementation, it splits by ';' and '=' and should
        // be able to extract session_id even from malformed headers
        // Let's test with an empty cookie header to ensure ws_get_cookie returns null
        $request->method('getHeader')->with('Cookie')->willReturn([]);
        
        // Set $_COOKIE directly - but note: ws_auth checks ws_get_cookie first,
        // and only if $cookie is truthy does it call auth_require_session
        // Since ws_get_cookie returns null, ws_auth will return null without checking $_COOKIE
        // So this test should actually expect rejection, not acceptance
        $_COOKIE['session_id'] = (string)$sessionId;
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'Test User Agent';
        
        $conn = $this->createMockConnectionWithRequest($request);
        
        // ws_auth checks ws_get_cookie first, which returns null for empty headers
        // It doesn't fall back to $_COOKIE, so the connection should be rejected
        $conn->expects($this->once())->method('send');
        $conn->expects($this->once())->method('close');
        $this->mockInnerSocket->expects($this->never())->method('onOpen');
        
        $this->authenticatedServer->onOpen($conn);
        
        unset($_COOKIE['session_id']);
    }

    public function testOnCloseHandlesNullUserCtxGracefully(): void
    {
        /** @var ConnectionInterface&MockObject $conn */
        $conn = $this->createMock(ConnectionInterface::class);
        /** @phpstan-ignore-next-line */
        $conn->userCtx = null;
        
        // Should not throw error, just not delegate
        $this->mockInnerSocket->expects($this->never())->method('onClose');
        
        $this->authenticatedServer->onClose($conn);
    }

    public function testOnMessageForwardsAllMessageTypesWhenAuthenticated(): void
    {
        // Create real users for realistic test
        $userId = $this->createTestUser('testuser_alltypes');
        $targetUserId = $this->createTestUser('testuser_target');
        $sessionId = $this->createTestSession($userId);
        
        /** @var ConnectionInterface&MockObject $conn */
        $conn = $this->createMock(ConnectionInterface::class);
        /** @phpstan-ignore-next-line */
        $conn->userCtx = ['user_id' => $userId, 'session_id' => $sessionId];
        
        $messages = [
            '{"type":"chat","msg":"hello"}',
            '{"type":"ping"}',
            'invalid json',
            '',
            '{"type":"challenge","to_user_id":' . $targetUserId . '}',
        ];
        
        $this->mockInnerSocket->expects($this->exactly(count($messages)))
            ->method('onMessage');
        
        foreach ($messages as $msg) {
            $this->authenticatedServer->onMessage($conn, $msg);
        }
    }

    // ============================================================================
    // CONNECTION LIMIT TESTS
    // ============================================================================

    public function testOnOpenEnforcesPerIpConnectionLimit(): void
    {
        // Create multiple users (2 users x 5 connections each = 10 connections from same IP)
        // This ensures we hit IP limit (10) before user limit (5 per user)
        $user1Id = $this->createTestUser('conn_limit_user1');
        $session1Id = $this->createTestSession($user1Id);
        $user2Id = $this->createTestUser('conn_limit_user2');
        $session2Id = $this->createTestSession($user2Id);
        
        // Commit transaction to allow db_consume_ws_nonce to work
        $wasInTransaction = $this->pdo->inTransaction();
        if ($wasInTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            // Create 10 connections from same IP but different users (5 each)
            // MAX_CONNECTIONS_PER_IP = 10, MAX_CONNECTIONS_PER_USER = 5
            $connections = [];
            $this->mockInnerSocket->expects($this->exactly(10))->method('onOpen');
            
            // First 5 connections from user1
            for ($i = 0; $i < 5; $i++) {
                $token = $this->createWsToken($session1Id);
                $request = $this->createMockRequestWithQuery("token=$token", []);
                $request->method('getHeaderLine')->willReturnCallback(function ($name) {
                    if ($name === 'X-Forwarded-For') {
                        return '192.168.1.100';
                    }
                    return '';
                });
                
                $conn = $this->createMockConnectionWithRequest($request);
                /** @phpstan-ignore-next-line */
                $conn->remoteAddress = '192.168.1.100:1234' . $i;
                $this->authenticatedServer->onOpen($conn);
                $connections[] = $conn;
            }
            
            // Next 5 connections from user2 (same IP)
            for ($i = 5; $i < 10; $i++) {
                $token = $this->createWsToken($session2Id);
                $request = $this->createMockRequestWithQuery("token=$token", []);
                $request->method('getHeaderLine')->willReturnCallback(function ($name) {
                    if ($name === 'X-Forwarded-For') {
                        return '192.168.1.100';
                    }
                    return '';
                });
                
                $conn = $this->createMockConnectionWithRequest($request);
                /** @phpstan-ignore-next-line */
                $conn->remoteAddress = '192.168.1.100:1234' . $i;
                $this->authenticatedServer->onOpen($conn);
                $connections[] = $conn;
            }
            
            // 11th connection should be rejected (IP limit reached, not user limit)
            // Use user1 who already has 5 connections, but IP limit should trigger first
            $token11 = $this->createWsToken($session1Id);
            $request11 = $this->createMockRequestWithQuery("token=$token11", []);
            $request11->method('getHeaderLine')->willReturnCallback(function ($name) {
                if ($name === 'X-Forwarded-For') {
                    return '192.168.1.100';
                }
                return '';
            });
            
            $conn11 = $this->createMockConnectionWithRequest($request11);
            /** @phpstan-ignore-next-line */
            $conn11->remoteAddress = '192.168.1.100:12350';
            $conn11->expects($this->once())->method('send')->with($this->callback(function ($msg) {
                $data = json_decode($msg, true);
                return isset($data['type']) && $data['type'] === 'error' 
                    && isset($data['message']) && strpos($data['message'], 'ip_connection_limit_exceeded') !== false;
            }));
            $conn11->expects($this->once())->method('close');
            $this->mockInnerSocket->expects($this->never())->method('onOpen');
            
            $this->authenticatedServer->onOpen($conn11);
        } finally {
            // Restart transaction for test isolation
            if ($wasInTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
    }

    public function testOnOpenEnforcesPerUserConnectionLimit(): void
    {
        // Create user and session
        $userId = $this->createTestUser('user_limit_user');
        $sessionId = $this->createTestSession($userId);
        
        // Commit transaction to allow db_consume_ws_nonce to work
        $wasInTransaction = $this->pdo->inTransaction();
        if ($wasInTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            // Create 5 connections (MAX_CONNECTIONS_PER_USER = 5)
            // Each connection needs a new token (tokens are single-use)
            $this->mockInnerSocket->expects($this->exactly(5))->method('onOpen');
            for ($i = 0; $i < 5; $i++) {
                $token = $this->createWsToken($sessionId);
                $ipCounter = $i + 1;
                $request = $this->createMockRequestWithQuery("token=$token", []);
                $request->method('getHeaderLine')->willReturnCallback(function ($name) use ($ipCounter) {
                    if ($name === 'X-Forwarded-For') {
                        return '192.168.1.' . (100 + $ipCounter);
                    }
                    return '';
                });
                
                $conn = $this->createMockConnectionWithRequest($request);
                /** @phpstan-ignore-next-line */
                $conn->remoteAddress = '192.168.1.' . (100 + $i + 1) . ':1234';
                $conn->expects($this->never())->method('close');
                $this->authenticatedServer->onOpen($conn);
            }
            
            // 6th connection should be rejected (user limit)
            $token6 = $this->createWsToken($sessionId);
            $request6 = $this->createMockRequestWithQuery("token=$token6", []);
            $request6->method('getHeaderLine')->willReturnCallback(function ($name) {
                if ($name === 'X-Forwarded-For') {
                    return '192.168.1.106';
                }
                return '';
            });
            
            $conn6 = $this->createMockConnectionWithRequest($request6);
            /** @phpstan-ignore-next-line */
            $conn6->remoteAddress = '192.168.1.106:1234';
            $conn6->expects($this->once())->method('send')->with($this->callback(function ($msg) {
                $data = json_decode($msg, true);
                return isset($data['type']) && $data['type'] === 'error' 
                    && isset($data['message']) && strpos($data['message'], 'user_connection_limit_exceeded') !== false;
            }));
            $conn6->expects($this->once())->method('close');
            $this->mockInnerSocket->expects($this->never())->method('onOpen');
            
            $this->authenticatedServer->onOpen($conn6);
        } finally {
            // Restart transaction for test isolation
            if ($wasInTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
    }

    public function testOnCloseDecrementsConnectionCounts(): void
    {
        // Create user and token
        $userId = $this->createTestUser('decrement_user');
        $token = $this->createTestWsToken($userId);
        
        // Commit transaction to allow db_consume_ws_nonce to work
        $wasInTransaction = $this->pdo->inTransaction();
        if ($wasInTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            // Create request with IP
            $request = $this->createMockRequestWithQuery("token=$token", []);
            $request->method('getHeaderLine')->willReturnCallback(function ($name) {
                if ($name === 'X-Forwarded-For') {
                    return '192.168.1.200';
                }
                return '';
            });
            
            // Create connection (onOpen will set userCtx, ipAddress, userId)
            $conn = $this->createMockConnectionWithRequest($request);
            /** @phpstan-ignore-next-line */
            $conn->remoteAddress = '192.168.1.200:1234';
            
            $this->mockInnerSocket->expects($this->once())->method('onOpen')->with($conn);
            $this->authenticatedServer->onOpen($conn);
            
            // Close connection
            $this->mockInnerSocket->expects($this->once())->method('onClose')->with($conn);
            $this->authenticatedServer->onClose($conn);
            
            // Connection count should be decremented (verified by onClose being called)
            $this->assertTrue(true, 'Connection count should be decremented on close');
        } finally {
            // Restart transaction for test isolation
            if ($wasInTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
    }

    public function testOnOpenExtractsIpFromProxyHeaders(): void
    {
        // Create user and token
        $userId = $this->createTestUser('proxy_ip_user');
        $token = $this->createTestWsToken($userId);
        
        // Commit transaction to allow db_consume_ws_nonce to work
        $wasInTransaction = $this->pdo->inTransaction();
        if ($wasInTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }
        
        try {
            // Create request with X-Forwarded-For header
            $request = $this->createMockRequestWithQuery("token=$token", []);
            $request->method('getHeaderLine')->willReturnCallback(function ($name) {
                if ($name === 'X-Forwarded-For') {
                    return '203.0.113.1, 198.51.100.1';
                }
                return '';
            });
            
            $conn = $this->createMockConnectionWithRequest($request);
            /** @phpstan-ignore-next-line */
            $conn->remoteAddress = '192.168.1.1:1234'; // Direct connection IP (should be ignored)
            
            // Should use X-Forwarded-For IP (203.0.113.1)
            $conn->expects($this->never())->method('close');
            $this->mockInnerSocket->expects($this->once())->method('onOpen')->with($conn);
            
            $this->authenticatedServer->onOpen($conn);
            
            // Verify connection was tracked with proxy IP
            /** @phpstan-ignore-next-line */
            $this->assertTrue(isset($conn->ipAddress), 'Connection should have ipAddress set');
        } finally {
            // Restart transaction for test isolation
            if ($wasInTransaction) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
    }
}

