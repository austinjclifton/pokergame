<?php
// backend/tests/unit/BettingEngineTest.php
// -----------------------------------------------------------------------------
// Unit tests for BettingEngine - betting logic, action validation, and legal actions.
// -----------------------------------------------------------------------------

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/PlayerStateHelpers.php';
require_once __DIR__ . '/../../app/services/game/engine/BettingEngine.php';
require_once __DIR__ . '/../../app/services/game/rules/GameTypes.php';

final class BettingEngineTest extends TestCase
{
    use PlayerStateHelpers;

    /**
     * Test that RAISE is not offered when all opponents are all-in
     * Scenario 1: Illegal Raise When Only One Opponent Remains
     */
    public function test_raise_not_offered_when_all_opponents_all_in(): void
    {
        // Setup: 3 players, 2 all-in, 1 active
        $player1 = $this->makePlayerState(1, 1000, ['allIn' => true]);
        $player2 = $this->makePlayerState(2, 1000, ['allIn' => true]);
        $player3 = $this->makePlayerState(3, 1000, ['bet' => 0]);
        
        $allPlayers = [1 => $player1, 2 => $player2, 3 => $player3];
        $currentBet = 500;
        $lastRaiseAmount = 100;
        
        // Get legal actions for player 3
        $legalActions = BettingEngine::getLegalActions($player3, $currentBet, $lastRaiseAmount, $allPlayers);
        
        // RAISE should not be available since all opponents are all-in
        $this->assertNotContains(ActionType::RAISE, $legalActions, 'RAISE should not be available when all opponents are all-in');
        $this->assertContains(ActionType::CALL, $legalActions, 'CALL should be available');
        $this->assertContains(ActionType::FOLD, $legalActions, 'FOLD should be available');
    }

    /**
     * Test minimum raise validation uses lastRaiseAmount correctly
     * Scenario 4: Minimum Raise Validation
     */
    public function test_minimum_raise_validation_uses_last_raise_amount(): void
    {
        // Setup: 2 players, currentBet = 20, lastRaiseAmount = 20
        $player1 = $this->makePlayerState(1, 1000, ['bet' => 20]);
        $player2 = $this->makePlayerState(2, 1000, ['bet' => 0]);
        
        $currentBet = 20;
        $lastRaiseAmount = 20;
        $bigBlindAmount = 20;
        
        // First raise to 40 should succeed (20 + 20 = 40)
        $result1 = BettingEngine::executeAction($player2, ActionType::RAISE, 20, $currentBet, $bigBlindAmount, $lastRaiseAmount);
        $this->assertTrue($result1['ok'], 'First raise to 40 should succeed');
        $this->assertEquals(40, $result1['newBet'], 'New bet should be 40');
        
        // Update state
        $currentBet = 40;
        $lastRaiseAmount = 20; // Last raise was 20 (from 20 to 40)
        $player1->bet = 0; // Reset for next test
        
        // Second raise to 55 should fail (minimum is 60: 40 + 20)
        $result2 = BettingEngine::executeAction($player1, ActionType::RAISE, 15, $currentBet, $bigBlindAmount, $lastRaiseAmount);
        $this->assertFalse($result2['ok'], 'Raise to 55 should fail');
        $this->assertStringContainsString('Minimum raise', $result2['message'] ?? '', 'Error should mention minimum raise');
    }

    /**
     * Test insufficient raise is rejected
     * Scenario 5: Insufficient Raise Rejection
     */
    public function test_insufficient_raise_rejected(): void
    {
        // Setup: 2 players, currentBet = 20, lastRaiseAmount = 20
        $player1 = $this->makePlayerState(1, 1000, ['bet' => 0]);
        $player2 = $this->makePlayerState(2, 1000, ['bet' => 20]);
        
        $currentBet = 20;
        $lastRaiseAmount = 20;
        $bigBlindAmount = 20;
        $initialStack = $player1->stack;
        $initialTotalInvested = $player1->totalInvested;
        
        // Attempt raise to 35 (insufficient - minimum is 40)
        $result = BettingEngine::executeAction($player1, ActionType::RAISE, 15, $currentBet, $bigBlindAmount, $lastRaiseAmount);
        
        $this->assertFalse($result['ok'], 'Insufficient raise should be rejected');
        $this->assertStringContainsString('Minimum raise is 40', $result['message'] ?? '', 'Error should specify minimum raise amount');
        
        // Verify no chips were moved
        $this->assertEquals($initialStack, $player1->stack, 'Stack should not change on failed raise');
        $this->assertEquals($initialTotalInvested, $player1->totalInvested, 'totalInvested should not change on failed raise');
    }

