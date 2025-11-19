<?php
declare(strict_types=1);

require_once __DIR__ . '/../cards/HandEvaluator.php';

/**
 * WinnerCalculator (2-player simplified version)
 *
 * - EXACTLY 1 POT.
 * - EXACTLY 1 WINNER unless a true tie.
 * - No side-pots.
 * - Folded players cannot win.
 * - Uses HandEvaluator::evaluate($all7cards).
 *
 * Returns:
 *   [
 *     totalPot => int,
 *     payouts  => [ seat => amount ],
 *     pots     => [],               // kept for UI shape compatibility
 *     handRanks=> [
 *         [ seat, rank, name, bestCards ]
 *     ]
 *   ]
 */
final class WinnerCalculator
{
    private HandEvaluator $evaluator;

    public function __construct(HandEvaluator $evaluator)
    {
        $this->evaluator = $evaluator;
    }

    /**
     * @param array<int, array{
     *   seat:int,
     *   user_id:int,
     *   cards:string[],
     *   folded:bool,
     *   contribution:int
     * }> $players
     * @param string[] $board
     */
    public function calculate(array $players, array $board): array
    {
        //
        // 1. Compute total pot
        //
        $totalPot = 0;
        foreach ($players as $p) {
            $totalPot += max(0, (int)$p['contribution']);
        }

        if ($totalPot <= 0) {
            return [
                'totalPot' => 0,
                'payouts'  => [],
                'pots'     => [],
                'handRanks'=> [],
            ];
        }

        //
        // 2. Evaluate hands for non-folded players with >0 contribution
        //
        $handRanks = [];   // seat => detail
        $rankVals  = [];   // seat => numeric rank_value

        foreach ($players as $p) {
            $seat   = (int)$p['seat'];
            $folded = (bool)$p['folded'];
            $c      = max(0, (int)$p['contribution']);

            if ($folded || $c <= 0) {
                // Folded / no contribution cannot win
                continue;
            }

            $all7 = array_merge($p['cards'], $board);
            $eval = $this->evaluator->evaluate($all7);

            $handRanks[$seat] = [
                'seat'      => $seat,
                'rank'      => $eval['rank_value'],   // HIGHER IS BETTER
                'name'      => $eval['hand_name'],
                'bestCards' => $eval['best_hand'],
            ];

            $rankVals[$seat] = $eval['rank_value'];
        }

        //
        // Safety: must have at least 1 contender
        //
        if (empty($rankVals)) {
            // All players folded? Should be caught earlier but handle safely.
            $payouts = [];
            return [
                'totalPot' => $totalPot,
                'payouts'  => $payouts,
                'pots'     => [],
                'handRanks'=> array_values($handRanks),
            ];
        }

        //
        // 3. Determine winner(s)
        //
        $bestRank = max($rankVals);  // higher is better
        $winnerSeats = [];

        foreach ($rankVals as $seat => $rv) {
            if ($rv === $bestRank) {
                $winnerSeats[] = $seat;
            }
        }

        //
        // 4. Assign payouts
        //
        $payouts = [];

        foreach ($rankVals as $seat => $rv) {
            $payouts[$seat] = 0;
        }

        if (count($winnerSeats) === 1) {
            //
            // Single winner takes entire pot
            //
            $win = $winnerSeats[0];
            $payouts[$win] = $totalPot;

        } else {
            //
            // EXACT tie → split pot evenly
            //
            $share = intdiv($totalPot, count($winnerSeats));
            foreach ($winnerSeats as $s) {
                $payouts[$s] = $share;
            }
        }

        //
        // 5. Chip conservation: payouts must equal pot
        //
        $check = array_sum($payouts);
        if ($check !== $totalPot) {
            throw new \RuntimeException(
                "WinnerCalculator: payout mismatch! totalPot=$totalPot sumPayouts=$check"
            );
        }

        //
        // Final structure
        //
        return [
            'totalPot' => $totalPot,
            'payouts'  => $payouts,
            'pots'     => [],                     // kept for UI compatibility
            'handRanks'=> array_values($handRanks),
        ];
    }
}
