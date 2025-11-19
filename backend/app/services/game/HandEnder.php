<?php
// backend/app/services/game/HandEnder.php
// -----------------------------------------------------------------------------
// New HandEnder:
//  - NO chip movement
//  - NO side pot logic
//  - NO WinnerCalculator::evaluate()
//  - Simply determines whether hand ended by fold or showdown,
//    and lets GameService + WinnerCalculator handle the settlement.
// -----------------------------------------------------------------------------

declare(strict_types=1);

require_once __DIR__ . '/GameState.php';
require_once __DIR__ . '/cards/HandEvaluator.php';
require_once __DIR__ . '/rules/WinnerCalculator.php';
require_once __DIR__ . '/rules/GameTypes.php';

final class HandEnder
{
    /**
     * Determine if the hand has ended (fold to one player or showdown),
     * BUT DO NOT MOVE CHIPS.
     *
     * @return array{
     *   handEnded: bool,
     *   reason: string,            // "fold" or "showdown"
     *   winnerSeat?: int,
     *   winnerAmount?: int,
     *   board?: array,
     * }
     */
    public static function endHand(GameState $state): array
    {
        $players = $state->players;

        // ======================================================
        // FOLD-DOWN → 1 active player wins immediately
        // ======================================================
        $active = array_filter($players, fn($p) => !$p->folded);

        if (count($active) === 1) {
            $seat = array_key_first($active);

            // *IMPORTANT*
            // We DO NOT move chips here.
            // GameService will apply the payout ONCE using WinnerCalculator or
            // direct fold-winner amount already provided by ActionProcessor.
            return [
                'handEnded'     => true,
                'reason'        => 'fold',
                'winnerSeat'    => $seat,
                'board'         => $state->board,
            ];
        }

        // ======================================================
        // SHOWDOWN
        // ======================================================
        // We DO NOT settle the pot here.
        // We only tell GameService that it's time for showdown settlement.

        return [
            'handEnded' => true,
            'reason'    => 'showdown',
            'board'     => $state->board,
        ];
    }
}
