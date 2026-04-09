<?php
declare(strict_types=1);

require_once __DIR__ . '/../db/BaseDBIntegrationTest.php';
require_once __DIR__ . '/../../helpers/WebSocketTestDoubles.php';

/**
 * Snapshot persistence coverage for the current GameSocket implementation.
 *
 * @coversNothing
 */
final class GamePersistenceIntegrationTest extends BaseDBIntegrationTest
{
    private GameSocket $gameSocket;
    private int $tableId;
    private int $gameId;
    private int $userId1;
    private int $userId2;

    protected function loadDatabaseFunctions(): void
    {
        require_once __DIR__ . '/../../../app/db/game_snapshots.php';
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

        $this->userId1 = $this->createTestUser('persist_player_1');
        $this->userId2 = $this->createTestUser('persist_player_2');

        $this->tableId = (int) db_create_table($this->pdo, 'Persistence Test Table', 2, 10, 20, 0);
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
     * @return array<string, mixed>|null
     */
    private function lastMessageByType(WebSocketTestConnection $conn, string $type): ?array
    {
        $match = null;

        foreach ($conn->sentMessages as $message) {
            $decoded = json_decode($message, true);
            if (($decoded['type'] ?? null) === $type) {
                $match = $decoded;
            }
        }

        return $match;
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

        $this->fail('No acting connection found for persistence test');
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

    public function testHandBootstrapCreatesSnapshot(): void
    {
        $conn1 = $this->createConnection(1, $this->userId1);
        $conn2 = $this->createConnection(2, $this->userId2);
        $this->openAuthenticatedConnection($conn1);
        $this->openAuthenticatedConnection($conn2);

        $stateSync = $this->lastMessageByType($conn1, 'STATE_SYNC');
        $this->assertNotNull($stateSync, 'Expected STATE_SYNC after authenticated connect');

        $snapshot = db_get_latest_snapshot($this->pdo, $this->gameId);
        $this->assertNotNull($snapshot, 'Expected bootstrap snapshot for new hand');
        $this->assertSame($stateSync['version'], $snapshot['version']);
        $this->assertArrayNotHasKey('ok', $snapshot['state']);
        $this->assertSame($stateSync['state']['phase'], $snapshot['state']['phase']);
        $this->assertSame($stateSync['state']['pot'], $snapshot['state']['pot']);
        $this->assertSame($stateSync['state']['currentBet'], $snapshot['state']['currentBet']);
        $this->assertIsArray($snapshot['state']['dealer'] ?? null);
    }

    public function testValidActionPersistsLatestStateSnapshot(): void
    {
        $conn1 = $this->createConnection(1, $this->userId1);
        $conn2 = $this->createConnection(2, $this->userId2);
        $this->openAuthenticatedConnection($conn1);
        $this->openAuthenticatedConnection($conn2);

        [$actingConn, $private] = $this->actingConnection($conn1, $conn2);

        $conn1->sentMessages = [];
        $conn2->sentMessages = [];
        $this->gameSocket->onMessage($actingConn, json_encode($this->validActionPayload($actingConn, $private)));

        $stateDiff = $this->lastMessageByType($conn1, 'STATE_DIFF')
            ?? $this->lastMessageByType($conn2, 'STATE_DIFF');
        $this->assertNotNull($stateDiff, 'Expected STATE_DIFF after valid action');

        $snapshot = db_get_latest_snapshot($this->pdo, $this->gameId);
        $this->assertNotNull($snapshot, 'Expected snapshot after valid action');
        $this->assertSame($stateDiff['version'], $snapshot['version']);
        $this->assertSame($stateDiff['state']['phase'], $snapshot['state']['phase']);
        $this->assertSame($stateDiff['state']['pot'], $snapshot['state']['pot']);
        $this->assertSame($stateDiff['state']['currentBet'], $snapshot['state']['currentBet']);
    }
}