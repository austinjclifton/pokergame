<?php
declare(strict_types=1);

require_once __DIR__ . '/../db/BaseDBIntegrationTest.php';

use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * Integration tests for GameSocket reconnection and version mismatch handling.
 * 
 * Tests:
 *  - Stale client version detection and STATE_SYNC response
 *  - Reconnection rebuilds state correctly
 *  - Replay from game_actions reproduces same version and pot totals
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

final class GameRejoinTest extends BaseDBIntegrationTest
{
    private $gameSocket;
    private int $tableId;
    private int $gameId;
    private int $userId1;
    private int $userId2;

    protected function loadDatabaseFunctions(): void
    {
        require_once __DIR__ . '/../../../app/db/tables.php';
        require_once __DIR__ . '/../../../app/db/table_seats.php';
        require_once __DIR__ . '/../../../app/db/games.php';
        require_once __DIR__ . '/../../../app/db/sessions.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        
        // Clean up
        $this->pdo->exec("DELETE FROM games");
        $this->pdo->exec("DELETE FROM table_seats");
        $this->pdo->exec("DELETE FROM tables");
        
        // Load required files
        require_once __DIR__ . '/../../../ws/GameSocket.php';
        require_once __DIR__ . '/../../../app/services/game/GameService.php';
        require_once __DIR__ . '/../../../app/services/game/cards/DealerService.php';
        require_once __DIR__ . '/../../../app/services/game/cards/HandEvaluator.php';
        require_once __DIR__ . '/../../../app/services/GamePersistenceService.php';
        require_once __DIR__ . '/../../../app/db/users.php';
        require_once __DIR__ . '/../../../lib/security.php';
        require_once __DIR__ . '/../../../app/services/AuditService.php';

        // Create test users
        $this->userId1 = $this->createTestUser('player1_rejoin');
        $this->userId2 = $this->createTestUser('player2_rejoin');

        // Create test table
        $this->tableId = db_create_table($this->pdo, 'Test Table Rejoin', 6, 10, 20, 0);
        $this->assertNotNull($this->tableId);

        // Seat players
        db_seat_player($this->pdo, $this->tableId, 1, $this->userId1);
        db_seat_player($this->pdo, $this->tableId, 2, $this->userId2);

        // Initialize GameSocket
        $this->gameSocket = new GameSocket($this->pdo);
    }

    private function createConnection(int $resourceId, int $userId, int $tableId): TestGameConnection
    {
        // Create session for user
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
        $sessionId = db_insert_session($this->pdo, $userId, 'test-ip', 'test-agent', $expiresAt);
        
        // Generate ws_token
        $token = generate_ws_token($this->pdo, $userId, $sessionId);
        
        $userCtx = [
            'user_id' => $userId,
            'session_id' => $sessionId,
        ];
        
        $request = new TestRequest("table_id={$tableId}&token={$token}");
        $conn = new TestGameConnection($resourceId, $userCtx, $request);
        return $conn;
    }

    /**
     * Test that stale client version receives STATE_SYNC update
     */
    public function testStaleVersionReceivesStateSync(): void
    {
        // This test requires a game to be started and actions to be taken
        // For now, we'll mark it as incomplete until hand starting is implemented
        $this->markTestIncomplete('Requires hand starting logic to be implemented');
        
        // TODO: Once hand starting is implemented:
        // 1. Start a hand
        // 2. Take an action (version becomes 1)
        // 3. Send action with stale version (0)
        // 4. Verify STATE_SYNC with reason VERSION_MISMATCH is received
        // 5. Verify ERROR with code STALE_VERSION is received
    }

    /**
     * Test that reconnection rebuilds state correctly
     */
    public function testReconnectionRebuildsState(): void
    {
        // This test requires a game to be started and actions to be taken
        $this->markTestIncomplete('Requires hand starting logic to be implemented');
        
        // TODO: Once hand starting is implemented:
        // 1. Start a hand
        // 2. Take several actions
        // 3. Disconnect player
        // 4. Reconnect player
        // 5. Verify STATE_SYNC with reason REJOIN is received
        // 6. Verify state matches previous state (pot, version, etc.)
    }


    /**
     * Helper to get message by type
     */
    private function getMessageByType(TestGameConnection $conn, string $type): ?array
    {
        foreach ($conn->sentMessages as $msg) {
            $data = json_decode($msg, true);
            if ($data && isset($data['type']) && $data['type'] === $type) {
                return $data;
            }
        }
        return null;
    }

    /**
     * Helper to get all messages by type
     */
    private function getMessagesByType(TestGameConnection $conn, string $type): array
    {
        $messages = [];
        foreach ($conn->sentMessages as $msg) {
            $data = json_decode($msg, true);
            if ($data && isset($data['type']) && $data['type'] === $type) {
                $messages[] = $data;
            }
        }
        return $messages;
    }
}

