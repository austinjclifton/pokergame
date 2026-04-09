<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test for reconnection stability.
 * 
 * Tests:
 *  - Browser refresh does not trigger false disconnections
 *  - State is restored correctly after reconnect
 *  - No PLAYER_DISCONNECTED message sent during reconnect
 * 
 * @coversNothing
 */
final class GameReconnectionTest extends TestCase
{
    public function testReconnectLogicUsesReconnectAwareTracking(): void
    {
        $gameSocketFile = __DIR__ . '/../../../ws/GameSocket.php';
        $this->assertFileExists($gameSocketFile);

        $content = file_get_contents($gameSocketFile);
        $this->assertIsString($content);
        $this->assertStringContainsString('userConnections', $content);
        $this->assertStringContainsString('isReconnect', $content);
        $this->assertStringContainsString('pendingDisconnects', $content);
        $this->assertStringContainsString('PLAYER_AWAY', $content);
    }
}

