<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/services/game/rules/WinnerCalculator.php';
require_once __DIR__ . '/../../app/services/game/cards/HandEvaluator.php';

final class WinnerCalculatorTest extends TestCase
{
    private function calculate(array $players, array $board): array
    {
        $calculator = new WinnerCalculator(new HandEvaluator());
        return $calculator->calculate($players, $board);
    }

    private function player(int $seat, array $cards, int $contribution, bool $folded = false): array
    {
        return [
            'seat' => $seat,
            'user_id' => $seat,
            'cards' => $cards,
            'folded' => $folded,
            'contribution' => $contribution,
        ];
    }

    public function test_single_winner_takes_full_pot(): void
    {
        $result = $this->calculate([
            $this->player(1, ['AS', 'AD'], 100),
            $this->player(2, ['KH', 'KD'], 100),
        ], ['2C', '7D', '9H', 'JC', '3S']);

        $this->assertSame(200, $result['totalPot']);
        $this->assertSame(200, $result['payouts'][1]);
        $this->assertSame(0, $result['payouts'][2]);
        $this->assertCount(2, $result['handRanks']);
    }

    public function test_exact_ties_split_pot_with_deterministic_remainder(): void
    {
        $result = $this->calculate([
            $this->player(1, ['AS', 'KD'], 101),
            $this->player(2, ['AC', 'KH'], 100),
        ], ['QH', 'JH', '10D', '2C', '3S']);

        $this->assertSame(201, $result['totalPot']);
        $this->assertSame(101, $result['payouts'][1]);
        $this->assertSame(100, $result['payouts'][2]);
    }

    public function test_folded_players_are_excluded_from_winner_selection(): void
    {
        $result = $this->calculate([
            $this->player(1, ['AS', 'AD'], 100),
            $this->player(2, ['KH', 'KD'], 100, true),
        ], ['2C', '7D', '9H', 'JC', '3S']);

        $this->assertSame(200, $result['totalPot']);
        $this->assertSame(200, $result['payouts'][1]);
        $this->assertArrayNotHasKey(2, $result['payouts']);
        $this->assertCount(1, $result['handRanks']);
    }

    public function test_zero_pot_returns_empty_payouts(): void
    {
        $result = $this->calculate([
            $this->player(1, ['AS', 'AD'], 0),
            $this->player(2, ['KH', 'KD'], 0),
        ], ['2C', '7D', '9H', 'JC', '3S']);

        $this->assertSame(0, $result['totalPot']);
        $this->assertSame([], $result['payouts']);
        $this->assertSame([], $result['handRanks']);
    }
}

