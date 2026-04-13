<?php
// backend/tests/unit/PhaseManagerTest.php
// -----------------------------------------------------------------------------
// Focused tests for PhaseEngine street advancement.
// -----------------------------------------------------------------------------

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/PlayerStateHelpers.php';
require_once __DIR__ . '/../../app/services/game/engine/TurnOrder.php';
require_once __DIR__ . '/../../app/services/game/engine/BettingEngine.php';
require_once __DIR__ . '/../../app/services/game/engine/PhaseEngine.php';
require_once __DIR__ . '/../../app/services/game/cards/DealerService.php';
require_once __DIR__ . '/../../app/services/game/rules/GameTypes.php';
require_once __DIR__ . '/../../app/services/game/GameState.php';

final class PhaseManagerTest extends TestCase
{
    use PlayerStateHelpers;

    /**
     * Advancing the phase should deal the next street and reset betting round state.
     */
    public function test_phase_engine_advances_street_and_resets_betting_state(): void
    {
        $state = new GameState();
        $player1 = $this->makePlayerState(1, 1000, ['bet' => 100, 'actedThisStreet' => true]);
        $player2 = $this->makePlayerState(2, 1000, ['bet' => 100, 'actedThisStreet' => true]);

        $state->players = [1 => $player1, 2 => $player2];
        $state->dealer = new DealerService(1234);
        $state->dealer->shuffleDeck();
        $state->dealerSeat = 1;
        $state->phase = Phase::PREFLOP;
        $state->currentBet = 100;
        $state->lastRaiseSeat = 2;
        $state->lastRaiseAmount = 40;
        $state->actionSeat = 2;

        $result = PhaseEngine::advance($state);

        $this->assertNull($result);
        $this->assertSame(Phase::FLOP, $state->phase);
        $this->assertCount(3, $state->board);
        $this->assertSame(0, $state->currentBet);
        $this->assertSame(-1, $state->lastRaiseSeat);
        $this->assertSame(0, $state->lastRaiseAmount);
        $this->assertSame(0, $player1->bet);
        $this->assertSame(0, $player2->bet);
        $this->assertFalse($player1->actedThisStreet);
        $this->assertFalse($player2->actedThisStreet);
        $this->assertSame(2, $state->actionSeat);

        $player1->bet = 50;
        $player2->bet = 50;
        $player1->actedThisStreet = true;
        $player2->actedThisStreet = true;
        $state->currentBet = 50;
        $state->lastRaiseSeat = 2;
        $state->lastRaiseAmount = 20;
        $state->actionSeat = 1;

        $result = PhaseEngine::advance($state);

        $this->assertNull($result);
        $this->assertSame(Phase::TURN, $state->phase);
        $this->assertCount(4, $state->board);
        $this->assertSame(0, $state->currentBet);
        $this->assertSame(0, $player1->bet);
        $this->assertSame(0, $player2->bet);
        $this->assertFalse($player1->actedThisStreet);
        $this->assertFalse($player2->actedThisStreet);
        $this->assertSame(2, $state->actionSeat);
    }
}

