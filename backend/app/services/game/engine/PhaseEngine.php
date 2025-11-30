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
     * ========================================================
     * ADVANCE PHASE
     * ========================================================
     * Called after each successful action.
     *
     * Returns:
     *   null
     *      → no phase change, hand continues normally
     *
     *   array{ handEnded: true, reason: string }
     *      → hand is over (showdown or fold-down)
     *
     * Heads-up rule:
     *   - If a betting round is complete AND any player is all-in,
     *     we auto-run all remaining streets (flop/turn/river) in
     *     one pass and then go straight to showdown.
     * ========================================================
     */
    public static function advance(GameState $state, HandEvaluator $evaluator): ?array
    {
        // Active = players who haven't folded
        $active = array_filter(
            $state->players,
            static fn(PlayerState $p) => !$p->folded
        );

        if (count($active) === 0) {
            // Nothing to do (shouldn't happen in normal play)
            return null;
        }

        // Heads-up only, but keep it explicit
        $isHeadsUp = (count($active) <= 2);

        // Is the current betting round naturally complete?
        $bettingComplete = BettingEngine::isBettingRoundComplete(
            $active,
            $state->actionSeat,
            $state->currentBet,
            $state->lastRaiseSeat
        );

        // Simple all-in detection among non-folded players
        $someoneAllIn = self::anyPlayerAllIn($active);

        // Generic auto-complete conditions (old behavior)
        $autoComplete =
            $state->actionSeat === -1 ||
            self::everyoneAllInOrFolded($active);

        // If betting is not complete AND we don't have any auto-complete reason,
        // we stop here and wait for more actions.
        if (
            !$bettingComplete &&
            !$autoComplete
        ) {
            return null;
        }

        // ========================================================
        // CASE 1: HEADS-UP ALL-IN → AUTO-RUN FULL BOARD
        // ========================================================
        //
        // At this point, the betting round is complete (or autoComplete = true),
        // and at least one player is all-in. In a 2-player game this means:
        //   - No more betting should ever occur
        //   - We should immediately deal out all remaining streets
        //     and go to showdown.
        // ========================================================
        if ($isHeadsUp && $someoneAllIn) {
            while (true) {
                switch ($state->phase) {
                    case Phase::PREFLOP:
                        self::onFlop($state);
                        break;

                    case Phase::FLOP:
                        self::onTurn($state);
                        break;

                    case Phase::TURN:
                        self::onRiver($state);
                        break;

                    case Phase::RIVER:
                        // Final street already dealt → showdown now
                        return self::onShowdown($state);

                    default:
                        // Unknown / unsupported phase: do nothing
                        return null;
                }

                // After dealing a street, if somehow there are no
                // active players left, just bail out.
                $active = array_filter(
                    $state->players,
                    static fn(PlayerState $p) => !$p->folded
                );
                if (empty($active)) {
                    return null;
                }
            }
        }

        // ========================================================
        // CASE 2: NORMAL SINGLE-STREET ADVANCE
        // ========================================================
        return match ($state->phase) {
            Phase::PREFLOP => self::onFlop($state),
            Phase::FLOP    => self::onTurn($state),
            Phase::TURN    => self::onRiver($state),
            Phase::RIVER   => self::onShowdown($state),
            default        => null,
        };
    }

    // ========================================================
    // HELPERS
    // ========================================================

    /**
     * True if ANY of the given players is marked all-in.
     */
    private static function anyPlayerAllIn(array $players): bool
    {
        foreach ($players as $p) {
            if ($p->allIn) {
                return true;
            }
        }
        return false;
    }

    /**
     * True if every given player is either folded or all-in.
     */
    private static function everyoneAllInOrFolded(array $players): bool
    {
        foreach ($players as $p) {
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
     * Normalize a seat so only a non-folded, non-all-in player
     * can have the action. Returns -1 if no such seat exists.
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
            // Find the first valid seat
            foreach ($state->players as $s => $p) {
                if (!$p->folded && !$p->allIn) {
                    return $s;
                }
            }
            return -1;
        }

        return $seat;
    }

    // ========================================================
    // STREET HANDLERS
    // ========================================================

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

    private static function onShowdown(GameState $state): array
    {
        // HandEnder determines fold/showdown reason, but does NOT move chips.
        $result = HandEnder::endHand($state);

        return [
            'handEnded' => true,
            'reason'    => $result['reason'] ?? 'showdown',
        ];
    }
}
