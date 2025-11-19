<?php
// backend/tests/integration/GameServiceStateTest.php
// -----------------------------------------------------------------------------
// Integration tests for GameService - state management and transitions.
// -----------------------------------------------------------------------------

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/PlayerStateHelpers.php';
require_once __DIR__ . '/../helpers/GameServiceStateHelpers.php';
require_once __DIR__ . '/../helpers/GameServiceActionHelpers.php';
require_once __DIR__ . '/../../app/services/game/GameService.php';
require_once __DIR__ . '/../../app/services/game/rules/GameTypes.php';

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
        // Setup: 2 players
        $game = $this->createGameService([
            ['seat' => 1, 'stack' => 1000],
            ['seat' => 2, 'stack' => 1000],
        ]);
        
        // Manually set up initial hand state (blinds, dealer, etc.)
        $reflection = new \ReflectionClass($game);
        $dealerSeatProperty = $reflection->getProperty('dealerSeat');
        $dealerSeatProperty->setAccessible(true);
        $dealerSeatProperty->setValue($game, 1);
        
        $sbSeatProperty = $reflection->getProperty('smallBlindSeat');
        $sbSeatProperty->setAccessible(true);
        $sbSeatProperty->setValue($game, 1);
        
        $bbSeatProperty = $reflection->getProperty('bigBlindSeat');
        $bbSeatProperty->setAccessible(true);
        $bbSeatProperty->setValue($game, 2);
        
        $actionSeatProperty = $reflection->getProperty('actionSeat');
        $actionSeatProperty->setAccessible(true);
        $actionSeatProperty->setValue($game, 1);
        
        // Post blinds manually
        $players = $this->getPlayers($game);
        $players[1]->stack -= 10;
        $players[1]->bet = 10;
        $players[1]->totalInvested = 10;
        $players[2]->stack -= 20;
        $players[2]->bet = 20;
        $players[2]->totalInvested = 20;
        
        $potProperty = $reflection->getProperty('pot');
        $potProperty->setAccessible(true);
        $potProperty->setValue($game, 30);
        
        $currentBetProperty = $reflection->getProperty('currentBet');
        $currentBetProperty->setAccessible(true);
        $currentBetProperty->setValue($game, 20);
        
        $lastRaiseAmountProperty = $reflection->getProperty('lastRaiseAmount');
        $lastRaiseAmountProperty->setAccessible(true);
        $lastRaiseAmountProperty->setValue($game, 20);
        
        $phaseProperty = $reflection->getProperty('phase');
        $phaseProperty->setAccessible(true);
        $phaseProperty->setValue($game, Phase::PREFLOP);
        
        // Force cards to avoid randomness
        $this->forceCards($game, 1, ['AS', 'KS']);
        $this->forceCards($game, 2, ['AD', 'KD']);
        
        // Complete preflop betting round
        $this->completeBettingRound($game);
        
        // Advance to flop
        $game->advancePhaseIfNeeded();
        $this->assertEquals(Phase::FLOP, $this->getPhase($game), 'Should be in FLOP phase');
        $this->assertEquals(0, $this->getCurrentBet($game), 'Current bet should be 0 after flop');
        
        // Set a bet and complete flop betting round
        $this->forceCurrentBet($game, 50);
        $this->forceBets($game, [1 => 50, 2 => 50]);
        $this->forceTotalInvested($game, [1 => 50, 2 => 50]); // Match bets
        $this->forceActedThisStreet($game, [1 => true, 2 => true]);
        $this->forceLastRaiseAmount($game, 0);
        
        // Complete betting round
        $this->completeBettingRound($game);
        
        // Advance to turn
        $game->advancePhaseIfNeeded();
        $this->assertEquals(Phase::TURN, $this->getPhase($game), 'Should be in TURN phase');
        $this->assertEquals(0, $this->getCurrentBet($game), 'Current bet should be 0 after turn');
        
        // Set a bet and complete turn betting round
        $this->forceCurrentBet($game, 100);
        $this->forceBets($game, [1 => 100, 2 => 100]);
        $this->forceTotalInvested($game, [1 => 100, 2 => 100]); // Match bets
        $this->forceActedThisStreet($game, [1 => true, 2 => true]);
        $this->forceLastRaiseAmount($game, 0);
        
        // Complete betting round
        $this->completeBettingRound($game);
        
        // Advance to river
        $game->advancePhaseIfNeeded();
        $this->assertEquals(Phase::RIVER, $this->getPhase($game), 'Should be in RIVER phase');
        $this->assertEquals(0, $this->getCurrentBet($game), 'Current bet should be 0 after river');
    }

    /**
     * Test next hand dealer rotation
     * Scenario 14: Next Hand Dealer Rotation
     */
    public function test_next_hand_dealer_rotation(): void
    {
        // Setup: 3 players
        $game = $this->createGameService([
            ['seat' => 1, 'stack' => 1000],
            ['seat' => 2, 'stack' => 1000],
            ['seat' => 3, 'stack' => 1000],
        ]);
        
        // Manually set up first hand state
        $reflection = new \ReflectionClass($game);
        $dealerSeatProperty = $reflection->getProperty('dealerSeat');
        $dealerSeatProperty->setAccessible(true);
        $dealerSeatProperty->setValue($game, 1);
        
        $sbSeatProperty = $reflection->getProperty('smallBlindSeat');
        $sbSeatProperty->setAccessible(true);
        $sbSeatProperty->setValue($game, 2);
        
        $bbSeatProperty = $reflection->getProperty('bigBlindSeat');
        $bbSeatProperty->setAccessible(true);
        $bbSeatProperty->setValue($game, 3);
        
        // Post blinds
        $players = $this->getPlayers($game);
        $players[2]->stack -= 10;
        $players[2]->bet = 10;
        $players[2]->totalInvested = 10;
        $players[3]->stack -= 20;
        $players[3]->bet = 20;
        $players[3]->totalInvested = 20;
        
        $dealer1 = $this->getDealerSeat($game);
        $blinds1 = $this->getBlindSeats($game);
        
        // Force cards to avoid randomness
        $this->forceCards($game, 1, ['AS', 'KS']);
        $this->forceCards($game, 2, ['AD', 'KD']);
        $this->forceCards($game, 3, ['AC', 'KC']);
        
        // Complete hand
        $this->completeHand($game);
        
        // Start next hand
        $this->startNextHand($game);
        
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
        // Setup: 2 players
        $game = $this->createGameService([
            ['seat' => 1, 'stack' => 1000],
            ['seat' => 2, 'stack' => 1000],
        ]);
        
        // Manually set up first hand state
        $reflection = new \ReflectionClass($game);
        $dealerSeatProperty = $reflection->getProperty('dealerSeat');
        $dealerSeatProperty->setAccessible(true);
        $dealerSeatProperty->setValue($game, 1);
        
        $sbSeatProperty = $reflection->getProperty('smallBlindSeat');
        $sbSeatProperty->setAccessible(true);
        $sbSeatProperty->setValue($game, 1);
        
        $bbSeatProperty = $reflection->getProperty('bigBlindSeat');
        $bbSeatProperty->setAccessible(true);
        $bbSeatProperty->setValue($game, 2);
        
        // Set some investments (matching bets)
        $players = $this->getPlayers($game);
        $players[1]->bet = 100;
        $players[1]->totalInvested = 100;
        $players[2]->bet = 150;
        $players[2]->totalInvested = 150;
        
        // Verify investments are set
        $this->assertEquals(100, $this->getTotalInvested($game, 1), 'Player 1 should have 100 invested');
        $this->assertEquals(150, $this->getTotalInvested($game, 2), 'Player 2 should have 150 invested');
        
        // Force cards to avoid randomness
        $this->forceCards($game, 1, ['AS', 'KS']);
        $this->forceCards($game, 2, ['AD', 'KD']);
        
        // Complete hand
        $this->completeHand($game);
        
        // Start next hand
        $this->startNextHand($game);
        
        // Verify investments are reset
        $this->assertEquals(0, $this->getTotalInvested($game, 1), 'Player 1 totalInvested should be reset to 0');
        $this->assertEquals(0, $this->getTotalInvested($game, 2), 'Player 2 totalInvested should be reset to 0');
    }

    /**
     * Test actedThisStreet reset after showdown
     * Scenario 20: ActedThisStreet Reset After Showdown
     */
    public function test_acted_this_street_reset_after_showdown(): void
    {
        // Setup: 2 players
        $game = $this->createGameService([
            ['seat' => 1, 'stack' => 1000],
            ['seat' => 2, 'stack' => 1000],
        ]);
        
        // Manually set up initial state
        $reflection = new \ReflectionClass($game);
        $dealerSeatProperty = $reflection->getProperty('dealerSeat');
        $dealerSeatProperty->setAccessible(true);
        $dealerSeatProperty->setValue($game, 1);
        
        $sbSeatProperty = $reflection->getProperty('smallBlindSeat');
        $sbSeatProperty->setAccessible(true);
        $sbSeatProperty->setValue($game, 1);
        
        $bbSeatProperty = $reflection->getProperty('bigBlindSeat');
        $bbSeatProperty->setAccessible(true);
        $bbSeatProperty->setValue($game, 2);
        
        // Post blinds
        $players = $this->getPlayers($game);
        $players[1]->stack -= 10;
        $players[1]->bet = 10;
        $players[1]->totalInvested = 10;
        $players[2]->stack -= 20;
        $players[2]->bet = 20;
        $players[2]->totalInvested = 20;
        
        $potProperty = $reflection->getProperty('pot');
        $potProperty->setAccessible(true);
        $potProperty->setValue($game, 30);
        
        $currentBetProperty = $reflection->getProperty('currentBet');
        $currentBetProperty->setAccessible(true);
        $currentBetProperty->setValue($game, 20);
        
        $lastRaiseAmountProperty = $reflection->getProperty('lastRaiseAmount');
        $lastRaiseAmountProperty->setAccessible(true);
        $lastRaiseAmountProperty->setValue($game, 20);
        
        $phaseProperty = $reflection->getProperty('phase');
        $phaseProperty->setAccessible(true);
        $phaseProperty->setValue($game, Phase::PREFLOP);
        
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
        $game->advancePhaseIfNeeded();
        
        // Verify we're in showdown
        $this->assertEquals(Phase::SHOWDOWN, $this->getPhase($game), 'Should be in SHOWDOWN phase');
        
        // Evaluate winners (this should reset actedThisStreet)
        $game->evaluateWinners();
        
        // Verify actedThisStreet is reset after showdown
        $this->assertFalse($this->getActedThisStreet($game, 1), 'Player 1 actedThisStreet should be reset after showdown');
        $this->assertFalse($this->getActedThisStreet($game, 2), 'Player 2 actedThisStreet should be reset after showdown');
    }
}
