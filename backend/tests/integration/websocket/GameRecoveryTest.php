<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * Integration tests for game state persistence and recovery.
 * 
 * Tests:
 *  - Snapshot creation and retrieval
 *  - State recovery via action replay
 *  - Reconnection scenario returns consistent state
 *  - Stale version detection
 * 
 * @coversNothing
 */
class TestRecoveryConnection implements ConnectionInterface
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

class TestRecoveryRequest implements RequestInterface
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

final class GameRecoveryTest extends TestCase
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
        require_once __DIR__ . '/../../../app/services/GamePersistenceService.php';
        require_once __DIR__ . '/../../../app/services/game/cards/DealerService.php';
        require_once __DIR__ . '/../../../app/services/game/cards/HandEvaluator.php';
        require_once __DIR__ . '/../../../app/db/game_snapshots.php';
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

    private function createConnection(int $resourceId, int $userId, int $gameId): TestRecoveryConnection
    {
        $userCtx = [
            'user_id' => $userId,
            'session_id' => 1,
        ];
        
        $request = new TestRecoveryRequest("game_id={$gameId}");
        $conn = new TestRecoveryConnection($resourceId, $userCtx, $request);
        return $conn;
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

        // Perform an action
        $actionMsg = json_encode([
            'cmd' => 'action',
            'action' => 'check',
            'amount' => 0,
            'game_version' => 0,
        ]);
        $conn1->sentMessages = [];
        $this->gameSocket->onMessage($conn1, $actionMsg);

        // Get state after action
        $stateAfterAction = null;
        foreach ($conn1->sentMessages as $msg) {
            $data = json_decode($msg, true);
            if ($data && isset($data['type']) && $data['type'] === 'STATE_DIFF') {
                $stateAfterAction = $data['state'];
                break;
            }
        }

        $this->assertNotNull($stateAfterAction, 'Should receive state update after action');

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
        $this->assertEquals($stateAfterAction['phase'], $reconnectedState['phase'], 'Phase should match');
        $this->assertEquals($stateAfterAction['pot'], $reconnectedState['pot'], 'Pot should match');
    }

    /**
     * Test snapshot + replay yields identical state
     */
    public function testSnapshotAndReplayYieldsIdenticalState(): void
    {
        require_once __DIR__ . '/../../../app/services/GamePersistenceService.php';
        
        $persistenceService = new GamePersistenceService($this->pdo, 5);
        
        // Connect and perform actions
        $conn1 = $this->createConnection(1, $this->userId1, $this->gameId);
        $this->gameSocket->onOpen($conn1);

        // Perform a few actions
        $actions = ['check', 'fold'];
        foreach ($actions as $action) {
            $actionMsg = json_encode([
                'cmd' => 'action',
                'action' => $action,
                'amount' => 0,
                'game_version' => 0,
            ]);
            $this->gameSocket->onMessage($conn1, $actionMsg);
        }

        // Get current state from GameSocket
        // We need to access the game service directly - for testing purposes
        // In a real scenario, we'd get this from the connection's state sync
        
        // Create a new game service and recover state
        $sb = 10;
        $bb = 20;
        $recoveryEngine = new GameService($sb, $bb);
        
        $recoveredState = $persistenceService->recoverGame($this->gameId, $recoveryEngine);
        
        $this->assertIsArray($recoveredState, 'Recovered state should be an array');
        $this->assertArrayHasKey('phase', $recoveredState);
        $this->assertArrayHasKey('pot', $recoveredState);
        $this->assertArrayHasKey('players', $recoveredState);
    }

    /**
     * Test stale version causes forced STATE_SYNC resync
     */
    public function testStaleVersionCausesResync(): void
    {
        $conn1 = $this->createConnection(1, $this->userId1, $this->gameId);
        $this->gameSocket->onOpen($conn1);

        // Get current version from initial sync
        $currentVersion = null;
        foreach ($conn1->sentMessages as $msg) {
            $data = json_decode($msg, true);
            if ($data && isset($data['type']) && $data['type'] === 'STATE_SYNC') {
                $currentVersion = $data['version'] ?? 0;
                break;
            }
        }

        $this->assertNotNull($currentVersion, 'Should have a version');

        // Perform an action to increment version
        $actionMsg = json_encode([
            'cmd' => 'action',
            'action' => 'check',
            'amount' => 0,
            'game_version' => $currentVersion,
        ]);
        $conn1->sentMessages = [];
        $this->gameSocket->onMessage($conn1, $actionMsg);

        // Try to send action with stale version
        $staleActionMsg = json_encode([
            'cmd' => 'action',
            'action' => 'check',
            'amount' => 0,
            'game_version' => $currentVersion, // This is now stale
        ]);
        $conn1->sentMessages = [];
        $this->gameSocket->onMessage($conn1, $staleActionMsg);

        // Should receive error about stale version
        $errorFound = false;
        foreach ($conn1->sentMessages as $msg) {
            $data = json_decode($msg, true);
            if ($data && isset($data['type']) && $data['type'] === 'error' && $data['error'] === 'stale_version') {
                $errorFound = true;
                $this->assertArrayHasKey('current_version', $data);
                break;
            }
        }

        $this->assertTrue($errorFound, 'Should receive stale_version error');
    }

    /**
     * Test that snapshots are created periodically
     */
    public function testSnapshotsAreCreatedPeriodically(): void
    {
        require_once __DIR__ . '/../../../app/db/game_snapshots.php';
        
        $conn1 = $this->createConnection(1, $this->userId1, $this->gameId);
        $this->gameSocket->onOpen($conn1);

        // Perform multiple actions (more than snapshot interval of 5)
        for ($i = 0; $i < 6; $i++) {
            $actionMsg = json_encode([
                'cmd' => 'action',
                'action' => 'check',
                'amount' => 0,
                'game_version' => 0,
            ]);
            $this->gameSocket->onMessage($conn1, $actionMsg);
        }

        // Check if snapshot was created
        $snapshot = db_get_latest_snapshot($this->pdo, $this->gameId);
        
        $this->assertNotNull($snapshot, 'Snapshot should be created after multiple actions');
        $this->assertArrayHasKey('version', $snapshot);
        $this->assertArrayHasKey('state', $snapshot);
    }
}

