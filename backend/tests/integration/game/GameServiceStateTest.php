<?php
// backend/tests/integration/GameServiceStateTest.php
// -----------------------------------------------------------------------------
// Integration tests for GameService - state management and transitions.
// -----------------------------------------------------------------------------

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../helpers/PlayerStateHelpers.php';
require_once __DIR__ . '/../../helpers/GameServiceStateHelpers.php';
require_once __DIR__ . '/../../helpers/GameServiceActionHelpers.php';
require_once __DIR__ . '/../../../app/services/game/GameService.php';
require_once __DIR__ . '/../../../app/services/game/rules/GameTypes.php';

final class GameServiceStateTest extends TestCase
{
    use PlayerStateHelpers;
    use GameServiceStateHelpers;
    use GameServiceActionHelpers;

    /**
     * Test current bet reset on each street
     * Scenario 12: CurrentBet Reset on Each Street
     */
    public function test_current_bet_reset_on_each_street(): void
    {
        $game = $this->createGameService([
            ['seat' => 1, 'stack' => 1000],
            ['seat' => 2, 'stack' => 1000],
        ]);
        $result = $game->startHand();
        $this->assertTrue($result['ok']);
        
        // Force cards to avoid randomness
        $this->forceCards($game, 1, ['AS', 'KS']);
        $this->forceCards($game, 2, ['AD', 'KD']);
        
        $result = $this->executeAction($game, 1, ActionType::CALL);
        $this->assertTrue($result['ok']);

        $result = $this->executeAction($game, 2, ActionType::CHECK);
        $this->assertTrue($result['ok']);
        $this->assertEquals(Phase::FLOP, $this->getPhase($game), 'Should be in FLOP phase');
        $this->assertEquals(0, $this->getCurrentBet($game), 'Current bet should be 0 after flop');

        $result = $this->executeAction($game, 2, ActionType::BET, 50);
        $this->assertTrue($result['ok']);

        $result = $this->executeAction($game, 1, ActionType::CALL);
        $this->assertTrue($result['ok']);
        $this->assertEquals(Phase::TURN, $this->getPhase($game), 'Should be in TURN phase');
        $this->assertEquals(0, $this->getCurrentBet($game), 'Current bet should be 0 after turn');

        $result = $this->executeAction($game, 2, ActionType::BET, 100);
        $this->assertTrue($result['ok']);

        $result = $this->executeAction($game, 1, ActionType::CALL);
        $this->assertTrue($result['ok']);
        $this->assertEquals(Phase::RIVER, $this->getPhase($game), 'Should be in RIVER phase');
        $this->assertEquals(0, $this->getCurrentBet($game), 'Current bet should be 0 after river');
    }

    /**
     * Test next hand dealer rotation
     * Scenario 14: Next Hand Dealer Rotation
     */
    public function test_next_hand_dealer_rotation(): void
    {
        $game = $this->createGameService([
            ['seat' => 1, 'stack' => 1000],
            ['seat' => 2, 'stack' => 1000],
            ['seat' => 3, 'stack' => 1000],
        ]);
        $result = $game->startHand();
        $this->assertTrue($result['ok']);
        
        $dealer1 = $this->getDealerSeat($game);
        $blinds1 = $this->getBlindSeats($game);
        
        // Force cards to avoid randomness
        $this->forceCards($game, 1, ['AS', 'KS']);
        $this->forceCards($game, 2, ['AD', 'KD']);
        $this->forceCards($game, 3, ['AC', 'KC']);
        
        // Complete hand
        $this->completeHand($game);
        
        // Start next hand
        $result = $game->startNextHand();
        $this->assertTrue($result['ok']);
        
        $dealer2 = $this->getDealerSeat($game);
        $blinds2 = $this->getBlindSeats($game);
        
        // Dealer should rotate
        $this->assertNotEquals($dealer1, $dealer2, 'Dealer should rotate to different seat');
        
        // Blinds should rotate accordingly
        $this->assertNotEquals($blinds1['smallBlind'], $blinds2['smallBlind'], 'Small blind should rotate');
        $this->assertNotEquals($blinds1['bigBlind'], $blinds2['bigBlind'], 'Big blind should rotate');
    }

    /**
     * Test totalInvested reset between hands
     * Scenario 18: TotalInvested Reset Between Hands
     */
    public function test_total_invested_reset_between_hands(): void
    {
        $game = $this->createGameService([
            ['seat' => 1, 'stack' => 1000],
            ['seat' => 2, 'stack' => 1000],
        ]);
        $result = $game->startHand();
        $this->assertTrue($result['ok']);

        $this->executeAction($game, 1, ActionType::CALL);
        $this->executeAction($game, 2, ActionType::CHECK);

        $this->assertEquals(20, $this->getTotalInvested($game, 1), 'Player 1 should have matched the big blind');
        $this->assertEquals(20, $this->getTotalInvested($game, 2), 'Player 2 should have the big blind invested');
        
        // Force cards to avoid randomness
        $this->forceCards($game, 1, ['AS', 'KS']);
        $this->forceCards($game, 2, ['AD', 'KD']);
        
        // Complete hand
        $this->completeHand($game);
        
        // Start next hand
        $result = $game->startNextHand();
        $this->assertTrue($result['ok']);

        $investments = [
            $this->getTotalInvested($game, 1),
            $this->getTotalInvested($game, 2),
        ];
        sort($investments);

        $this->assertSame([10, 20], $investments, 'Only the new hand blinds should remain invested');
    }

    /**
     * Test actedThisStreet reset after showdown
     * Scenario 20: ActedThisStreet Reset After Showdown
     */
    public function test_acted_this_street_reset_after_showdown(): void
    {
        $game = $this->createGameService([
            ['seat' => 1, 'stack' => 1000],
            ['seat' => 2, 'stack' => 1000],
        ]);
        $result = $game->startHand();
        $this->assertTrue($result['ok']);
        
        // Force cards to avoid randomness
        $this->forceCards($game, 1, ['AS', 'KS']);
        $this->forceCards($game, 2, ['AD', 'KD']);
        
        // Advance to river and set actedThisStreet
        $this->advanceToStreet($game, Phase::RIVER);
        $this->forceActedThisStreet($game, [1 => true, 2 => true]);
        
        // Verify actedThisStreet is set
        $this->assertTrue($this->getActedThisStreet($game, 1), 'Player 1 should have acted this street');
        $this->assertTrue($this->getActedThisStreet($game, 2), 'Player 2 should have acted this street');
        
        // Complete river betting round and advance to showdown
        $this->completeBettingRound($game);
        $result = $game->advancePhaseIfNeeded();
        $this->assertTrue($result['handEnded'] ?? false);
        
        // Verify we're in showdown
        $this->assertEquals(Phase::SHOWDOWN, $this->getPhase($game), 'Should be in SHOWDOWN phase');
        
        // Evaluate winners (this should reset actedThisStreet)
        $result = $game->evaluateWinners();
        $this->assertTrue($result['ok']);
        
        // Verify actedThisStreet is reset after showdown
        $this->assertFalse($this->getActedThisStreet($game, 1), 'Player 1 actedThisStreet should be reset after showdown');
        $this->assertFalse($this->getActedThisStreet($game, 2), 'Player 2 actedThisStreet should be reset after showdown');
    }
}