    /**
     * Test totalInvested is tracked through multiple actions
     * Scenario 10: TotalInvested Accuracy Through Multiple Actions
     */
    public function test_total_invested_tracked_through_multiple_actions(): void
    {
        // Setup: Single player, track through CALL, RAISE, CALL, ALLIN
        $player = $this->makePlayerState(1, 1000, ['bet' => 0, 'totalInvested' => 0]);
        
        // Use simple opponent bet simulation:
        // We only care about the math on this one player's totalInvested.
        $currentBet = 20;          // Opponent opened to 20
        $lastRaiseAmount = 20;     // BB = 20
        $bigBlindAmount = 20;

        $initialStack = $player->stack;

        // -------------------------------------------------------------
        // Action 1: CALL 20
        // Player bets 20 total (20 - 0)
        // -------------------------------------------------------------
        $result1 = BettingEngine::executeAction(
            $player,
            ActionType::CALL,
            0,
            $currentBet,
            $bigBlindAmount,
            $lastRaiseAmount
        );

        $this->assertTrue($result1['ok'], 'CALL should succeed');
        $this->assertEquals(20, $player->totalInvested, 'totalInvested should be 20 after CALL');


        // -------------------------------------------------------------
        // Opponent now RAISES to 60 (raise amount = 40)
        // We simulate this by increasing currentBet (not using our player).
        // Player currently has bet=20 and is facing a bet of 60.
        // -------------------------------------------------------------
        $player->bet = 20;  // Already committed this amount
        $currentBet = 60;
        $lastRaiseAmount = 40;


        // -------------------------------------------------------------
        // Action 2: CALL 40 more (to match 60)
        // Player moves from 20 → 60, adding 40 to totalInvested
        // -------------------------------------------------------------
        $result2 = BettingEngine::executeAction(
            $player,
            ActionType::CALL,
            0,
            $currentBet,
            $bigBlindAmount,
            $lastRaiseAmount
        );

        $this->assertTrue($result2['ok'], 'CALL to match raise should succeed');
        $this->assertEquals(60, $player->totalInvested, 'totalInvested should be 60 after matching raise');


        // -------------------------------------------------------------
        // Opponent now SHOVES → we simulate facing an all-in of full stack.
        // currentBet = player's entire stack (force an all-in decision).
        // -------------------------------------------------------------
        $player->bet = 60;              // Already matched previous street
        $currentBet = $initialStack;    // Facing an all-in
        $lastRaiseAmount = $initialStack - 60; // Not actually used in ALLIN call


        // -------------------------------------------------------------
        // Action 3: ALLIN — player calls the shove with full remaining stack.
        // totalInvested should end at initialStack (1000).
        // -------------------------------------------------------------
        $result3 = BettingEngine::executeAction(
            $player,
            ActionType::ALLIN,
            0,
            $currentBet,
            $bigBlindAmount,
            $lastRaiseAmount
        );

        $this->assertTrue($result3['ok'], 'ALLIN should succeed');
        $this->assertEquals(
            $initialStack,
            $player->totalInvested,
            'totalInvested should equal starting stack after ALLIN'
        );
    }

    /**
     * Test minimum raise after big blind
     * Scenario 17: Minimum Raise After Big Blind
     */
    public function test_minimum_raise_after_big_blind(): void
    {
        // Setup: 2 players, currentBet = 20 (big blind), lastRaiseAmount = 20
        $player1 = $this->makePlayerState(1, 1000, ['bet' => 0]);
        $player2 = $this->makePlayerState(2, 1000, ['bet' => 20]);
        
        $currentBet = 20;
        $lastRaiseAmount = 20;
        $bigBlindAmount = 20;
        
        // First raise to 40 should succeed (20 + 20 = 40)
        $result1 = BettingEngine::executeAction($player1, ActionType::RAISE, 20, $currentBet, $bigBlindAmount, $lastRaiseAmount);
        $this->assertTrue($result1['ok'], 'First raise to 40 should succeed');
        $this->assertEquals(40, $result1['newBet'], 'New bet should be 40');
        
        // Verify lastRaiseAmount would be updated to 20 (the raise amount)
        // Note: In actual GameService, lastRaiseAmount is updated after the action
        $expectedLastRaiseAmount = 20; // 40 - 20 = 20
        
        // Second raise to 55 should fail (minimum is 60: 40 + 20)
        $currentBet = 40;
        $player2->bet = 0; // Reset for next test
        $result2 = BettingEngine::executeAction($player2, ActionType::RAISE, 15, $currentBet, $bigBlindAmount, $expectedLastRaiseAmount);
        $this->assertFalse($result2['ok'], 'Raise to 55 should fail');
        $this->assertStringContainsString('Minimum raise is 60', $result2['message'] ?? '', 'Error should specify minimum raise of 60');
    }
}

