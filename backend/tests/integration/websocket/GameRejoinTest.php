<?php
declare(strict_types=1);

require_once __DIR__ . '/../db/BaseDBIntegrationTest.php';
require_once __DIR__ . '/../../helpers/WebSocketTestDoubles.php';

/**
 * Rejoin and stale-version coverage for the current GameSocket contract.
 *
 * @coversNothing
 */
final class GameRejoinTest extends BaseDBIntegrationTest
{
    private GameSocket $gameSocket;
    private int $tableId;
    private int $gameId;
    private int $userId1;
    private int $userId2;

    protected function loadDatabaseFunctions(): void
    {
        require_once __DIR__ . '/../../../app/db/games.php';
        require_once __DIR__ . '/../../../app/db/table_seats.php';
        require_once __DIR__ . '/../../../app/db/tables.php';
        require_once __DIR__ . '/../../../app/db/users.php';
    }

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../../../ws/GameSocket.php';
        require_once __DIR__ . '/../../../app/services/game/GameService.php';
        require_once __DIR__ . '/../../../app/services/game/cards/DealerService.php';
        require_once __DIR__ . '/../../../app/services/game/cards/HandEvaluator.php';

        $this->userId1 = $this->createTestUser('rejoin_player_1');
        $this->userId2 = $this->createTestUser('rejoin_player_2');

        $this->tableId = (int) db_create_table($this->pdo, 'Rejoin Test Table', 2, 10, 20, 0);
        db_seat_player($this->pdo, $this->tableId, 1, $this->userId1);
        db_seat_player($this->pdo, $this->tableId, 2, $this->userId2);

        $this->gameId = (int) db_create_game($this->pdo, $this->tableId, 1, 1, 2, 12345);
        $this->gameSocket = new GameSocket($this->pdo);
    }

    private function createConnection(int $resourceId, int $userId, ?int $tableId = null): WebSocketTestConnection
    {
        return new WebSocketTestConnection(
            $resourceId,
            new WebSocketTestRequest('/game', 'table_id=' . ($tableId ?? $this->tableId)),
            [
                'user_id' => $userId,
                'session_id' => 1,
            ]
        );
    }

    private function openAuthenticatedConnection(WebSocketTestConnection $conn): void
    {
        $this->gameSocket->onOpen($conn);
        $this->gameSocket->onAuthenticated($conn);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function messagesByType(WebSocketTestConnection $conn, string $type): array
    {
        $messages = [];

        foreach ($conn->sentMessages as $message) {
            $decoded = json_decode($message, true);
            if (($decoded['type'] ?? null) === $type) {
                $messages[] = $decoded;
            }
        }

        return $messages;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastMessageByType(WebSocketTestConnection $conn, string $type): ?array
    {
        $messages = $this->messagesByType($conn, $type);
        if ($messages === []) {
            return null;
        }

        return $messages[array_key_last($messages)];
    }

    /**
     * @return array{0: WebSocketTestConnection, 1: array<string, mixed>}
     */
    private function actingConnection(WebSocketTestConnection ...$connections): array
    {
        foreach ($connections as $conn) {
            $private = $this->lastMessageByType($conn, 'STATE_PRIVATE');
            if (!empty($private['state']['legalActions'])) {
                return [$conn, $private];
            }
        }

        $this->fail('No acting connection found for rejoin test');
    }

    /**
     * @param array<string, mixed> $privateMessage
     * @return array{type: string, action: string, amount: int, game_version: int}
     */
    private function validActionPayload(WebSocketTestConnection $conn, array $privateMessage): array
    {
        $legalActions = $privateMessage['state']['legalActions'] ?? [];
        $action = in_array('call', $legalActions, true) ? 'call' : 'check';
        $sync = $this->lastMessageByType($conn, 'STATE_SYNC');

        return [
            'type' => 'action',
            'action' => $action,
            'amount' => 0,
            'game_version' => (int) ($sync['version'] ?? 0),
        ];
    }

    public function testVersionMismatchReceivesStateSync(): void
    {
        $conn1 = $this->createConnection(1, $this->userId1);
        $conn2 = $this->createConnection(2, $this->userId2);
        $this->openAuthenticatedConnection($conn1);
        $this->openAuthenticatedConnection($conn2);

        [$actingConn, $private] = $this->actingConnection($conn1, $conn2);
        $payload = $this->validActionPayload($actingConn, $private);
        $currentVersion = $payload['game_version'];
        $payload['game_version'] = $currentVersion + 42;

        $actingConn->sentMessages = [];
        $this->gameSocket->onMessage($actingConn, json_encode($payload));

        $resync = $this->lastMessageByType($actingConn, 'STATE_SYNC');
        $this->assertNotNull($resync, 'Expected STATE_SYNC after version mismatch');
        $this->assertSame('version_mismatch', $resync['reason']);
        $this->assertSame($currentVersion, $resync['version']);
    }

    public function testReconnectClearsPendingAwayBroadcast(): void
    {
        $conn1 = $this->createConnection(1, $this->userId1);
        $conn2 = $this->createConnection(2, $this->userId2);
        $this->openAuthenticatedConnection($conn1);
        $this->openAuthenticatedConnection($conn2);

        $this->gameSocket->onClose($conn1);

        $reflection = new ReflectionClass($this->gameSocket);
        $pendingDisconnects = $reflection->getProperty('pendingDisconnects');
        $pendingDisconnects->setAccessible(true);

        $timers = $pendingDisconnects->getValue($this->gameSocket);
        $this->assertArrayHasKey($this->tableId, $timers);
        $this->assertArrayHasKey($this->userId1, $timers[$this->tableId]);

        $timers[$this->tableId][$this->userId1]['timestamp'] = 0.0;
        $pendingDisconnects->setValue($this->gameSocket, $timers);

        $reconnected = $this->createConnection(3, $this->userId1);
        $this->openAuthenticatedConnection($reconnected);

        $timersAfterReconnect = $pendingDisconnects->getValue($this->gameSocket);
        $this->assertArrayNotHasKey($this->userId1, $timersAfterReconnect[$this->tableId] ?? []);

        [$actingConn, $private] = $this->actingConnection($conn2, $reconnected);
        $conn2->sentMessages = [];
        $reconnected->sentMessages = [];
        $this->gameSocket->onMessage($actingConn, json_encode($this->validActionPayload($actingConn, $private)));

        $this->assertSame([], $this->messagesByType($conn2, 'PLAYER_AWAY'));
        $this->assertSame([], $this->messagesByType($reconnected, 'PLAYER_AWAY'));
    }
}