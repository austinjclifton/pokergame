<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
require_once __DIR__ . '/../../helpers/WebSocketTestDoubles.php';

final class AuthTestInnerServer implements MessageComponentInterface
{
    /** @var list<array{event:string, args:array<int, mixed>}> */
    public array $events = [];

    public ?Throwable $openException = null;
    public ?Throwable $authenticatedException = null;
    public ?Throwable $messageException = null;
    public ?Throwable $closeException = null;
    public ?Throwable $errorException = null;

    public function onOpen(ConnectionInterface $conn): void
    {
        $this->events[] = ['event' => 'onOpen', 'args' => [$conn]];
        if ($this->openException !== null) {
            throw $this->openException;
        }
    }

    public function onAuthenticated(ConnectionInterface $conn): void
    {
        $this->events[] = ['event' => 'onAuthenticated', 'args' => [$conn]];
        if ($this->authenticatedException !== null) {
            throw $this->authenticatedException;
        }
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $this->events[] = ['event' => 'onMessage', 'args' => [$from, $msg]];
        if ($this->messageException !== null) {
            throw $this->messageException;
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $this->events[] = ['event' => 'onClose', 'args' => [$conn]];
        if ($this->closeException !== null) {
            throw $this->closeException;
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        $this->events[] = ['event' => 'onError', 'args' => [$conn, $e]];
        if ($this->errorException !== null) {
            throw $this->errorException;
        }
    }

    /**
     * @return list<string>
     */
    public function eventNames(): array
    {
        return array_map(static fn(array $entry): string => $entry['event'], $this->events);
    }
}

final class AuthenticatedServerTest extends TestCase
{
    private PDO $pdo;
    private bool $inTransaction = false;
    private int $resourceCounter = 1;
    private AuthTestInnerServer $inner;
    private AuthenticatedServer $authenticatedServer;

    protected function setUp(): void
    {
        global $pdo;

        if (!isset($pdo) && isset($GLOBALS['pdo'])) {
            $pdo = $GLOBALS['pdo'];
        }

        if (!isset($pdo) || !($pdo instanceof PDO)) {
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
        }

        $this->pdo = $pdo;

        require_once __DIR__ . '/../../../ws/AuthenticatedServer.php';
        require_once __DIR__ . '/../../../app/db/nonces.php';
        require_once __DIR__ . '/../../../app/db/sessions.php';
        require_once __DIR__ . '/../../../app/db/users.php';

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo->beginTransaction();
        $this->inTransaction = true;

        $this->inner = new AuthTestInnerServer();
        $this->authenticatedServer = new AuthenticatedServer($this->pdo, $this->inner);
    }

    protected function tearDown(): void
    {
        if ($this->inTransaction && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
            $this->inTransaction = false;
        }

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * @return array{id:int, username:string}
     */
    private function createTestUser(string $baseUsername): array
    {
        $username = sprintf('%s_%s_%s', $baseUsername, time(), bin2hex(random_bytes(3)));
        $email = $username . '@test.com';

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (username, email, password_hash) VALUES (:username, :email, :password_hash)'
        );
        $stmt->execute([
            'username' => $username,
            'email' => $email,
            'password_hash' => password_hash('testpass123', PASSWORD_DEFAULT),
        ]);

        return [
            'id' => (int) $this->pdo->lastInsertId(),
            'username' => $username,
        ];
    }

    private function createTestSession(int $userId): int
    {
        return db_insert_session(
            $this->pdo,
            $userId,
            hash('sha256', '127.0.0.1'),
            'AuthenticatedServerTest',
            date('Y-m-d H:i:s', strtotime('+1 day'))
        );
    }

    private function createWsToken(int $sessionId, int $ttlSeconds = 30): string
    {
        return db_create_ws_nonce($this->pdo, $sessionId, $ttlSeconds);
    }

    private function createConnection(?RequestInterface $request = null): WebSocketTestConnection
    {
        return new WebSocketTestConnection($this->resourceCounter++, $request);
    }

    /**
     * @param array<string, string> $cookies
     */
    private function createRequest(string $queryString = '', array $cookies = []): WebSocketTestRequest
    {
        $headers = [];
        if ($cookies !== []) {
            $pairs = [];
            foreach ($cookies as $name => $value) {
                $pairs[] = $name . '=' . urlencode($value);
            }
            $headers['Cookie'] = [implode('; ', $pairs)];
        }

        return new WebSocketTestRequest('/socket', $queryString, $headers);
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function withCommittedState(callable $callback)
    {
        $hadTransaction = $this->inTransaction && $this->pdo->inTransaction();
        if ($hadTransaction) {
            $this->pdo->commit();
            $this->inTransaction = false;
        }

        try {
            return $callback();
        } finally {
            if ($hadTransaction && !$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
                $this->inTransaction = true;
            }
        }
    }

    private function createServer(string $channelType = 'generic', ?PDO $pdo = null): AuthenticatedServer
    {
        return new AuthenticatedServer($pdo ?? $this->pdo, $this->inner, $channelType);
    }

    private function assertLastError(WebSocketTestConnection $conn, string $error): void
    {
        $this->assertNotEmpty($conn->sentMessages, 'Expected an error payload to be sent');
        $decoded = json_decode($conn->sentMessages[array_key_last($conn->sentMessages)], true);
        $this->assertIsArray($decoded);
        $this->assertSame('error', $decoded['type'] ?? null);
        $this->assertSame($error, $decoded['error'] ?? null);
    }

    public function testOnOpenRejectsConnectionWithoutHttpRequest(): void
    {
        $conn = $this->createConnection();

        $this->authenticatedServer->onOpen($conn);

        $this->assertTrue($conn->isClosed);
        $this->assertLastError($conn, 'unauthorized');
        $this->assertSame([], $this->inner->eventNames());
    }

    public function testOnOpenRejectsConnectionWithoutTokenOrCookie(): void
    {
        $conn = $this->createConnection($this->createRequest());

        $this->authenticatedServer->onOpen($conn);

        $this->assertTrue($conn->isClosed);
        $this->assertLastError($conn, 'unauthorized');
        $this->assertSame([], $this->inner->eventNames());
    }

    public function testOnOpenAcceptsValidWsTokenAndForwardsLifecycleInOrder(): void
    {
        $user = $this->createTestUser('token_user');
        $sessionId = $this->createTestSession($user['id']);

        $this->withCommittedState(function () use ($sessionId, $user): void {
            $token = $this->createWsToken($sessionId);
            $conn = $this->createConnection($this->createRequest('token=' . $token));

            $this->authenticatedServer->onOpen($conn);

            $this->assertFalse($conn->isClosed);
            $this->assertSame([], $conn->sentMessages);
            $this->assertSame($user['id'], $conn->userCtx['user_id'] ?? null);
            $this->assertSame($user['username'], $conn->userCtx['username'] ?? null);
            $this->assertSame($sessionId, $conn->userCtx['session_id'] ?? null);
            $this->assertSame($token, $conn->userCtx['token'] ?? null);
            $this->assertSame('generic', $conn->userCtx['channel'] ?? null);
            $this->assertSame(['onOpen', 'onAuthenticated'], $this->inner->eventNames());
        });
    }

    public function testOnOpenPrefersValidTokenOverCookieFallback(): void
    {
        $tokenUser = $this->createTestUser('token_preferred');
        $cookieUser = $this->createTestUser('cookie_secondary');
        $tokenSessionId = $this->createTestSession($tokenUser['id']);
        $cookieSessionId = $this->createTestSession($cookieUser['id']);

        $this->withCommittedState(function () use ($tokenSessionId, $cookieSessionId, $tokenUser): void {
            $token = $this->createWsToken($tokenSessionId);
            $conn = $this->createConnection($this->createRequest(
                'token=' . $token,
                ['session_id' => (string) $cookieSessionId]
            ));

            $this->authenticatedServer->onOpen($conn);

            $this->assertFalse($conn->isClosed);
            $this->assertSame($tokenUser['id'], $conn->userCtx['user_id'] ?? null);
        });
    }

    public function testOnOpenRejectsInvalidTokenEvenWhenCookieIsPresent(): void
    {
        $cookieUser = $this->createTestUser('cookie_user');
        $cookieSessionId = $this->createTestSession($cookieUser['id']);

        $this->withCommittedState(function () use ($cookieSessionId): void {
            $conn = $this->createConnection($this->createRequest(
                'token=invalid_token_12345',
                ['session_id' => (string) $cookieSessionId]
            ));

            $this->authenticatedServer->onOpen($conn);

            $this->assertTrue($conn->isClosed);
            $this->assertLastError($conn, 'unauthorized');
            $this->assertSame([], $this->inner->eventNames());
        });
    }

    public function testOnOpenAcceptsValidSessionCookieForNonGameChannel(): void
    {
        $user = $this->createTestUser('cookie_user');
        $sessionId = $this->createTestSession($user['id']);
        $conn = $this->createConnection($this->createRequest('', ['session_id' => (string) $sessionId]));

        $this->authenticatedServer->onOpen($conn);

        $this->assertFalse($conn->isClosed);
        $this->assertSame($user['id'], $conn->userCtx['user_id'] ?? null);
        $this->assertSame($user['username'], $conn->userCtx['username'] ?? null);
        $this->assertSame($sessionId, $conn->userCtx['session_id'] ?? null);
        $this->assertSame('', $conn->userCtx['token'] ?? null);
        $this->assertSame(['onOpen', 'onAuthenticated'], $this->inner->eventNames());
    }

    public function testOnOpenRejectsSessionCookieForGameChannel(): void
    {
        $user = $this->createTestUser('game_cookie_user');
        $sessionId = $this->createTestSession($user['id']);
        $server = $this->createServer('game');
        $conn = $this->createConnection($this->createRequest('', ['session_id' => (string) $sessionId]));

        $server->onOpen($conn);

        $this->assertTrue($conn->isClosed);
        $this->assertLastError($conn, 'unauthorized');
        $this->assertSame([], $this->inner->eventNames());
    }

    public function testOnOpenRejectsRevokedSessionCookie(): void
    {
        $user = $this->createTestUser('revoked_session_user');
        $sessionId = $this->createTestSession($user['id']);
        $stmt = $this->pdo->prepare('UPDATE sessions SET revoked_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $sessionId]);

        $conn = $this->createConnection($this->createRequest('', ['session_id' => (string) $sessionId]));
        $this->authenticatedServer->onOpen($conn);

        $this->assertTrue($conn->isClosed);
        $this->assertLastError($conn, 'unauthorized');
    }

    public function testOnOpenRejectsExpiredSessionCookie(): void
    {
        $user = $this->createTestUser('expired_session_user');
        $stmt = $this->pdo->prepare(
            'INSERT INTO sessions (user_id, expires_at, ip_hash, user_agent) VALUES (:uid, DATE_SUB(NOW(), INTERVAL 2 DAY), :ip, :ua)'
        );
        $stmt->execute([
            'uid' => $user['id'],
            'ip' => hash('sha256', '127.0.0.1'),
            'ua' => 'AuthenticatedServerTest',
        ]);
        $sessionId = (int) $this->pdo->lastInsertId();

        $conn = $this->createConnection($this->createRequest('', ['session_id' => (string) $sessionId]));
        $this->authenticatedServer->onOpen($conn);

        $this->assertTrue($conn->isClosed);
        $this->assertLastError($conn, 'unauthorized');
    }

    public function testWsTokenIsSingleUse(): void
    {
        $user = $this->createTestUser('single_use_token_user');
        $sessionId = $this->createTestSession($user['id']);

        $this->withCommittedState(function () use ($sessionId): void {
            $token = $this->createWsToken($sessionId);

            $firstConn = $this->createConnection($this->createRequest('token=' . $token));
            $this->authenticatedServer->onOpen($firstConn);
            $this->assertFalse($firstConn->isClosed);
            $this->assertSame(['onOpen', 'onAuthenticated'], $this->inner->eventNames());

            $secondConn = $this->createConnection($this->createRequest('token=' . $token));
            $this->authenticatedServer->onOpen($secondConn);
            $this->assertTrue($secondConn->isClosed);
            $this->assertLastError($secondConn, 'unauthorized');
        });
    }

    public function testOnOpenSendsServerErrorWhenRequestParsingThrows(): void
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getQuery')->willThrowException(new RuntimeException('query exploded'));

        $request = $this->createMock(RequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $conn = $this->createConnection($request);
        $this->authenticatedServer->onOpen($conn);

        $this->assertTrue($conn->isClosed);
        $this->assertLastError($conn, 'server_error');
        $this->assertSame([], $this->inner->eventNames());
    }

    public function testOnOpenSendsServerErrorWhenInnerOnOpenThrows(): void
    {
        $user = $this->createTestUser('inner_open_error_user');
        $sessionId = $this->createTestSession($user['id']);
        $this->inner->openException = new RuntimeException('inner open failed');

        $this->withCommittedState(function () use ($sessionId): void {
            $token = $this->createWsToken($sessionId);
            $conn = $this->createConnection($this->createRequest('token=' . $token));

            $this->authenticatedServer->onOpen($conn);

            $this->assertTrue($conn->isClosed);
            $this->assertLastError($conn, 'server_error');
            $this->assertSame(['onOpen'], $this->inner->eventNames());
        });
    }

    public function testOnOpenSwallowsOnAuthenticatedExceptionAfterSuccessfulOpen(): void
    {
        $user = $this->createTestUser('inner_auth_error_user');
        $sessionId = $this->createTestSession($user['id']);
        $this->inner->authenticatedException = new RuntimeException('inner auth failed');

        $this->withCommittedState(function () use ($sessionId): void {
            $token = $this->createWsToken($sessionId);
            $conn = $this->createConnection($this->createRequest('token=' . $token));

            $this->authenticatedServer->onOpen($conn);

            $this->assertFalse($conn->isClosed);
            $this->assertSame([], $conn->sentMessages);
            $this->assertSame(['onOpen', 'onAuthenticated'], $this->inner->eventNames());
        });
    }

    public function testOnMessageRejectsMessageWithoutUserCtx(): void
    {
        $conn = $this->createConnection();

        $this->authenticatedServer->onMessage($conn, '{"type":"ping"}');

        $this->assertTrue($conn->isClosed);
        $this->assertLastError($conn, 'unauthorized');
        $this->assertSame([], $this->inner->eventNames());
    }

    public function testOnMessageForwardsAuthenticatedMessages(): void
    {
        $conn = $this->createConnection();
        $conn->userCtx = ['user_id' => 123, 'session_id' => 456];

        $this->authenticatedServer->onMessage($conn, '{"type":"ping"}');

        $this->assertFalse($conn->isClosed);
        $this->assertSame([], $conn->sentMessages);
        $this->assertSame(['onMessage'], $this->inner->eventNames());
    }

    public function testOnMessageSwallowsInnerExceptions(): void
    {
        $conn = $this->createConnection();
        $conn->userCtx = ['user_id' => 123, 'session_id' => 456];
        $this->inner->messageException = new RuntimeException('inner message failed');

        $this->authenticatedServer->onMessage($conn, '{"type":"ping"}');

        $this->assertFalse($conn->isClosed);
        $this->assertSame([], $conn->sentMessages);
        $this->assertSame(['onMessage'], $this->inner->eventNames());
    }

    public function testOnCloseOnlyDelegatesWhenUserCtxExists(): void
    {
        $authorizedConn = $this->createConnection();
        $authorizedConn->userCtx = ['user_id' => 1, 'session_id' => 2];
        $unauthorizedConn = $this->createConnection();

        $this->authenticatedServer->onClose($authorizedConn);
        $this->authenticatedServer->onClose($unauthorizedConn);

        $this->assertSame(['onClose'], $this->inner->eventNames());
    }

    public function testOnErrorSendsServerErrorDelegatesAndCloses(): void
    {
        $conn = $this->createConnection();
        $conn->userCtx = ['user_id' => 42, 'session_id' => 7];

        $this->authenticatedServer->onError($conn, new RuntimeException('transport failure'));

        $this->assertTrue($conn->isClosed);
        $this->assertLastError($conn, 'server_error');
        $this->assertSame(['onError'], $this->inner->eventNames());
    }

    public function testOnErrorStillClosesWhenInnerOnErrorThrows(): void
    {
        $conn = $this->createConnection();
        $this->inner->errorException = new RuntimeException('inner error failed');

        $this->authenticatedServer->onError($conn, new RuntimeException('transport failure'));

        $this->assertTrue($conn->isClosed);
        $this->assertLastError($conn, 'server_error');
        $this->assertSame(['onError'], $this->inner->eventNames());
    }
}