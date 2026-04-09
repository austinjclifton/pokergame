<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;

final class WebSocketJsonTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../lib/WebSocketJson.php';
    }

    public function testEncodePreservesUnicodeAndSlashes(): void
    {
        $json = WebSocketJson::encode([
            'url' => 'https://example.com/ws',
            'msg' => 'Hello 🎮',
        ]);

        $this->assertStringContainsString('https://example.com/ws', $json);
        $this->assertStringContainsString('Hello 🎮', $json);
    }

    public function testSendWritesEncodedPayload(): void
    {
        $conn = new class implements ConnectionInterface {
            public int $resourceId = 1;
            public array $messages = [];

            public function send($data): void
            {
                $this->messages[] = $data;
            }

            public function close(): void
            {
            }
        };

        $result = WebSocketJson::send($conn, ['type' => 'pong']);

        $this->assertTrue($result);
        $this->assertSame(['{"type":"pong"}'], $conn->messages);
    }

    public function testSendEncodedReportsFailureViaCallback(): void
    {
        $conn = new class implements ConnectionInterface {
            public int $resourceId = 2;

            public function send($data): void
            {
                throw new RuntimeException('send failed');
            }

            public function close(): void
            {
            }
        };

        $captured = null;
        $result = WebSocketJson::sendEncoded($conn, '{"type":"pong"}', function (\Throwable $e) use (&$captured): void {
            $captured = $e->getMessage();
        });

        $this->assertFalse($result);
        $this->assertSame('send failed', $captured);
    }

    public function testCloseQuietlySwallowsCloseExceptions(): void
    {
        $conn = new class implements ConnectionInterface {
            public int $resourceId = 3;

            public function send($data): void
            {
            }

            public function close(): void
            {
                throw new RuntimeException('close failed');
            }
        };

        $captured = null;
        WebSocketJson::closeQuietly($conn, function (\Throwable $e) use (&$captured): void {
            $captured = $e->getMessage();
        });

        $this->assertSame('close failed', $captured);
    }
}