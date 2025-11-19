<?php
declare(strict_types=1);

final class BlindRotator
{
    /**
     * Rotate dealer, small blind, and big blind.
     *
     * @param array<int,int> $seats Sorted list of seat numbers
     * @param int $currentDealer Current dealer seat
     * @return array{dealer:int, sb:int, bb:int}
     */
    public static function rotate(array $seats, int $currentDealer): array
    {
        $count = count($seats);
        if ($count < 2) {
            throw new RuntimeException("Cannot rotate blinds with fewer than 2 seats");
        }

        $dealerIdx = array_search($currentDealer, $seats, true);

        // If starting fresh / invalid dealer, start at seat 0
        if ($dealerIdx === false) {
            $dealerIdx = 0;
        }

        $nextDealerIdx = ($dealerIdx + 1) % $count;
        $dealer = $seats[$nextDealerIdx];

        // Heads up: dealer IS small blind
        if ($count === 2) {
            $sb = $dealer;
            $bb = $seats[($nextDealerIdx + 1) % $count];
            return compact('dealer', 'sb', 'bb');
        }

        // 3+ players
        $sb = $seats[($nextDealerIdx + 1) % $count];
        $bb = $seats[($nextDealerIdx + 2) % $count];

        return compact('dealer', 'sb', 'bb');
    }
}
