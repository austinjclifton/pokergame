<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * Integration tests for GameSocket WebSocket handler.
 * 
 * Tests:
 *  - Player authentication via ws_token
 *  - Action flow (bet/check/fold) updates state for all connections
 *  - Private vs public state separation
 *  - Reconnect scenario returns consistent state
 * 
 * @coversNothing
 */
class TestGameConnection implements ConnectionInterface
{
    public $resourceId;
    public $userCtx;
    public $httpRequest;
    public array $sentMessages = [];
    public bool $isClosed = false;
    
    public function __construct(int $resourceId, array $userCtx, $httpRequest = null)
    {
        $this->resourceId = $resourceId;
        $this->userCtx = $userCtx;
        $this->httpRequest = $httpRequest;
    }
    
    public function send($data): void
    {
        if ($this->isClosed) {
            throw new \RuntimeException('Cannot send message on closed connection');
        }
        $this->sentMessages[] = $data;
    }
    
    public function close(): void
    {
        $this->isClosed = true;
    }
}

class TestRequest implements RequestInterface
{
    private UriInterface $uri;
    
    public function __construct(string $queryString)
    {
        $this->uri = new class($queryString) implements UriInterface {
            private string $query;
            
            public function __construct(string $query)
            {
                $this->query = $query;
            }
            
            public function getQuery(): string { return $this->query; }
            public function getScheme(): string { return 'ws'; }
            public function getAuthority(): string { return ''; }
            public function getUserInfo(): string { return ''; }
            public function getHost(): string { return ''; }
            public function getPort(): ?int { return null; }
            public function getPath(): string { return '/game'; }
            public function getFragment(): string { return ''; }
            public function withScheme(string $scheme): UriInterface { return $this; }
            public function withUserInfo(string $user, ?string $password = null): UriInterface { return $this; }
            public function withHost(string $host): UriInterface { return $this; }
            public function withPort(?int $port): UriInterface { return $this; }
            public function withPath(string $path): UriInterface { return $this; }
            public function withQuery(string $query): UriInterface { return $this; }
            public function withFragment(string $fragment): UriInterface { return $this; }
            public function __toString(): string { return ''; }
        };
    }
    
    public function getRequestTarget(): string { return ''; }
    public function withRequestTarget($requestTarget): RequestInterface { return $this; }
    public function getMethod(): string { return 'GET'; }
    public function withMethod($method): RequestInterface { return $this; }
    public function getUri(): UriInterface { return $this->uri; }
    public function withUri(UriInterface $uri, $preserveHost = false): RequestInterface { return $this; }
    public function getProtocolVersion(): string { return '1.1'; }
    public function withProtocolVersion($version): RequestInterface { return $this; }
    public function getHeaders(): array { return []; }
    public function hasHeader($name): bool { return false; }
    public function getHeader($name): array { return []; }
    public function getHeaderLine($name): string { return ''; }
    public function withHeader($name, $value): RequestInterface { return $this; }
    public function withAddedHeader($name, $value): RequestInterface { return $this; }
    public function withoutHeader($name): RequestInterface { return $this; }
    public function getBody(): \Psr\Http\Message\StreamInterface { return new class implements \Psr\Http\Message\StreamInterface {
        public function __toString(): string { return ''; }
        public function close(): void {}
        public function detach() { return null; }
        public function getSize(): ?int { return null; }
        public function tell(): int { return 0; }
        public function eof(): bool { return true; }
        public function isSeekable(): bool { return false; }
        public function seek($offset, $whence = SEEK_SET): void {}
        public function rewind(): void {}
        public function isWritable(): bool { return false; }
        public function write($string): int { return 0; }
        public function isReadable(): bool { return false; }
        public function read($length): string { return ''; }
        public function getContents(): string { return ''; }
        public function getMetadata($key = null) { return null; }
    }; }
    public function withBody(\Psr\Http\Message\StreamInterface $body): RequestInterface { return $this; }
}

final class GameSocketTest extends TestCase
{
    private PDO $pdo;
    private bool $inTransaction = false;
    private $gameSocket;
    private int $gameId;
    private int $userId1;
    private int $userId2;

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
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        $this->pdo->beginTransaction();
        $this->inTransaction = true;

