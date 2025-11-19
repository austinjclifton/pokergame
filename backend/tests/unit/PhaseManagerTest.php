<?php
// backend/tests/unit/PhaseManagerTest.php
// -----------------------------------------------------------------------------
// Unit tests for PhaseManager - phase transitions and action seat rotation.
// -----------------------------------------------------------------------------

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/PlayerStateHelpers.php';
require_once __DIR__ . '/../../app/services/game/engine/PhaseManager.php';
require_once __DIR__ . '/../../app/services/game/cards/DealerService.php';
require_once __DIR__ . '/../../app/services/game/rules/GameTypes.php';

final class PhaseManagerTest extends TestCase
{
    use PlayerStateHelpers;

    /**
     * Test all-in player is skipped in action rotation
     * Scenario 7: All-In Player Skipped in Action Rotation
     */
    public function test_all_in_player_skipped_in_action_rotation(): void
    {
        // Setup: 3 players, Seat 1 all-in, Seat 2 and 3 active
        $player1 = $this->makePlayerState(1, 0, ['allIn' => true, 'folded' => false]);
        $player2 = $this->makePlayerState(2, 1000, ['allIn' => false, 'folded' => false]);
        $player3 = $this->makePlayerState(3, 1000, ['allIn' => false, 'folded' => false]);
        
        $players = [1 => $player1, 2 => $player2, 3 => $player3];
        
        // Starting from seat 1 (all-in), next active seat should be 2
        $nextSeat = PhaseManager::getNextActiveSeat($players, 1);
        $this->assertEquals(2, $nextSeat, 'Next active seat should be 2, skipping all-in player 1');
        
        // Starting from seat 2, next active seat should be 3 (skipping 1)
        $nextSeat = PhaseManager::getNextActiveSeat($players, 2);
        $this->assertEquals(3, $nextSeat, 'Next active seat should be 3, skipping all-in player 1');
        
        // Starting from seat 3, should wrap around to 2 (skipping 1)
        $nextSeat = PhaseManager::getNextActiveSeat($players, 3);
        $this->assertEquals(2, $nextSeat, 'Next active seat should wrap to 2, skipping all-in player 1');
    }

    /**
     * Test phase manager resets bets and actedThisStreet
     * Scenario 11: PhaseManager Resets Bets and ActedThisStreet
     */
    public function test_phase_manager_resets_bets_and_acted_this_street(): void
    {
        // Setup: 2 players with bets = 100, actedThisStreet = true
        $player1 = $this->makePlayerState(1, 1000, ['bet' => 100, 'actedThisStreet' => true]);
        $player2 = $this->makePlayerState(2, 1000, ['bet' => 100, 'actedThisStreet' => true]);
        
        $players = [1 => $player1, 2 => $player2];
        $dealer = new DealerService();
        $dealer->shuffleDeck();
        $dealerSeat = 1;
        
        // Deal flop
        $result = PhaseManager::dealFlop($dealer, $players, $dealerSeat);
        
        // Verify bets reset
        $this->assertEquals(0, $player1->bet, 'Player 1 bet should be reset to 0');
        $this->assertEquals(0, $player2->bet, 'Player 2 bet should be reset to 0');
        
        // Verify actedThisStreet reset
        $this->assertFalse($player1->actedThisStreet, 'Player 1 actedThisStreet should be reset to false');
        $this->assertFalse($player2->actedThisStreet, 'Player 2 actedThisStreet should be reset to false');
        
        // Verify board has 3 cards
        $this->assertCount(3, $result['board'], 'Flop should have 3 cards');
        
        // Set bets and actedThisStreet again for turn test
        $player1->bet = 50;
        $player2->bet = 50;
        $player1->actedThisStreet = true;
        $player2->actedThisStreet = true;
        
        // Deal turn
        $result = PhaseManager::dealTurn($dealer, $players, $result['board'], $dealerSeat);
        
        // Verify bets reset again
        $this->assertEquals(0, $player1->bet, 'Player 1 bet should be reset to 0 after turn');
        $this->assertEquals(0, $player2->bet, 'Player 2 bet should be reset to 0 after turn');
        
        // Verify actedThisStreet reset again
        $this->assertFalse($player1->actedThisStreet, 'Player 1 actedThisStreet should be reset to false after turn');
        $this->assertFalse($player2->actedThisStreet, 'Player 2 actedThisStreet should be reset to false after turn');
        
        // Verify board has 4 cards
        $this->assertCount(4, $result['board'], 'Turn should add 1 card to board');
    }
}

