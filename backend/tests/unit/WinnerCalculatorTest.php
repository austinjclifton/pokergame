<?php
// backend/tests/unit/WinnerCalculatorTest.php
// -----------------------------------------------------------------------------
// Unit tests for WinnerCalculator - side pot calculation and winner determination.
// -----------------------------------------------------------------------------

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/PlayerStateHelpers.php';
require_once __DIR__ . '/../../app/services/game/rules/WinnerCalculator.php';
require_once __DIR__ . '/../../app/services/game/rules/GameTypes.php';

final class WinnerCalculatorTest extends TestCase
{
    use PlayerStateHelpers;

    /**
     * Test all-in call creates side pot
     * Scenario 2: All-In Call Creates Side Pot
     */
    public function test_all_in_call_creates_side_pot(): void
    {
        // Setup: 3 players, Seat 1 all-in 100, Seat 2 and 3 call 100 each
        $player1 = $this->makePlayerState(1, 0, ['allIn' => true, 'totalInvested' => 100]);
        $player2 = $this->makePlayerState(2, 900, ['totalInvested' => 100]);
        $player3 = $this->makePlayerState(3, 900, ['totalInvested' => 100]);
        
        $players = [1 => $player1, 2 => $player2, 3 => $player3];
        
        // Set hand ranks (player 2 wins)
        $this->setHandRank($player1, 100, 'Pair');
        $this->setHandRank($player2, 200, 'Two Pair');
        $this->setHandRank($player3, 150, 'Pair');
        
        $winners = WinnerCalculator::calculateWinners($players);
        
        // Should have one winner (player 2)
        $this->assertCount(1, $winners, 'Should have one winner');
        $this->assertEquals(2, $winners[0]['seat'], 'Player 2 should win');
        $this->assertEquals(300, $winners[0]['amount'], 'Winner should get entire pot of 300');
    }

    /**
     * Test all-in raise creates multiple side pots
     * Scenario 3: All-In Raise Creates Multiple Side Pots
     */
    public function test_all_in_raise_creates_multiple_side_pots(): void
    {
        // Setup: 3 players with different stacks
        // Seat 1: all-in 50
        // Seat 2: all-in 200 (after raising)
        // Seat 3: calls 200
        $player1 = $this->makePlayerState(1, 0, ['allIn' => true, 'totalInvested' => 50]);
        $player2 = $this->makePlayerState(2, 0, ['allIn' => true, 'totalInvested' => 200]);
        $player3 = $this->makePlayerState(3, 800, ['totalInvested' => 200]);
        
        $players = [1 => $player1, 2 => $player2, 3 => $player3];
        
        // Set hand ranks (player 2 has best hand)
        $this->setHandRank($player1, 100, 'Pair');
        $this->setHandRank($player2, 300, 'Three of a Kind');
        $this->setHandRank($player3, 200, 'Two Pair');
        
        $winners = WinnerCalculator::calculateWinners($players);
        
        // Player 2 should win both side pots
        $this->assertCount(1, $winners, 'Should have one winner');
        $this->assertEquals(2, $winners[0]['seat'], 'Player 2 should win');
        
        // Side pot 1: 50 * 3 = 150 (all players eligible)
        // Side pot 2: 150 * 2 = 300 (only players 2 and 3 eligible)
        // Total: 450
        $this->assertEquals(450, $winners[0]['amount'], 'Winner should get total pot of 450');
    }

