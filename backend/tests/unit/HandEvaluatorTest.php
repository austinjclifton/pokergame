<?php
// backend/tests/unit/HandEvaluatorTest.php
// -----------------------------------------------------------------------------
// Unit tests for HandEvaluator - hand evaluation correctness.
// -----------------------------------------------------------------------------

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/services/game/cards/HandEvaluator.php';

final class HandEvaluatorTest extends TestCase
{
    /**
     * Test hand evaluation: straight flush beats four of a kind
     * Scenario 13: Hand Evaluation Correctness
     */
    public function test_hand_evaluation_straight_flush_beats_four_of_a_kind(): void
    {
        $evaluator = new HandEvaluator();
        
        // Player 1: Straight flush (9s, 10s, Js, Qs, Ks)
        $player1Cards = ['9S', 'KS', '10S', 'JS', 'QS', '2H', '3D'];
        $result1 = $evaluator->evaluate($player1Cards);
        
        // Player 2: Four of a kind (Aces)
        $player2Cards = ['AS', 'AH', 'AD', 'AC', 'KH', 'QH', 'JH'];
        $result2 = $evaluator->evaluate($player2Cards);
        
        // Straight flush should have higher rank than four of a kind
        $this->assertGreaterThan($result2['rank_value'], $result1['rank_value'], 
            'Straight flush should beat four of a kind');
        
        $this->assertEquals('Straight Flush', $result1['hand_name'], 
            'Player 1 should have straight flush');
        $this->assertEquals('Four of a Kind', $result2['hand_name'], 
            'Player 2 should have four of a kind');
    }
}
