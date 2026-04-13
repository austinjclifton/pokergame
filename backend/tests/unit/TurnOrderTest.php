<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/PlayerStateHelpers.php';
require_once __DIR__ . '/../../app/services/game/engine/TurnOrder.php';

final class TurnOrderTest extends TestCase
{
    use PlayerStateHelpers;

    public function testNextSeatRotatesClockwiseAndWraps(): void
    {
        $players = [
            1 => $this->makePlayerState(1, 1000),
            3 => $this->makePlayerState(3, 1000),
            5 => $this->makePlayerState(5, 1000),
        ];

        $this->assertSame(3, TurnOrder::nextSeat($players, 1));
        $this->assertSame(5, TurnOrder::nextSeat($players, 3));
        $this->assertSame(1, TurnOrder::nextSeat($players, 5));
        $this->assertSame(1, TurnOrder::nextSeat($players, 99));
    }

    public function testNextActiveSeatSkipsFoldedAndAllInPlayers(): void
    {
        $players = [
            1 => $this->makePlayerState(1, 0, ['allIn' => true, 'folded' => false]),
            2 => $this->makePlayerState(2, 1000, ['allIn' => false, 'folded' => false]),
            3 => $this->makePlayerState(3, 1000, ['allIn' => false, 'folded' => false]),
            4 => $this->makePlayerState(4, 1000, ['allIn' => false, 'folded' => true]),
        ];

        $this->assertSame(2, TurnOrder::nextActiveSeat($players, 1));
        $this->assertSame(3, TurnOrder::nextActiveSeat($players, 2));
        $this->assertSame(2, TurnOrder::nextActiveSeat($players, 4));
        $this->assertSame(-1, TurnOrder::nextActiveSeat($players, 99));
    }

    public function testNormalizeActiveSeatFallsBackToFirstActiveSeat(): void
    {
        $players = [
            1 => $this->makePlayerState(1, 1000, ['folded' => true]),
            2 => $this->makePlayerState(2, 1000),
            3 => $this->makePlayerState(3, 0, ['allIn' => true]),
        ];

        $this->assertSame(2, TurnOrder::normalizeActiveSeat($players, 1));
        $this->assertSame(2, TurnOrder::normalizeActiveSeat($players, 99));
        $this->assertSame(2, TurnOrder::normalizeActiveSeat($players, 2));
        $this->assertSame(-1, TurnOrder::normalizeActiveSeat($players, -1));
    }
}