<?php
// backend/app/services/PhaseManager.php
// -----------------------------------------------------------------------------
// Deals flop/turn/river and determines who acts first.
// DOES NOT reset player bets or actedThisStreet flags.
// That is handled exclusively inside PhaseEngine.
// -----------------------------------------------------------------------------

declare(strict_types=1);

require_once __DIR__ . '/../rules/GameTypes.php';
require_once __DIR__ . '/../PlayerState.php';
require_once __DIR__ . '/../cards/DealerService.php';

final class PhaseManager
{
    /**
     * Deal the flop (3 community cards)
     */
    public static function dealFlop(
        DealerService $dealer,
        array $players,
        int $dealerSeat
    ): array {
        $board = $dealer->dealCards(3);

        // First player to act = left of dealer (UTG)
        $actionSeat = self::getNextActiveSeat($players, $dealerSeat);

        return [
            'board'      => $board,
            'actionSeat' => $actionSeat
        ];
    }

    /**
     * Deal the turn (1 new card)
     */
    public static function dealTurn(
        DealerService $dealer,
        array $players,
        array $board,
        int $dealerSeat
    ): array {
        $board = array_merge($board, $dealer->dealCards(1));

        $actionSeat = self::getNextActiveSeat($players, $dealerSeat);

        return [
            'board'      => $board,
            'actionSeat' => $actionSeat
        ];
    }

    /**
     * Deal the river (1 new card)
     */
    public static function dealRiver(
        DealerService $dealer,
        array $players,
        array $board,
        int $dealerSeat
    ): array {
        $board = array_merge($board, $dealer->dealCards(1));

        $actionSeat = self::getNextActiveSeat($players, $dealerSeat);

        return [
            'board'      => $board,
            'actionSeat' => $actionSeat
        ];
    }

    /**
     * Get the next non-folded, non-all-in player after a given seat.
     */
    public static function getNextActiveSeat(array $players, int $startSeat): int
    {
        $seats = array_keys($players);
        sort($seats);

        $startIndex = array_search($startSeat, $seats, true);
        if ($startIndex === false) {
            return $seats[0] ?? -1;
        }

        $count = count($seats);

        for ($i = 1; $i <= $count; $i++) {
            $idx = ($startIndex + $i) % $count;
            $seat = $seats[$idx];
            $p    = $players[$seat];

            if (!$p->folded && !$p->allIn) {
                return $seat;
            }
        }

        return -1;
    }
}
