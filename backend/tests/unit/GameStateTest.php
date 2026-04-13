<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @covers \GameState
 */
final class GameStateTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../app/services/game/rules/GameTypes.php';
        require_once __DIR__ . '/../../app/services/game/cards/DealerService.php';
        require_once __DIR__ . '/../../app/services/game/PlayerState.php';
        require_once __DIR__ . '/../../app/services/game/GameState.php';
    }

    public function testResetBettingRoundResetsStreetStateButPreservesHandTotals(): void
    {
        $state = new GameState();
        $state->pot = 190;
        $state->currentBet = 80;
        $state->lastRaiseSeat = 2;
        $state->lastRaiseAmount = 40;

        $activePlayer = new PlayerState(1, 920);
        $activePlayer->bet = 80;
        $activePlayer->actedThisStreet = true;
        $activePlayer->totalInvested = 80;
        $activePlayer->contribution = 80;
        $activePlayer->cards = ['AS', 'KD'];

        $foldedPlayer = new PlayerState(2, 900);
        $foldedPlayer->bet = 20;
        $foldedPlayer->actedThisStreet = true;
        $foldedPlayer->folded = true;
        $foldedPlayer->totalInvested = 100;
        $foldedPlayer->contribution = 100;

        $allInPlayer = new PlayerState(3, 0);
        $allInPlayer->bet = 90;
        $allInPlayer->actedThisStreet = true;
        $allInPlayer->allIn = true;
        $allInPlayer->totalInvested = 90;
        $allInPlayer->contribution = 90;

        $state->players = [
            1 => $activePlayer,
            2 => $foldedPlayer,
            3 => $allInPlayer,
        ];

        $state->resetBettingRound();

        $this->assertSame(190, $state->pot);
        $this->assertSame(0, $state->currentBet);
        $this->assertSame(-1, $state->lastRaiseSeat);
        $this->assertSame(0, $state->lastRaiseAmount);

        $this->assertSame(0, $activePlayer->bet);
        $this->assertFalse($activePlayer->actedThisStreet);
        $this->assertSame(80, $activePlayer->totalInvested);
        $this->assertSame(80, $activePlayer->contribution);
        $this->assertSame(['AS', 'KD'], $activePlayer->cards);

        $this->assertSame(0, $foldedPlayer->bet);
        $this->assertTrue($foldedPlayer->actedThisStreet);
        $this->assertSame(100, $foldedPlayer->totalInvested);
        $this->assertSame(100, $foldedPlayer->contribution);

        $this->assertSame(0, $allInPlayer->bet);
        $this->assertTrue($allInPlayer->actedThisStreet);
        $this->assertSame(90, $allInPlayer->totalInvested);
        $this->assertSame(90, $allInPlayer->contribution);
    }
}