    /**
     * Test showdown winner calculation for tied hands
     * Scenario 8: Showdown Winner Calculation for Tied Hands
     */
    public function test_showdown_winner_calculation_for_tied_hands(): void
    {
        // Setup: 3 players, all-in, board creates tie between 2 players
        $player1 = $this->makePlayerState(1, 0, ['allIn' => true, 'totalInvested' => 100]);
        $player2 = $this->makePlayerState(2, 0, ['allIn' => true, 'totalInvested' => 100]);
        $player3 = $this->makePlayerState(3, 0, ['allIn' => true, 'totalInvested' => 100]);
        
        $players = [1 => $player1, 2 => $player2, 3 => $player3];
        
        // Players 1 and 2 tie with same hand rank, player 3 loses
        $this->setHandRank($player1, 200, 'Two Pair');
        $this->setHandRank($player2, 200, 'Two Pair');
        $this->setHandRank($player3, 100, 'Pair');
        
        $winners = WinnerCalculator::calculateWinners($players);
        
        // Should have 2 winners (players 1 and 2)
        $this->assertCount(2, $winners, 'Should have 2 winners');
        
        $winnerSeats = array_column($winners, 'seat');
        $this->assertContains(1, $winnerSeats, 'Player 1 should be a winner');
        $this->assertContains(2, $winnerSeats, 'Player 2 should be a winner');
        $this->assertNotContains(3, $winnerSeats, 'Player 3 should not win');
        
        // Pot should be split 150/150
        $totalWon = array_sum(array_column($winners, 'amount'));
        $this->assertEquals(300, $totalWon, 'Total winnings should equal pot of 300');
        
        // Each winner should get 150
        foreach ($winners as $winner) {
            $this->assertEquals(150, $winner['amount'], 'Each winner should get 150');
        }
    }

    /**
     * Test remainder chip distribution in split pot
     * Scenario 9: Remainder Chip Distribution in Split Pot
     */
    public function test_remainder_chip_distribution_in_split_pot(): void
    {
        // Setup: 3 players, all tie, pot = 301
        $player1 = $this->makePlayerState(1, 0, ['allIn' => true, 'totalInvested' => 101]);
        $player2 = $this->makePlayerState(2, 0, ['allIn' => true, 'totalInvested' => 100]);
        $player3 = $this->makePlayerState(3, 0, ['allIn' => true, 'totalInvested' => 100]);
        
        $players = [1 => $player1, 2 => $player2, 3 => $player3];
        
        // All players tie
        $this->setHandRank($player1, 200, 'Two Pair');
        $this->setHandRank($player2, 200, 'Two Pair');
        $this->setHandRank($player3, 200, 'Two Pair');
        
        $winners = WinnerCalculator::calculateWinners($players);
        
        // Should have 3 winners
        $this->assertCount(3, $winners, 'Should have 3 winners');
        
        $totalWon = array_sum(array_column($winners, 'amount'));
        $this->assertEquals(301, $totalWon, 'Total winnings should equal pot of 301');
        
        // Each should get 100, remainder (1 chip) goes to first winner
        $amounts = array_column($winners, 'amount');
        sort($amounts);
        $this->assertEquals([100, 100, 101], $amounts, 'Remainder chip should go to first winner');
    }

    /**
     * Test side pot eligibility excludes folded players
     * Scenario 19: Side Pot Eligibility Excludes Folded Players
     */
    public function test_side_pot_eligibility_excludes_folded_players(): void
    {
        // Setup: 3 players, Seat 3 folded but contributed to pot
        $player1 = $this->makePlayerState(1, 0, ['allIn' => true, 'totalInvested' => 50, 'folded' => false]);
        $player2 = $this->makePlayerState(2, 0, ['allIn' => true, 'totalInvested' => 100, 'folded' => false]);
        $player3 = $this->makePlayerState(3, 0, ['totalInvested' => 100, 'folded' => true]);
        
        $players = [1 => $player1, 2 => $player2, 3 => $player3];
        
        // Set hand ranks (player 2 wins)
        $this->setHandRank($player1, 100, 'Pair');
        $this->setHandRank($player2, 200, 'Two Pair');
        // Player 3 has no hand rank (folded)
        
        $winners = WinnerCalculator::calculateWinners($players);
        
        // Should have one winner (player 2)
        $this->assertCount(1, $winners, 'Should have one winner');
        $this->assertEquals(2, $winners[0]['seat'], 'Player 2 should win');
        
        // Side pot 1: 50 * 3 = 150 (all 3 contributed, but only 1 and 2 eligible)
        // Side pot 2: 50 * 2 = 100 (only 2 and 3 contributed, but only 2 eligible)
        // Total: 250 (player 3's contribution is in pot but they're not eligible)
        $this->assertEquals(250, $winners[0]['amount'], 'Winner should get 250 (excluding folded player from eligibility)');
    }
}

