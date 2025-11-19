<?php
declare(strict_types=1);

require_once __DIR__ . '/../GameState.php';
require_once __DIR__ . '/../PlayerState.php';
require_once __DIR__ . '/../rules/GameTypes.php';
require_once __DIR__ . '/../cards/HandEvaluator.php';
require_once __DIR__ . '/../engine/BettingEngine.php';
require_once __DIR__ . '/../engine/PhaseManager.php';
require_once __DIR__ . '/../HandEnder.php';

final class PhaseEngine
{
    /**
     * Called after each successful action.
     *
     * Returns:
     *   null → continue normally
     *   array{ handEnded: true, reason: string } → showdown or fold-down
     */
    public static function advance(GameState $state, HandEvaluator $evaluator): ?array
    {
        // Active = players who haven't folded
        $active = array_filter(
            $state->players,
            static fn(PlayerState $p) => !$p->folded
        );

        if (count($active) === 0) {
            // Should not happen, but nothing to process
            return null;
        }

        // If no actions are possible (all-in or folded), betting auto-completes
        $autoComplete =
            $state->actionSeat === -1 ||
            self::everyoneAllInOrFolded($active);

        // If betting is not complete AND not auto-complete, stop.
        if (
            !$autoComplete &&
            !BettingEngine::isBettingRoundComplete(
                $active,
                $state->actionSeat,
                $state->currentBet,
                $state->lastRaiseSeat
            )
        ) {
            return null;
        }

        // Betting completed or not possible → determine next phase
        return match ($state->phase) {
            Phase::PREFLOP => self::onFlop($state),
            Phase::FLOP    => self::onTurn($state),
            Phase::TURN    => self::onRiver($state),
            Phase::RIVER   => self::onShowdown($state),
            default        => null,
        };
    }

    /**
     * Check if all remaining players cannot act.
     */
    private static function everyoneAllInOrFolded(array $activePlayers): bool
    {
        foreach ($activePlayers as $p) {
            if (!$p->folded && !$p->allIn) {
                return false;
            }
        }
        return true;
    }

    /**
     * Reset betting state between streets.
     */
    private static function resetForNewStreet(GameState $state): void
    {
        $state->currentBet      = 0;
        $state->lastRaiseSeat   = -1;
        $state->lastRaiseAmount = 0;

        foreach ($state->players as $p) {
            $p->bet = 0;

            if (!$p->folded && !$p->allIn) {
                $p->actedThisStreet = false;
            }
        }
    }

    /**
     * Normalize a seat selection by PhaseManager.
     */
    private static function normalizeActionSeat(GameState $state, int $seat): int
    {
        if ($seat === -1) {
            return -1;
        }

        if (
            $seat < 0 ||
            !isset($state->players[$seat]) ||
            $state->players[$seat]->folded ||
            $state->players[$seat]->allIn
        ) {
            // Find next valid seat
            foreach ($state->players as $s => $p) {
                if (!$p->folded && !$p->allIn) {
                    return $s;
                }
            }
            return -1;
        }

        return $seat;
    }

    // -------------------------------------------------------------------
    // FLOP
    // -------------------------------------------------------------------
    private static function onFlop(GameState $state): ?array
    {
        $result = PhaseManager::dealFlop(
            $state->dealer,
            $state->players,
            $state->dealerSeat
        );

        $state->board = $result['board'];
        $state->phase = Phase::FLOP;

        self::resetForNewStreet($state);
        $state->actionSeat = self::normalizeActionSeat($state, $result['actionSeat']);

        return null;
    }

    // -------------------------------------------------------------------
    // TURN
    // -------------------------------------------------------------------
    private static function onTurn(GameState $state): ?array
    {
        $result = PhaseManager::dealTurn(
            $state->dealer,
            $state->players,
            $state->board,
            $state->dealerSeat
        );

        $state->board = $result['board'];
        $state->phase = Phase::TURN;

        self::resetForNewStreet($state);
        $state->actionSeat = self::normalizeActionSeat($state, $result['actionSeat']);

        return null;
    }

    // -------------------------------------------------------------------
    // RIVER
    // -------------------------------------------------------------------
    private static function onRiver(GameState $state): ?array
    {
        $result = PhaseManager::dealRiver(
            $state->dealer,
            $state->players,
            $state->board,
            $state->dealerSeat
        );

        $state->board = $result['board'];
        $state->phase = Phase::RIVER;

        self::resetForNewStreet($state);
        $state->actionSeat = self::normalizeActionSeat($state, $result['actionSeat']);

        return null;
    }

    // -------------------------------------------------------------------
    // SHOWDOWN
    // -------------------------------------------------------------------
    private static function onShowdown(GameState $state): array
    {
        // HandEnder only detects fold / showdown. It no longer moves chips.
        $result = HandEnder::endHand($state);

        return [
            'handEnded' => true,
            'reason'    => $result['reason'] ?? 'showdown',
        ];
    }
}