        // Load required files
        require_once __DIR__ . '/../../../ws/GameSocket.php';
        require_once __DIR__ . '/../../../app/services/game/GameService.php';
        require_once __DIR__ . '/../../../app/services/game/cards/DealerService.php';
        require_once __DIR__ . '/../../../app/services/game/cards/HandEvaluator.php';
        require_once __DIR__ . '/../../../app/db/games.php';
        require_once __DIR__ . '/../../../app/db/users.php';

        // Create test users
        $this->userId1 = $this->createTestUser('player1');
        $this->userId2 = $this->createTestUser('player2');

        // Create test game
        $this->gameId = db_create_game($this->pdo, $this->userId1, $this->userId2);

        // Initialize GameSocket
        $this->gameSocket = new GameSocket($this->pdo);
    }

    protected function tearDown(): void
    {
        if ($this->inTransaction && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
            $this->inTransaction = false;
        }
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    }

    private function createTestUser(string $username): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO users (username, email, password_hash, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$username, "{$username}@test.com", password_hash('test', PASSWORD_DEFAULT)]);
        return (int)$this->pdo->lastInsertId();
    }

    private function createConnection(int $resourceId, int $userId, int $gameId): TestGameConnection
    {
        $userCtx = [
            'user_id' => $userId,
            'session_id' => 1,
        ];
        
        $request = new TestRequest("game_id={$gameId}");
        $conn = new TestGameConnection($resourceId, $userCtx, $request);
        return $conn;
    }

    /**
     * Test player authentication via ws_token
     */
    public function testPlayerAuthentication(): void
    {
        $conn = $this->createConnection(1, $this->userId1, $this->gameId);
        $this->gameSocket->onOpen($conn);

        // Should receive state sync messages
        $this->assertGreaterThan(0, count($conn->sentMessages), 'Should receive messages on connect');
        
        $hasStateSync = false;
        foreach ($conn->sentMessages as $msg) {
            $data = json_decode($msg, true);
            if ($data && isset($data['type']) && $data['type'] === 'STATE_SYNC') {
                $hasStateSync = true;
                $this->assertEquals($this->gameId, $data['game_id']);
                $this->assertArrayHasKey('state', $data);
                break;
            }
        }
        
        $this->assertTrue($hasStateSync, 'Should receive STATE_SYNC message');
    }

    /**
     * Test that unauthorized connection is rejected
     */
    public function testUnauthorizedConnectionRejected(): void
    {
        $conn = new TestGameConnection(1, []); // No userCtx
        $this->gameSocket->onOpen($conn);

        $this->assertTrue($conn->isClosed, 'Unauthorized connection should be closed');
        $this->assertGreaterThan(0, count($conn->sentMessages), 'Should receive error message');
        
        $errorFound = false;
        foreach ($conn->sentMessages as $msg) {
            $data = json_decode($msg, true);
            if ($data && isset($data['type']) && $data['type'] === 'error') {
                $errorFound = true;
                break;
            }
        }
        $this->assertTrue($errorFound, 'Should receive error message');
    }

    /**
     * Test action flow updates state for all connections
     */
    public function testActionFlowUpdatesStateForAllConnections(): void
    {
        // Create two connections (two players)
        $conn1 = $this->createConnection(1, $this->userId1, $this->gameId);
        $conn2 = $this->createConnection(2, $this->userId2, $this->gameId);
        
        $this->gameSocket->onOpen($conn1);
        $this->gameSocket->onOpen($conn2);

        // Clear initial messages
        $conn1->sentMessages = [];
        $conn2->sentMessages = [];

        // Player 1 (seat 1) sends a check action
        $actionMsg = json_encode([
            'cmd' => 'action',
            'action' => 'check',
            'amount' => 0,
            'game_version' => 0,
        ]);
        
        $this->gameSocket->onMessage($conn1, $actionMsg);

        // Both connections should receive state updates
        $conn1ReceivedUpdate = false;
        $conn2ReceivedUpdate = false;

        foreach ($conn1->sentMessages as $msg) {
            $data = json_decode($msg, true);
            if ($data && isset($data['type']) && $data['type'] === 'STATE_DIFF') {
                $conn1ReceivedUpdate = true;
                $this->assertArrayHasKey('state', $data);
                $this->assertArrayHasKey('version', $data);
                break;
            }
        }

        foreach ($conn2->sentMessages as $msg) {
            $data = json_decode($msg, true);
            if ($data && isset($data['type']) && $data['type'] === 'STATE_DIFF') {
                $conn2ReceivedUpdate = true;
                $this->assertArrayHasKey('state', $data);
                break;
            }
        }

        $this->assertTrue($conn1ReceivedUpdate, 'Player 1 should receive state update');
        $this->assertTrue($conn2ReceivedUpdate, 'Player 2 should receive state update');
    }

    /**
     * Test private vs public state separation
     */
    public function testPrivateVsPublicStateSeparation(): void
    {
        $conn1 = $this->createConnection(1, $this->userId1, $this->gameId);
        $this->gameSocket->onOpen($conn1);

        // Find STATE_PRIVATE message
        $privateState = null;
        $publicState = null;

        foreach ($conn1->sentMessages as $msg) {
            $data = json_decode($msg, true);
            if ($data && isset($data['type'])) {
                if ($data['type'] === 'STATE_PRIVATE') {
                    $privateState = $data['state'];
                } elseif ($data['type'] === 'STATE_SYNC') {
                    $publicState = $data['state'];
                }
            }
        }

        $this->assertNotNull($privateState, 'Should receive private state');
        $this->assertNotNull($publicState, 'Should receive public state');

        // Private state should have hole cards
        $this->assertArrayHasKey('myCards', $privateState, 'Private state should have myCards');
        $this->assertArrayHasKey('legalActions', $privateState, 'Private state should have legalActions');

        // Public state should NOT have hole cards
        $this->assertArrayHasKey('players', $publicState, 'Public state should have players');
        if (isset($publicState['players'][1])) {
            $this->assertArrayNotHasKey('cards', $publicState['players'][1], 'Public state should not have hole cards');
        }
    }

    /**
     * Test reconnect scenario returns consistent state
     */
    public function testReconnectReturnsConsistentState(): void
    {
        // Initial connection
        $conn1 = $this->createConnection(1, $this->userId1, $this->gameId);
        $this->gameSocket->onOpen($conn1);

        // Get initial state
        $initialState = null;
        foreach ($conn1->sentMessages as $msg) {
            $data = json_decode($msg, true);
            if ($data && isset($data['type']) && $data['type'] === 'STATE_SYNC') {
                $initialState = $data['state'];
                break;
            }
        }

        $this->assertNotNull($initialState, 'Should receive initial state');

        // Disconnect
        $this->gameSocket->onClose($conn1);

        // Reconnect
        $conn2 = $this->createConnection(2, $this->userId1, $this->gameId);
        $this->gameSocket->onOpen($conn2);

        // Get reconnected state
        $reconnectedState = null;
        foreach ($conn2->sentMessages as $msg) {
            $data = json_decode($msg, true);
            if ($data && isset($data['type']) && $data['type'] === 'STATE_SYNC') {
                $reconnectedState = $data['state'];
                break;
            }
        }

        $this->assertNotNull($reconnectedState, 'Should receive state on reconnect');
        $this->assertEquals($initialState['phase'], $reconnectedState['phase'], 'Phase should be consistent');
        $this->assertEquals($initialState['pot'], $reconnectedState['pot'], 'Pot should be consistent');
    }

    /**
     * Test invalid action is rejected
     */
    public function testInvalidActionRejected(): void
    {
        $conn = $this->createConnection(1, $this->userId1, $this->gameId);
        $this->gameSocket->onOpen($conn);

        // Send invalid action
        $actionMsg = json_encode([
            'cmd' => 'action',
            'action' => 'invalid_action',
            'amount' => 0,
        ]);
        
        $this->gameSocket->onMessage($conn, $actionMsg);

        // Should receive error
        $errorFound = false;
        foreach ($conn->sentMessages as $msg) {
            $data = json_decode($msg, true);
            if ($data && isset($data['type']) && $data['type'] === 'error') {
                $errorFound = true;
                break;
            }
        }

        $this->assertTrue($errorFound, 'Should receive error for invalid action');
    }

    /**
     * Test ping/pong heartbeat
     */
    public function testPingPong(): void
    {
        $conn = $this->createConnection(1, $this->userId1, $this->gameId);
        $this->gameSocket->onOpen($conn);

        $conn->sentMessages = [];
        
        $pingMsg = json_encode(['cmd' => 'ping']);
        $this->gameSocket->onMessage($conn, $pingMsg);

        $pongFound = false;
        foreach ($conn->sentMessages as $msg) {
            $data = json_decode($msg, true);
            if ($data && isset($data['type']) && $data['type'] === 'pong') {
                $pongFound = true;
                break;
            }
        }

        $this->assertTrue($pongFound, 'Should receive pong for ping');
    }
}

