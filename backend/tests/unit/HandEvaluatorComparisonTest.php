<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/services/game/cards/HandEvaluator.php';

final class HandEvaluatorComparisonTest extends TestCase
{
    private HandEvaluator $eval;

    protected function setUp(): void
    {
        $this->eval = new HandEvaluator();
    }

    private function compareHands(array $p1, array $p2, array $board): int
    {
        $r1 = $this->eval->evaluate(array_merge($p1, $board))['rank_value'];
        $r2 = $this->eval->evaluate(array_merge($p2, $board))['rank_value'];

        return $r1 <=> $r2; // -1 p1 loses, 0 tie, 1 p1 wins
    }

    private function assertWinner(array $p1, array $p2, array $board, string $msg = ''): void
    {
        $this->assertSame(1, $this->compareHands($p1, $p2, $board), "P1 should win. $msg");
    }

    private function assertTie(array $p1, array $p2, array $board, string $msg = ''): void
    {
        $this->assertSame(0, $this->compareHands($p1, $p2, $board), "Tie expected. $msg");
    }

    private function assertLoser(array $p1, array $p2, array $board, string $msg = ''): void
    {
        $this->assertSame(-1, $this->compareHands($p1, $p2, $board), "P1 should lose. $msg");
    }

    // ================================================================
    // HIGH CARD
    // ================================================================

    public function test_high_card_kicker(): void
    {
        $p1 = ['AC', '7D']; // A high
        $p2 = ['KC', '7S']; // K high
        $board = ['2D', '4C', '9H', 'JD', '3S'];

        $this->assertWinner($p1, $p2, $board);
    }

    public function test_high_card_tie(): void
    {
        $p1 = ['AC', '7D'];
        $p2 = ['AD', '7C'];
        $board = ['2H', '3D', '4S', '9C', 'KH']; // both A-K-9-7-4

        $this->assertTie($p1, $p2, $board);
    }

    // ================================================================
    // PAIR
    // ================================================================

    public function test_pair_kicker(): void
    {
        $p1 = ['8C', 'AD']; // pair 8s, A kicker
        $p2 = ['8D', '2S']; // pair 8s, 2 kicker
        $board = ['8H', 'KC', 'TS', '4D', '3C'];

        $this->assertWinner($p1, $p2, $board);
    }

    public function test_pair_full_kicker_runoff(): void
    {
        $p1 = ['QS', '7C']; // pair Q, kickers 7,5,4
        $p2 = ['QD', '2C']; // pair Q, kickers 5,4,2
        $board = ['QH', '5D', '4C', '9S', '3D'];

        $this->assertWinner($p1, $p2, $board);
    }

    public function test_pair_exact_tie(): void
    {
        $p1 = ['AC', '2D'];
        $p2 = ['AD', '3C'];
        $board = ['AH', 'KC', 'QS', 'TD', 'JS']; // best 5 all on board

        $this->assertTie($p1, $p2, $board);
    }

    // ================================================================
    // TWO PAIR
    // ================================================================

    public function test_two_pair_top_pair_decider(): void
    {
        $p1 = ['KC', '7C'];
        $p2 = ['QD', '8C'];
        $board = ['KD', 'QS', '7S', '8D', '3D'];

        $this->assertWinner($p1, $p2, $board);
    }

    public function test_two_pair_kicker(): void
    {
        $p1 = ['9S', 'AC']; // kicker A
        $p2 = ['9H', 'KD']; // kicker K
        $board = ['9C', '9D', '7C', '5D', '3C'];

        $this->assertWinner($p1, $p2, $board);
    }

    public function test_two_pair_exact_tie(): void
    {
        $p1 = ['KC', 'QD'];
        $p2 = ['KD', 'QC'];
        $board = ['KH', 'QS', 'TD', '7C', '3S'];

        $this->assertTie($p1, $p2, $board);
    }

    // ================================================================
    // TRIPS
    // ================================================================

    public function test_trips_kicker(): void
    {
        $p1 = ['8C', 'AC']; // trips 8+A kicker
        $p2 = ['8S', 'KD']; // trips 8+K kicker
        $board = ['8H', '8D', '4C', '3D', '2S'];

        $this->assertWinner($p1, $p2, $board);
    }

    // ================================================================
    // STRAIGHTS
    // ================================================================

    public function test_straight_vs_lower_straight(): void
    {
        $p1 = ['AC', 'KD']; // broadway
        $p2 = ['9C', '8D']; // 9 high
        $board = ['QC', 'JD', 'TC', '2S', '3D'];

        $this->assertWinner($p1, $p2, $board);
    }

    public function test_wheel_beats_highcard(): void
    {
        $p1 = ['AC', '2D']; // wheel A2345
        $p2 = ['KS', 'QC'];
        $board = ['3D', '4C', '5S', '9D', 'JC'];

        $this->assertWinner($p1, $p2, $board);
    }

