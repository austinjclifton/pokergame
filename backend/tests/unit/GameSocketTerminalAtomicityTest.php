<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/WebSocketTestDoubles.php';
require_once __DIR__ . '/../../ws/GameSocket.php';

final class GameSocketTerminalAtomicityTest extends TestCase
{
    public function testTerminalCleanupFailureRollsBackBeforeBroadcast(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->sqliteCreateFunction('NOW', static fn(): string => '2026-10-23 00:00:00');
        $pdo->exec('CREATE TABLE games (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE game_snapshots (game_id INTEGER, version INTEGER, state_json TEXT)');
        $pdo->exec('CREATE TABLE table_seats (table_id INTEGER, left_at TEXT NULL)');
        $pdo->exec('INSERT INTO games (id) VALUES (1)');
        $pdo->exec("INSERT INTO game_snapshots VALUES (1, 1, '{}')");
        $pdo->exec('INSERT INTO table_seats (table_id, left_at) VALUES (1, NULL)');
        $pdo->exec(<<<'SQL'
            CREATE TRIGGER fail_terminal_seat_cleanup
            BEFORE UPDATE ON table_seats
            BEGIN
                SELECT RAISE(ABORT, 'forced seat cleanup failure');
            END
            SQL);

        $state = [
            'board' => [],
            'players' => [
                1 => ['user_id' => 10, 'cards' => ['AS', 'AD'], 'folded' => false, 'stack' => 2000, 'bet' => 0],
                2 => ['user_id' => 20, 'cards' => ['KH', 'KD'], 'folded' => true, 'stack' => 0, 'bet' => 0],
            ],
        ];

        $service = new class($state) {
            public bool $restored = false;

            public function __construct(private array $state)
            {
            }

            public function getVersion(): int { return 1; }
            public function getGameId(): int { return 1; }
            public function getSnapshot(): array { return $this->state; }
            public function isHandBootstrapped(): bool { return true; }
            public function restoreLatestSnapshot(): bool { return false; }

            public function restoreSnapshot(array $state, int $version): void
            {
                $this->restored = true;
            }

            public function applyAction(int $seat, string $action, int $amount): array
            {
                return [
                    'ok' => true,
                    'matchEnded' => true,
                    'winner' => ['seat' => 1, 'user_id' => 10, 'stack' => 2000],
                    'loser' => ['seat' => 2, 'user_id' => 20, 'stack' => 0],
                    'reason' => 'forfeit',
                    'state' => $this->state,
                ];
            }
        };

        $socket = new GameSocket($pdo);
        $connection = new WebSocketTestConnection(1);
        $this->setProperty($socket, 'gameServices', [1 => $service]);
        $this->setProperty($socket, 'tableIdToGameId', [1 => 1]);
        $this->setProperty($socket, 'userConnections', [1 => [10 => [$connection]]]);
        $this->setProperty($socket, 'connInfo', [
            1 => [
                'user_id' => 10,
                'username' => 'player1',
                'table_id' => 1,
                'seat' => 1,
                'game_id' => 1,
                'rate' => TokenBucketRateLimiter::seed(10.0),
            ],
        ]);

        $socket->onMessage($connection, json_encode([
            'type' => 'action',
            'action' => 'forfeit',
            'game_version' => 1,
        ], JSON_THROW_ON_ERROR));

        $this->assertFalse($pdo->inTransaction());
        $this->assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM games')->fetchColumn());
        $this->assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM game_snapshots')->fetchColumn());
        $this->assertNull($pdo->query('SELECT left_at FROM table_seats')->fetchColumn());
        $this->assertTrue($service->restored);

        $messages = array_map(
            static fn(string $message): array => json_decode($message, true, 512, JSON_THROW_ON_ERROR),
            $connection->sentMessages,
        );
        $this->assertContains('server_error', array_column($messages, 'error'));
        $this->assertNotContains('match_end', array_column($messages, 'event'));
    }

    private function setProperty(object $object, string $name, mixed $value): void
    {
        $property = new ReflectionProperty($object, $name);
        $property->setValue($object, $value);
    }
}
