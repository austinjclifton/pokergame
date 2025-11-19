<?php
// backend/tests/integration/GameServiceShowdownTest.php
// -----------------------------------------------------------------------------
// Integration tests for GameService - showdown and winner calculation.
// -----------------------------------------------------------------------------

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/PlayerStateHelpers.php';
require_once __DIR__ . '/../helpers/GameServiceStateHelpers.php';
require_once __DIR__ . '/../helpers/GameServiceActionHelpers.php';
require_once __DIR__ . '/../../app/services/game/GameService.php';
require_once __DIR__ . '/../../app/services/game/rules/GameTypes.php';
require_once __DIR__ . '/../../app/services/game/cards/HandEvaluator.php';

final class GameServiceShowdownTest extends TestCase
{
    use PlayerStateHelpers;
    use GameServiceStateHelpers;
    use GameServiceActionHelpers;

    /**
     * Scenarios 2 & 3:
     * - Player 1 all-in for 100
     * - Players 2 & 3 call
     * - Pot = 300
     * - Move naturally to showdown
     * - All players have same hand => split pot 3 ways
     */
    public function test_showdown_all_in_call_side_pot_integration(): void
    {
        // Create game
        $game = $this->createGameService([
            ['seat' => 1, 'stack' => 100],
            ['seat' => 2, 'stack' => 1000],
            ['seat' => 3, 'stack' => 1000],
        ]);

        // Start the hand
        $result = $game->startHand([
            ['seat' => 1, 'stack' => 100],
            ['seat' => 2, 'stack' => 1000],
            ['seat' => 3, 'stack' => 1000],
        ]);
        $this->assertTrue($result['ok']);

        //
        // Force deterministic hole cards
        //
        $this->forceCards($game, 1, ['AS', 'KS']);
        $this->forceCards($game, 2, ['AD', 'KD']);
        $this->forceCards($game, 3, ['AC', 'KC']);

        //
        // Player 1 goes all-in for 100
        //
        $this->executeAction($game, 1, ActionType::RAISE, 100);

        //
        // Player 2 CALLS 100
        //
        $this->executeAction($game, 2, ActionType::CALL);

        //
        // Player 3 CALLS 100
        //
        $this->executeAction($game, 3, ActionType::CALL);

        //
        // Betting complete → advance to flop
        //
        $this->completeBettingRound($game);
        $game->advancePhaseIfNeeded();

        //
        // Force deterministic board for showdown
        //
        $this->forceBoard($game, ['QS', 'JS', '10S', '9H', '8H']);

        //
        // Force phase → SHOWDOWN
        //
        $this->forcePhase($game, Phase::SHOWDOWN);

        //
        // Now evaluate winners
        //
        $winners = $game->evaluateWinners();

        $this->assertNotEmpty($winners);
        $this->assertEquals(300, array_sum(array_column($winners, 'amount')));
    }

    /**
     * Scenarios 8 & 9:
     * - 3 players all with identical made hands
     * - Pot = 300 after equal CALL actions
     * - All tie → evenly split 100 / 100 / 100
     */
    public function test_showdown_tie_handling_integration(): void
    {
        $game = $this->createGameService([
            ['seat' => 1, 'stack' => 1000],
            ['seat' => 2, 'stack' => 1000],
            ['seat' => 3, 'stack' => 1000],
        ]);

        // Start hand
        $result = $game->startHand([
            ['seat' => 1, 'stack' => 1000],
            ['seat' => 2, 'stack' => 1000],
            ['seat' => 3, 'stack' => 1000],
        ]);
        $this->assertTrue($result['ok']);

        //
        // Force deterministic hole cards
        //
        $this->forceCards($game, 1, ['AS', 'KS']);
        $this->forceCards($game, 2, ['AD', 'KD']);
        $this->forceCards($game, 3, ['AC', 'KC']);

        //
        // All players CALL 100 (simulate equal action)
        //
        $this->executeAction($game, 1, ActionType::RAISE, 100);
        $this->executeAction($game, 2, ActionType::CALL);
        $this->executeAction($game, 3, ActionType::CALL);

        $this->completeBettingRound($game);
        $game->advancePhaseIfNeeded();

        //
        // Force deterministic board that creates a TIE
        //
        $this->forceBoard($game, ['AH', 'KH', 'QS', 'JS', '10S']);

        $this->forcePhase($game, Phase::SHOWDOWN);

        //
        // Evaluate winners
        //
        $winners = $game->evaluateWinners();

        $this->assertCount(3, $winners); // 3-way tie
        $this->assertEquals(300, array_sum(array_column($winners, 'amount')));

        foreach ($winners as $w) {
            $this->assertEquals(100, $w['amount']);
        }
    }
}
