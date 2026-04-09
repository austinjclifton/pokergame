<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../../helpers/WebSocketTestDoubles.php';

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
final class GameSocketTest extends TestCase
{
    private PDO $pdo;
    private bool $inTransaction = false;
    private $gameSocket;
    private int $tableId;
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
        require_once __DIR__ . '/../../../app/db/tables.php';
        require_once __DIR__ . '/../../../app/db/table_seats.php';
        require_once __DIR__ . '/../../../app/db/users.php';

        // Create test users
        $this->userId1 = $this->createTestUser('player1');
        $this->userId2 = $this->createTestUser('player2');

        $this->tableId = (int) db_create_table($this->pdo, 'Test GameSocket Table', 2, 10, 20, 0);
        db_seat_player($this->pdo, $this->tableId, 1, $this->userId1);
        db_seat_player($this->pdo, $this->tableId, 2, $this->userId2);

        // Create test game
        $this->gameId = (int) db_create_game($this->pdo, $this->tableId, 1, 1, 2, 12345);

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

    private function createConnection(int $resourceId, int $userId, ?int $tableId = null): WebSocketTestConnection
    {
        $userCtx = [
            'user_id' => $userId,
            'session_id' => 1,
        ];
        
        $request = new WebSocketTestRequest('/game', 'table_id=' . ($tableId ?? $this->tableId));
        $conn = new WebSocketTestConnection($resourceId, $request, $userCtx);
        return $conn;
    }

    private function createLobbyConnection(int $resourceId, int $userId): WebSocketTestConnection
    {
        return new WebSocketTestConnection($resourceId, null, [
            'user_id' => $userId,
            'session_id' => 1,
        ]);
    }

    private function openAuthenticatedConnection(WebSocketTestConnection $conn): void
    {
        $this->gameSocket->onOpen($conn);
        $this->gameSocket->onAuthenticated($conn);
    }