    public function test_straight_exact_tie(): void
    {
        $p1 = ['AC', 'KD'];
        $p2 = ['AD', 'KC'];
        $board = ['QC', 'JD', 'TH', '4S', '2C'];

        $this->assertTie($p1, $p2, $board);
    }

    // ================================================================
    // FLUSH
    // ================================================================

    public function test_flush_vs_flush_kicker(): void
    {
        $p1 = ['AC', '2C']; // A-high flush
        $p2 = ['KC', 'QC']; // K-high flush
        $board = ['9C', '7C', '4C', '2D', '3S'];

        $this->assertWinner($p1, $p2, $board);
    }

    // 1) Neither player has clubs → both must use board flush → tie    
    public function test_flush_board_only_tie(): void
    {
        $p1 = ['AH', '2D']; // no clubs
        $p2 = ['AS', '2H']; // no clubs

        $board = ['KC', 'QC', 'JC', '9C', '3C']; // 5-card club flush

        $this->assertTie($p1, $p2, $board, "Both players must use board flush.");
    }


    // 2) Both players have clubs, but ONLY low clubs below the board quality → still tie
    public function test_flush_both_have_low_clubs_but_board_still_wins(): void
    {
        // Both have low clubs BELOW the board's lowest club
        $p1 = ['4C', '2D']; 
        $p2 = ['5C', '2H'];
    
        // Board flush: K♣ Q♣ J♣ 9♣ 8♣ (lowest = 8♣)
        $board = ['KC', 'QC', 'JC', '9C', '8C'];
    
        // Neither 4♣ nor 5♣ can replace 8♣ → exact tie
        $this->assertTie($p1, $p2, $board, "Low clubs cannot improve board flush.");
    }    

    // 3) One player has a higher club than the lowest board club → that player wins
    public function test_flush_player_improves_flush_with_higher_club(): void
    {
        $p1 = ['AC', '2D']; // Ace of clubs → higher than ALL board clubs
        $p2 = ['7H', '8S']; // no clubs

        // Board flush: K♣ Q♣ J♣ 9♣ 3♣ → highest = K♣
        $board = ['KC', 'QC', 'JC', '9C', '3C'];

        // P1 best flush = A♣ K♣ Q♣ J♣ 9♣  ← STRONGER
        // P2 best flush = K♣ Q♣ J♣ 9♣ 3♣  ← BOARD
        $this->assertWinner($p1, $p2, $board, "P1 improves flush with higher club.");
    }

    // ================================================================
    // FULL HOUSES
    // ================================================================

    // Trips rank decides: AAAKK vs KKKAA (P1 should win)
    public function test_full_house_trips_decider(): void
    {
        // Board: A♥, A♦, K♠, 7♣, 2♣
        $board = ['AH', 'AD', 'KS', '7C', '2C'];

        // P1: AC, KD -> AAAKK (Aces full of Kings)
        $p1 = ['AC', 'KD'];

        // P2: KC, KH -> KKKAA (Kings full of Aces)
        $p2 = ['KC', 'KH'];

        $this->assertWinner($p1, $p2, $board, 'Trips rank should decide full house.');
    }

    // Pair rank decides: QQQJJ vs QQQTT (P1 should win)
    public function test_full_house_pair_decider(): void
    {
        // Board: Q♣, Q♦, Q♥, J♦, T♦
        $board = ['QC', 'QD', 'QH', 'JD', 'TD'];

        // P1: J♠, 2♣ -> QQQJJ (trips Q, pair J)
        $p1 = ['JS', '2C'];

        // P2: T♠, 2♦ -> QQQTT (trips Q, pair T)
        $p2 = ['TS', '2D'];

        $this->assertWinner($p1, $p2, $board, 'Pair rank should decide full house when trips are equal.');
    }

    // ================================================================
    // QUADS
    // ================================================================

    public function test_quads_kicker(): void
    {
        $p1 = ['AC', 'KC'];
        $p2 = ['AD', '2D'];
        $board = ['AH', 'AS', 'AC', '3C', '4C'];

        $this->assertWinner($p1, $p2, $board);
    }

    // ================================================================
    // STRAIGHT FLUSH / ROYAL
    // ================================================================

    public function test_straight_flush_beats_quads(): void
    {
        $p1 = ['9C', 'TC']; // straight flush
        $p2 = ['AD', 'AS']; // big hand but not SF
        $board = ['JC', 'QC', 'KC', 'AH', 'AD'];

        $this->assertWinner($p1, $p2, $board);
    }

    public function test_royal_flush(): void
    {
        $p1 = ['AC', 'KC'];
        $p2 = ['AD', 'AH'];
        $board = ['QC', 'JC', 'TC', '2D', '3C'];

        $this->assertWinner($p1, $p2, $board);
    }
}
