<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../../helpers/WebSocketTestDoubles.php';

/**
 * Integration tests for current GameSocket recovery and resync behavior.
 *
 * @coversNothing
 */
final class GameRecoveryTest extends TestCase
{
    private PDO $pdo;
    private bool $inTransaction = false;
    private GameSocket $gameSocket;
    private int $tableId;
    private int $gameId;
    private int $userId1;
    private int $userId2;

    protected function setUp(): void
    {
        $this->pdo = test_database_pdo();
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo->beginTransaction();
        $this->inTransaction = true;

        require_once __DIR__ . '/../../../ws/GameSocket.php';
        require_once __DIR__ . '/../../../app/services/game/GameService.php';
        require_once __DIR__ . '/../../../app/services/game/cards/DealerService.php';
        require_once __DIR__ . '/../../../app/services/game/cards/HandEvaluator.php';
        require_once __DIR__ . '/../../../app/db/game_snapshots.php';
        require_once __DIR__ . '/../../../app/db/games.php';
        require_once __DIR__ . '/../../../app/db/tables.php';
        require_once __DIR__ . '/../../../app/db/table_seats.php';
        require_once __DIR__ . '/../../../app/db/users.php';

        $this->userId1 = $this->createTestUser('recovery_player_1');
        $this->userId2 = $this->createTestUser('recovery_player_2');

        $this->tableId = (int) db_create_table($this->pdo, 'Recovery Test Table', 2, 10, 20, 0);
        db_seat_player($this->pdo, $this->tableId, 1, $this->userId1);
        db_seat_player($this->pdo, $this->tableId, 2, $this->userId2);

        $this->gameId = (int) db_create_game($this->pdo, $this->tableId, 1, 1, 2, 12345);
        $this->gameSocket = new GameSocket($this->pdo);
    }

