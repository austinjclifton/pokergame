<?php
// backend/tests/integration/GameServiceBettingTest.php
// -----------------------------------------------------------------------------
// Integration tests for GameService - betting round completion logic.
// -----------------------------------------------------------------------------

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../helpers/PlayerStateHelpers.php';
require_once __DIR__ . '/../../helpers/GameServiceStateHelpers.php';
require_once __DIR__ . '/../../helpers/GameServiceActionHelpers.php';
require_once __DIR__ . '/../../../app/services/game/GameService.php';
require_once __DIR__ . '/../../../app/services/game/rules/GameTypes.php';

final class GameServiceBettingTest extends TestCase
{
    use PlayerStateHelpers;
    use GameServiceStateHelpers;
    use GameServiceActionHelpers;

    /**
     * Scenario 6: Betting round completes when all players have acted
     */
    public function test_betting_round_completion_after_all_act(): void
    {
        //
        // Setup a CLEAN legal flop state:
        //
        //  - Let GameService start normally (posting blinds, dealing cards)
        //  - Then force the phase to FLOP
        //  - Then reset bets to simulate a fresh flop round
        //

        $game = $this->createGameService([
            ['seat' => 1, 'stack' => 1000],
            ['seat' => 2, 'stack' => 1000],
            ['seat' => 3, 'stack' => 1000],
        ]);

        // Start real hand (legal initial state)
        $result = $game->startHand();
        $this->assertTrue($result['ok']);

        // Jump directly to FLOP for deterministic testing
        $this->forcePhase($game, Phase::FLOP);

        // Fresh betting street on flop
        $this->forceCurrentBet($game, 0);
        $this->forceBets($game, [1 => 0, 2 => 0, 3 => 0]);
        $this->forceTotalInvested($game, [
            1 => $this->getTotalInvested($game, 1),
            2 => $this->getTotalInvested($game, 2),
            3 => $this->getTotalInvested($game, 3),
        ]);

        $this->forceActedThisStreet($game, [1 => false, 2 => false, 3 => false]);
        $this->forceLastRaiseAmount($game, 0);

        // Set action seat (first to act: seat left of dealer)
        $actionSeat = $this->getNextActiveSeat($this->getPlayers($game), $this->getDealerSeat($game));
        $this->forceActionSeat($game, $actionSeat);

        // Players all CHECK in turn → valid for checking round
        $this->executeAction($game, $actionSeat, ActionType::CHECK);

        $next = $this->getNextActiveSeat($this->getPlayers($game), $actionSeat);
        $this->executeAction($game, $next, ActionType::CHECK);

        $next2 = $this->getNextActiveSeat($this->getPlayers($game), $next);
        $this->executeAction($game, $next2, ActionType::CHECK);

        $this->assertEquals(Phase::TURN, $this->getPhase($game), 'Completing the flop round should advance to the turn');
        $this->assertEquals(0, $this->getCurrentBet($game), 'The next street should start with no live bet');
    }

    /**
     * Scenario 16: Betting completes even when one player is all-in
     */
    public function test_betting_round_completion_with_all_in_player(): void
    {
        //
        // Setup:
        //   Seat 1 = short stack → goes all-in preflop automatically after raise
        //   Then we jump into FLOP and run a CALL/CALL betting round
        //

        $game = $this->createGameService([
            ['seat' => 1, 'stack' => 50],   // short stack → can be forced all-in
            ['seat' => 2, 'stack' => 1000],
            ['seat' => 3, 'stack' => 1000],
        ]);

        $game->startHand();

        //
        // Force flop the same way as scenario 6,
        // but mark seat 1 as all-in with matching investment.
        //

        $this->forcePhase($game, Phase::FLOP);

        // Seat 1 shoved their entire stack before flop
        $players = $this->getPlayers($game);
        $players[1]->allIn = true;

        // Force equal pot contributions for the test
        $this->forceBets($game, [1 => 50, 2 => 0, 3 => 0]);
        $this->forceTotalInvested($game, [
            1 => 50,
            2 => $this->getTotalInvested($game, 2),
            3 => $this->getTotalInvested($game, 3),
        ]);

        $this->forceCurrentBet($game, 50);
        $this->forceActedThisStreet($game, [1 => false, 2 => false, 3 => false]);
        $this->forceLastRaiseAmount($game, 0);

        $first = $this->getNextActiveSeat($players, $this->getDealerSeat($game));
        $this->forceActionSeat($game, $first);
        $this->forceBets($game, [1 => 50, 2 => 50, 3 => 50]);
        $this->forceActedThisStreet($game, [1 => false, 2 => true, 3 => true]);

        $this->assertTrue(
            \BettingEngine::isBettingRoundComplete(
                $this->getActivePlayers($game),
                $this->getActionSeat($game),
                $this->getCurrentBet($game),
                $this->getLastRaiseSeat($game)
            ),
            'Betting round should be complete even when an all-in player is present'
        );

        // All-in player should *not* be part of active players
        $this->assertArrayNotHasKey(1, $this->getActivePlayers($game));
    }
}