    /**
     * Test player authentication via ws_token
     */
    public function testPlayerAuthentication(): void
    {
        $conn = $this->createConnection(1, $this->userId1);
        $this->openAuthenticatedConnection($conn);

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

    public function testHandStartBroadcastUsesPublicState(): void
    {
        $conn1 = $this->createConnection(1, $this->userId1);
        $conn2 = $this->createConnection(2, $this->userId2);

        $this->openAuthenticatedConnection($conn1);
        $this->openAuthenticatedConnection($conn2);

        $handStart = null;
        foreach ($conn1->sentMessages as $msg) {
            $data = json_decode($msg, true);
            if (($data['type'] ?? null) !== 'hand_start') {
                continue;
            }

            $handStart = $data;
            break;
        }

        $this->assertNotNull($handStart, 'Expected hand_start broadcast when the opening hand bootstraps');
        $this->assertArrayHasKey('state', $handStart);
        $this->assertArrayHasKey('players', $handStart['state']);

        foreach ($handStart['state']['players'] as $player) {
            $this->assertArrayNotHasKey('cards', $player, 'hand_start public state must not expose hole cards');
        }

        $encoded = json_encode($handStart);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('deckIndex', $encoded, 'hand_start public state must not expose dealer internals');
    }

    public function testGameStateUsesTableBlindLevels(): void
    {
        $tableId = (int) db_create_table($this->pdo, 'High Stakes Table', 2, 25, 50, 0);
        db_seat_player($this->pdo, $tableId, 1, $this->userId1);
        db_seat_player($this->pdo, $tableId, 2, $this->userId2);
        $gameId = (int) db_create_game($this->pdo, $tableId, 1, 1, 2, 54321);

        $gameSocket = new GameSocket($this->pdo);
        $conn1 = $this->createConnection(101, $this->userId1, $tableId);
        $conn2 = $this->createConnection(102, $this->userId2, $tableId);

        $gameSocket->onOpen($conn1);
        $gameSocket->onAuthenticated($conn1);
        $gameSocket->onOpen($conn2);
        $gameSocket->onAuthenticated($conn2);

        $stateSync = null;
        foreach ($conn1->sentMessages as $msg) {
            $data = json_decode($msg, true);
            if (($data['type'] ?? null) !== 'STATE_SYNC') {
                continue;
            }

            if (($data['game_id'] ?? null) !== $gameId) {
                continue;
            }

            $stateSync = $data;
        }

        $this->assertNotNull($stateSync, 'Expected STATE_SYNC for the high-stakes table');
        $this->assertSame(75, $stateSync['state']['pot']);
        $this->assertSame(50, $stateSync['state']['currentBet']);
    }

    /**
     * Test that unauthorized connection is rejected
     */
    public function testUnauthorizedConnectionRejected(): void
    {
        $conn = new WebSocketTestConnection(
            1,
            new WebSocketTestRequest('/game', "table_id={$this->tableId}"),
            []
        );
        $this->gameSocket->onOpen($conn);
        $this->gameSocket->onAuthenticated($conn);

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
        $conn1 = $this->createConnection(1, $this->userId1);
        $conn2 = $this->createConnection(2, $this->userId2);
        
        $this->openAuthenticatedConnection($conn1);
        $this->openAuthenticatedConnection($conn2);

        $conn1Private = null;
        $conn2Private = null;
        foreach ($conn1->sentMessages as $msg) {
            $data = json_decode($msg, true);
            if (($data['type'] ?? null) === 'STATE_PRIVATE') {
                $conn1Private = $data['state'];
            }
        }
        foreach ($conn2->sentMessages as $msg) {
            $data = json_decode($msg, true);
            if (($data['type'] ?? null) === 'STATE_PRIVATE') {
                $conn2Private = $data['state'];
            }
        }

        $actingConn = in_array('call', $conn1Private['legalActions'] ?? [], true) ? $conn1 : $conn2;

        // Clear initial messages
        $conn1->sentMessages = [];
        $conn2->sentMessages = [];

        // The current actor should be able to call the opening blind.
        $actionMsg = json_encode([
            'type' => 'action',
            'action' => 'call',
            'amount' => 0,
            'game_version' => 0,
        ]);
        
        $this->gameSocket->onMessage($actingConn, $actionMsg);

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
        $conn1 = $this->createConnection(1, $this->userId1);
        $this->openAuthenticatedConnection($conn1);

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
        $conn1 = $this->createConnection(1, $this->userId1);
        $this->openAuthenticatedConnection($conn1);

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
        $conn2 = $this->createConnection(2, $this->userId1);
        $this->openAuthenticatedConnection($conn2);

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
        $conn = $this->createConnection(1, $this->userId1);
        $this->openAuthenticatedConnection($conn);

        // Send invalid action
        $actionMsg = json_encode([
            'type' => 'action',
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
        $conn = $this->createConnection(1, $this->userId1);
        $this->openAuthenticatedConnection($conn);

        $conn->sentMessages = [];
        
        $pingMsg = json_encode(['type' => 'ping']);
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

    public function testConnectionWithoutActiveGameRejected(): void
    {
        $tableId = (int) db_create_table($this->pdo, 'No Active Game Table', 2, 10, 20, 0);
        db_seat_player($this->pdo, $tableId, 1, $this->userId1);

        $conn = $this->createConnection(99, $this->userId1, $tableId);
        $this->gameSocket->onOpen($conn);
        $this->gameSocket->onAuthenticated($conn);

        $this->assertTrue($conn->isClosed, 'Connection without active game should be closed');
        $this->assertNotEmpty($conn->sentMessages, 'Connection without active game should receive an error');

        $error = json_decode($conn->sentMessages[0], true);
        $this->assertIsArray($error);
        $this->assertSame('error', $error['type'] ?? null);
        $this->assertSame('game_not_found', $error['error'] ?? null);
    }

    public function testGamePresenceUpdateIncludesActiveTableId(): void
    {
        $lobbySocket = new LobbySocket($this->pdo);
        $watcher = $this->createLobbyConnection(50, $this->userId2);
        $lobbySocket->onOpen($watcher);
        $watcher->sentMessages = [];

        $gameSocket = new GameSocket($this->pdo, $lobbySocket);
        $conn = $this->createConnection(1, $this->userId1);
        $gameSocket->onOpen($conn);
        $gameSocket->onAuthenticated($conn);

        $presenceUpdate = null;
        foreach ($watcher->sentMessages as $message) {
            $decoded = json_decode($message, true);
            if (($decoded['type'] ?? null) !== 'presence' || ($decoded['event'] ?? null) !== 'update') {
                continue;
            }

            if (($decoded['user']['id'] ?? null) !== $this->userId1) {
                continue;
            }

            $presenceUpdate = $decoded;
            break;
        }

        $this->assertNotNull($presenceUpdate, 'Expected a lobby presence update when a player joins a game');
        $this->assertSame('in_game', $presenceUpdate['user']['status'] ?? null);
        $this->assertSame($this->tableId, $presenceUpdate['user']['active_table_id'] ?? null);
        $this->assertSame('player1', $presenceUpdate['user']['username'] ?? null);
    }

    public function testPeriodicDisconnectSweepMarksPlayerAwayWithoutFollowUpMessages(): void
    {
        $loop = new WebSocketTestLoop();
        $gameSocket = new GameSocket($this->pdo, null, $loop);

        $conn1 = $this->createConnection(1, $this->userId1);
        $conn2 = $this->createConnection(2, $this->userId2);
        $gameSocket->onOpen($conn1);
        $gameSocket->onAuthenticated($conn1);
        $gameSocket->onOpen($conn2);
        $gameSocket->onAuthenticated($conn2);

        $this->assertCount(1, $loop->periodicTimers, 'Expected GameSocket to register a periodic disconnect sweep');

        $conn2->sentMessages = [];
        $gameSocket->onClose($conn1);

        $reflection = new ReflectionClass($gameSocket);
        $pendingDisconnects = $reflection->getProperty('pendingDisconnects');
        $pendingDisconnects->setAccessible(true);

        $timers = $pendingDisconnects->getValue($gameSocket);
        $this->assertArrayHasKey($this->tableId, $timers);
        $this->assertArrayHasKey($this->userId1, $timers[$this->tableId]);

        $timers[$this->tableId][$this->userId1]['timestamp'] = 0.0;
        $pendingDisconnects->setValue($gameSocket, $timers);

        $loop->runPeriodicTimers();

        $awayMessage = null;
        foreach ($conn2->sentMessages as $message) {
            $decoded = json_decode($message, true);
            if (($decoded['type'] ?? null) === 'PLAYER_AWAY') {
                $awayMessage = $decoded;
                break;
            }
        }

        $this->assertNotNull($awayMessage, 'Expected periodic disconnect sweep to broadcast PLAYER_AWAY');
        $this->assertSame(1, $awayMessage['seat_no'] ?? null);
        $this->assertSame('player1', $awayMessage['username'] ?? null);
    }
}