    protected function tearDown(): void
    {
        if ($this->inTransaction && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
            $this->inTransaction = false;
        }

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private function createTestUser(string $username): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (username, email, password_hash, created_at) VALUES (?, ?, ?, NOW())'
        );
        $stmt->execute([
            $username,
            $username . '@test.com',
            password_hash('test', PASSWORD_DEFAULT),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createConnection(int $resourceId, int $userId, ?int $tableId = null): WebSocketTestConnection
    {
        $request = new WebSocketTestRequest('/game', 'table_id=' . ($tableId ?? $this->tableId));

        return new WebSocketTestConnection(
            $resourceId,
            $request,
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
    private function firstMessageByType(WebSocketTestConnection $conn, string $type): ?array
    {
        $messages = $this->messagesByType($conn, $type);
        return $messages[0] ?? null;
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

        $this->fail('No acting connection found');
    }

    /**
     * @param array<string, mixed> $privateMessage
     * @return array{type: string, action: string, amount: int, game_version: int}
     */
    private function validActionPayload(WebSocketTestConnection $conn, array $privateMessage): array
    {
        $legalActions = $privateMessage['state']['legalActions'] ?? [];
        $action = null;

        foreach (['call', 'check', 'fold'] as $candidate) {
            if (in_array($candidate, $legalActions, true)) {
                $action = $candidate;
                break;
            }
        }

        if ($action === null) {
            $this->fail('No supported legal action available for recovery test');
        }

        $sync = $this->lastMessageByType($conn, 'STATE_SYNC');

        return [
            'type' => 'action',
            'action' => $action,
            'amount' => 0,
            'game_version' => (int) ($sync['version'] ?? 0),
        ];
    }

    public function testReconnectReturnsConsistentState(): void
    {
        $conn1 = $this->createConnection(1, $this->userId1);
        $conn2 = $this->createConnection(2, $this->userId2);
        $this->openAuthenticatedConnection($conn1);
        $this->openAuthenticatedConnection($conn2);

        [$actingConn, $private] = $this->actingConnection($conn1, $conn2);
        $actingUserId = (int) $actingConn->userCtx['user_id'];

        $conn1->sentMessages = [];
        $conn2->sentMessages = [];
        $this->gameSocket->onMessage($actingConn, json_encode($this->validActionPayload($actingConn, $private)));

        $stateAfterAction = $this->lastMessageByType($conn1, 'STATE_DIFF')
            ?? $this->lastMessageByType($conn2, 'STATE_DIFF');

        $this->assertNotNull($stateAfterAction, 'Expected STATE_DIFF after valid action');

        $this->gameSocket->onClose($actingConn);

        $reconnected = $this->createConnection(3, $actingUserId);
        $this->openAuthenticatedConnection($reconnected);

        $reconnectedSync = $this->firstMessageByType($reconnected, 'STATE_SYNC');
        $this->assertNotNull($reconnectedSync, 'Reconnected player should receive STATE_SYNC');
        $this->assertSame($stateAfterAction['version'], $reconnectedSync['version']);
        $this->assertSame($stateAfterAction['state']['phase'], $reconnectedSync['state']['phase']);
        $this->assertSame($stateAfterAction['state']['pot'], $reconnectedSync['state']['pot']);
        $this->assertSame($stateAfterAction['state']['currentBet'], $reconnectedSync['state']['currentBet']);
    }

    public function testReconnectReturnsConsistentPrivateState(): void
    {
        $conn1 = $this->createConnection(1, $this->userId1);
        $conn2 = $this->createConnection(2, $this->userId2);
        $this->openAuthenticatedConnection($conn1);
        $this->openAuthenticatedConnection($conn2);

        $initialPrivate = $this->lastMessageByType($conn1, 'STATE_PRIVATE');
        $this->assertNotNull($initialPrivate, 'Expected STATE_PRIVATE on first connect');

        $this->gameSocket->onClose($conn1);

        $reconnected = $this->createConnection(3, $this->userId1);
        $this->openAuthenticatedConnection($reconnected);

        $reconnectedPrivate = $this->lastMessageByType($reconnected, 'STATE_PRIVATE');
        $this->assertNotNull($reconnectedPrivate, 'Expected STATE_PRIVATE on reconnect');
        $this->assertSame($initialPrivate['seat'], $reconnectedPrivate['seat']);
        $this->assertSame($initialPrivate['state']['mySeat'], $reconnectedPrivate['state']['mySeat']);
        $this->assertSame($initialPrivate['state']['myCards'], $reconnectedPrivate['state']['myCards']);
    }

    public function testVersionMismatchCausesStateSync(): void
    {
        $conn1 = $this->createConnection(1, $this->userId1);
        $conn2 = $this->createConnection(2, $this->userId2);
        $this->openAuthenticatedConnection($conn1);
        $this->openAuthenticatedConnection($conn2);

        [$actingConn, $private] = $this->actingConnection($conn1, $conn2);
        $payload = $this->validActionPayload($actingConn, $private);
        $currentVersion = $payload['game_version'];
        $payload['game_version'] = $currentVersion + 99;

        $actingConn->sentMessages = [];
        $this->gameSocket->onMessage($actingConn, json_encode($payload));

        $resync = $this->lastMessageByType($actingConn, 'STATE_SYNC');
        $this->assertNotNull($resync, 'Expected STATE_SYNC resync on version mismatch');
        $this->assertSame('version_mismatch', $resync['reason']);
        $this->assertSame($currentVersion, $resync['version']);
    }

    public function testEveryAcceptedActionAdvancesVersionExactlyOnce(): void
    {
        $conn1 = $this->createConnection(1, $this->userId1);
        $conn2 = $this->createConnection(2, $this->userId2);
        $this->openAuthenticatedConnection($conn1);
        $this->openAuthenticatedConnection($conn2);

        $initialSync = $this->lastMessageByType($conn1, 'STATE_SYNC');
        $this->assertNotNull($initialSync);
        $expectedVersion = (int)$initialSync['version'];

        for ($actionCount = 0; $actionCount < 4; $actionCount++) {
            [$actingConn, $private] = $this->actingConnection($conn1, $conn2);
            $payload = $this->validActionPayload($actingConn, $private);
            $payload['game_version'] = $expectedVersion;

            $conn1->sentMessages = [];
            $conn2->sentMessages = [];
            $this->gameSocket->onMessage($actingConn, json_encode($payload, JSON_THROW_ON_ERROR));

            $stateDiff = $this->lastMessageByType($conn1, 'STATE_DIFF')
                ?? $this->lastMessageByType($conn2, 'STATE_DIFF');
            $this->assertNotNull($stateDiff, 'Expected accepted action to broadcast state');
            $this->assertSame(++$expectedVersion, $stateDiff['version']);

            $persisted = db_get_latest_snapshot($this->pdo, $this->gameId);
            $this->assertNotNull($persisted);
            $this->assertSame($expectedVersion, $persisted['version']);
            $this->assertSame($expectedVersion, $this->databaseVersion());

            if (($stateDiff['state']['phase'] ?? null) !== 'preflop') {
                $this->assertSame('flop', $stateDiff['state']['phase']);
                return;
            }
        }

        $this->fail('Expected normal turn ordering to advance the hand to the flop');
    }

    public function testRejectedActionDoesNotAdvanceVersion(): void
    {
        $conn1 = $this->createConnection(1, $this->userId1);
        $conn2 = $this->createConnection(2, $this->userId2);
        $this->openAuthenticatedConnection($conn1);
        $this->openAuthenticatedConnection($conn2);

        [$actingConn, $private] = $this->actingConnection($conn1, $conn2);
        $payload = $this->validActionPayload($actingConn, $private);
        $payload['action'] = 'not-a-poker-action';
        $versionBefore = $this->databaseVersion();
        $snapshotBefore = db_get_latest_snapshot($this->pdo, $this->gameId);

        $actingConn->sentMessages = [];
        $this->gameSocket->onMessage($actingConn, json_encode($payload, JSON_THROW_ON_ERROR));

        $error = $this->lastMessageByType($actingConn, 'error');
        $this->assertNotNull($error);
        $this->assertSame('action_failed', $error['error']);
        $this->assertSame($versionBefore, $this->databaseVersion());
        $this->assertSame($snapshotBefore, db_get_latest_snapshot($this->pdo, $this->gameId));
        $this->assertNull($this->lastMessageByType($actingConn, 'STATE_DIFF'));
    }

    public function testStaleActionCannotOverwriteNewerSnapshot(): void
    {
        $conn1 = $this->createConnection(1, $this->userId1);
        $conn2 = $this->createConnection(2, $this->userId2);
        $this->openAuthenticatedConnection($conn1);
        $this->openAuthenticatedConnection($conn2);

        [$actingConn, $private] = $this->actingConnection($conn1, $conn2);
        $stalePayload = $this->validActionPayload($actingConn, $private);
        $this->gameSocket->onMessage($actingConn, json_encode($stalePayload, JSON_THROW_ON_ERROR));

        $authoritative = db_get_latest_snapshot($this->pdo, $this->gameId);
        $this->assertNotNull($authoritative);
        $this->assertSame($stalePayload['game_version'] + 1, $authoritative['version']);

        $actingConn->sentMessages = [];
        $this->gameSocket->onMessage($actingConn, json_encode($stalePayload, JSON_THROW_ON_ERROR));

        $resync = $this->lastMessageByType($actingConn, 'STATE_SYNC');
        $this->assertNotNull($resync);
        $this->assertSame('version_mismatch', $resync['reason']);
        $this->assertSame($authoritative['version'], $resync['version']);
        $this->assertSame($authoritative, db_get_latest_snapshot($this->pdo, $this->gameId));
        $this->assertSame($authoritative['version'], $this->databaseVersion());
        $this->assertNull($this->lastMessageByType($actingConn, 'STATE_DIFF'));
    }

    public function testStaleServerStateCannotOverwriteNewerAuthoritativeSnapshot(): void
    {
        $conn1 = $this->createConnection(1, $this->userId1);
        $conn2 = $this->createConnection(2, $this->userId2);
        $this->openAuthenticatedConnection($conn1);
        $this->openAuthenticatedConnection($conn2);

        [$actingConn, $private] = $this->actingConnection($conn1, $conn2);
        $payload = $this->validActionPayload($actingConn, $private);
        $staleVersion = $payload['game_version'];

        $authoritative = db_get_latest_snapshot($this->pdo, $this->gameId);
        $this->assertNotNull($authoritative);
        $authoritative['version'] = $staleVersion + 1;
        $authoritative['state']['players'][1]['stack'] += 7;

        $statement = $this->pdo->prepare('UPDATE games SET version = :version WHERE id = :game_id');
        $statement->execute([
            'version' => $authoritative['version'],
            'game_id' => $this->gameId,
        ]);
        db_insert_snapshot(
            $this->pdo,
            $this->gameId,
            $authoritative['version'],
            $authoritative['state'],
        );

        $actingConn->sentMessages = [];
        $this->gameSocket->onMessage($actingConn, json_encode($payload, JSON_THROW_ON_ERROR));

        $resync = $this->lastMessageByType($actingConn, 'STATE_SYNC');
        $this->assertNotNull($resync);
        $this->assertSame('version_mismatch', $resync['reason']);
        $this->assertSame($authoritative['version'], $resync['version']);
        $this->assertSame(
            $authoritative['state']['players'][1]['stack'],
            $resync['state']['players'][1]['stack'],
        );
        $this->assertSame($authoritative, db_get_latest_snapshot($this->pdo, $this->gameId));
        $this->assertSame($authoritative['version'], $this->databaseVersion());
        $this->assertNull($this->lastMessageByType($actingConn, 'STATE_DIFF'));
    }

    private function databaseVersion(): int
    {
        $statement = $this->pdo->prepare('SELECT version FROM games WHERE id = :game_id');
        $statement->execute(['game_id' => $this->gameId]);
        return (int)$statement->fetchColumn();
    }

    public function testLatestSnapshotMatchesBroadcastState(): void
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
        $this->assertNotNull($snapshot, 'Expected persisted snapshot for active game');
        $this->assertSame($stateDiff['version'], $snapshot['version']);
        $this->assertSame($stateDiff['state']['phase'], $snapshot['state']['phase']);
        $this->assertSame($stateDiff['state']['pot'], $snapshot['state']['pot']);
        $this->assertSame($stateDiff['state']['currentBet'], $snapshot['state']['currentBet']);
    }

    public function testFreshSocketRestoresPersistedActiveHandForBothPlayers(): void
    {
        $conn1 = $this->createConnection(1, $this->userId1);
        $conn2 = $this->createConnection(2, $this->userId2);
        $this->openAuthenticatedConnection($conn1);
        $this->openAuthenticatedConnection($conn2);

        for ($actionCount = 0; $actionCount < 4; $actionCount++) {
            $persisted = db_get_latest_snapshot($this->pdo, $this->gameId);
            $this->assertNotNull($persisted);
            if (($persisted['state']['phase'] ?? null) !== 'preflop') {
                break;
            }

            [$actingConn, $private] = $this->actingConnection($conn1, $conn2);
            $payload = $this->validActionPayload($actingConn, $private);
            $payload['game_version'] = (int)$persisted['version'];
            $this->gameSocket->onMessage($actingConn, json_encode($payload, JSON_THROW_ON_ERROR));
        }

        $persistedBeforeRestart = db_get_latest_snapshot($this->pdo, $this->gameId);
        $this->assertNotNull($persistedBeforeRestart);
        $this->assertSame('flop', $persistedBeforeRestart['state']['phase']);
        $this->assertCount(3, $persistedBeforeRestart['state']['board']);

        // Simulate a process restart: the replacement socket has no in-memory
        // GameService or connection state and can recover only from MySQL.
        $this->gameSocket = new GameSocket($this->pdo);
        $reconnected1 = $this->createConnection(101, $this->userId1);
        $reconnected2 = $this->createConnection(102, $this->userId2);
        $this->openAuthenticatedConnection($reconnected1);
        $this->openAuthenticatedConnection($reconnected2);

        $sync1 = $this->firstMessageByType($reconnected1, 'STATE_SYNC');
        $sync2 = $this->firstMessageByType($reconnected2, 'STATE_SYNC');
        $private1 = $this->firstMessageByType($reconnected1, 'STATE_PRIVATE');
        $private2 = $this->firstMessageByType($reconnected2, 'STATE_PRIVATE');

        $this->assertNotNull($sync1);
        $this->assertNotNull($sync2);
        $this->assertNotNull($private1);
        $this->assertNotNull($private2);
        $this->assertSame($persistedBeforeRestart['version'], $sync1['version']);
        $this->assertSame($persistedBeforeRestart['version'], $sync2['version']);

        foreach ([$sync1, $sync2] as $sync) {
            foreach (['phase', 'board', 'pot', 'currentBet', 'actionSeat', 'dealerSeat', 'smallBlindSeat', 'bigBlindSeat'] as $field) {
                $this->assertSame($persistedBeforeRestart['state'][$field], $sync['state'][$field], $field);
            }

            foreach ([1, 2] as $seat) {
                foreach (['stack', 'bet', 'folded', 'allIn'] as $field) {
                    $this->assertSame(
                        $persistedBeforeRestart['state']['players'][$seat][$field],
                        $sync['state']['players'][$seat][$field],
                        "seat {$seat} {$field}",
                    );
                }
                $this->assertArrayNotHasKey('cards', $sync['state']['players'][$seat]);
            }
        }

        $this->assertSame($persistedBeforeRestart['state']['players'][1]['cards'], $private1['state']['myCards']);
        $this->assertSame($persistedBeforeRestart['state']['players'][2]['cards'], $private2['state']['myCards']);

        $persistedAfterRestart = db_get_latest_snapshot($this->pdo, $this->gameId);
        $this->assertSame($persistedBeforeRestart['version'], $persistedAfterRestart['version']);
        $this->assertSame($persistedBeforeRestart['state']['handIndex'], $persistedAfterRestart['state']['handIndex']);
        $this->assertSame($persistedBeforeRestart['state'], $persistedAfterRestart['state']);
    }
}
