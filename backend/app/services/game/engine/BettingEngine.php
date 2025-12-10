<?php
declare(strict_types=1);

require_once __DIR__ . '/../rules/GameTypes.php';
require_once __DIR__ . '/../PlayerState.php';

/**
 * BettingEngine
 * -----------------------------------------------------------------------------
 * Pure betting logic on a single PlayerState.
 * - Does NOT touch GameState (pot, currentBet, etc.).
 * - Uses an "effective stack" for bets/raises to avoid unmatchable sizes.
 * - ALL-IN always means "use my entire stack" (true shove).
 *
 * GameState-level effects (pot updates, currentBet, lastRaiseAmount, etc.)
 * are handled by a higher-level ActionProcessor / GameService.
 */
final class BettingEngine
{
    // ========================================================
    // INTERNAL HELPERS
    // ========================================================

    /**
     * Effective stack helper
     * -------------------------------------------------------------------------
     * Determines the maximum amount a player can effectively wager this hand,
     * assuming we don't want unmatchable bet sizes.
     *
     * effectiveStack = min(
     *    my stack,
     *    min over opponents: opp.stack + opp.bet (for non-folded opponents)
     * )
     */
    private static function getEffectiveStack(PlayerState $player, array $allPlayers): int
    {
        $oppStacks = [];

        foreach ($allPlayers as $p) {
            if ($p !== $player && !$p->folded) {
                // Include their current street bet so action stays matchable
                $oppStacks[] = $p->stack + $p->bet;
            }
        }

        if (empty($oppStacks)) {
            return $player->stack;
        }

        return min($player->stack, min($oppStacks));
    }

    /**
     * Apply chips from player stack into the pot-facing fields.
     * -------------------------------------------------------------------------
     * Centralizes all modifications so we don't duplicate this block.
     *
     * - Decrements stack (clamped at 0 for safety).
     * - Increments per-street bet.
     * - Increments totalInvested and contribution.
     * - Sets allIn=true when stack hits 0.
     */
    private static function applyChips(PlayerState $player, int $chips): void
    {
        if ($chips <= 0) {
            return;
        }

        // Pay from stack
        $player->stack -= $chips;
        if ($player->stack < 0) {
            // Defensive clamp; ideally should never happen
            $player->stack = 0;
        }

        // Track bet / investment for this hand
        $player->bet           += $chips;
        $player->totalInvested += $chips;
        $player->contribution  += $chips;

        if ($player->stack === 0) {
            $player->allIn = true;
        }
    }

    // ========================================================
    // EXECUTE BETTING ACTION
    // ========================================================
    // Mutates PlayerState ONLY.
    // GameState-level changes (pot/currentBet/etc.) handled
    // by ActionProcessor / GameService.
    // ========================================================
    public static function executeAction(
        PlayerState $player,
        ActionType $action,
        int $amount,
        int $currentBet,
        int $bigBlindAmount,   // kept for interface compatibility; not used here
        int $lastRaiseAmount,
        array $allPlayers = [] // needed for effective-stack logic
    ): array {
        // Effective stack for bet/raise/call sizing
        $effectiveChips = self::getEffectiveStack($player, $allPlayers);

        $callAmount    = $currentBet - $player->bet;
        $availableChips = $effectiveChips;

        switch ($action) {

            // ---------------- FOLD ----------------
            case ActionType::FOLD:
                $player->folded = true;
                $player->actedThisStreet = true;

                return [
                    'ok'        => true,
                    'chipsUsed' => 0,
                ];

            // ---------------- CHECK ----------------
            case ActionType::CHECK:
                if ($callAmount > 0) {
                    return [
                        'ok'      => false,
                        'message' => 'Cannot check, must call or fold',
                    ];
                }

                $player->actedThisStreet = true;

                return [
                    'ok'        => true,
                    'chipsUsed' => 0,
                ];

            // ---------------- CALL ----------------
            case ActionType::CALL:
                if ($callAmount <= 0) {
                    // Nothing to call; treat as a "stand pat" action
                    $player->actedThisStreet = true;

                    return [
                        'ok'        => true,
                        'chipsUsed' => 0,
                        'newBet'    => $player->bet,
                    ];
                }

                // Cap call to effective stack (still consistent with no-oversize calls)
                $chips = min($callAmount, $availableChips);

                self::applyChips($player, $chips);
                $player->actedThisStreet = true;

                return [
                    'ok'        => true,
                    'chipsUsed' => $chips,
                    'newBet'    => $player->bet,
                    'isAllIn'   => $player->allIn,
                ];

            // ---------------- BET ----------------
            case ActionType::BET:
                if ($currentBet > 0) {
                    return [
                        'ok'      => false,
                        'message' => 'Cannot bet, bet already exists',
                    ];
                }
                if ($amount <= 0 || $amount > $availableChips) {
                    return [
                        'ok'      => false,
                        'message' => 'Bet exceeds effective stack',
                    ];
                }

                $chips = $amount;

                self::applyChips($player, $chips);
                $player->actedThisStreet = true;

                return [
                    'ok'        => true,
                    'chipsUsed' => $chips,
                    'newBet'    => $player->bet,
                    'isAllIn'   => $player->allIn,
                ];

            // ---------------- RAISE ----------------
            case ActionType::RAISE:
                if ($currentBet === 0) {
                    return [
                        'ok'      => false,
                        'message' => 'Cannot raise, no live bet',
                    ];
                }

                $minRaiseTo = $currentBet + $lastRaiseAmount;  // minimum "to" amount
                $raiseTo    = $currentBet + $amount;           // requested "to" amount

                if ($raiseTo < $minRaiseTo) {
                    return [
                        'ok'      => false,
                        'message' => "Minimum raise is to {$minRaiseTo}",
                    ];
                }

                $chipsNeeded = $raiseTo - $player->bet;

                if ($chipsNeeded > $availableChips) {
                    return [
                        'ok'      => false,
                        'message' => 'Raise exceeds effective stack',
                    ];
                }

                self::applyChips($player, $chipsNeeded);
                $player->actedThisStreet = true;

                return [
                    'ok'        => true,
                    'chipsUsed' => $chipsNeeded,
                    'newBet'    => $player->bet,
                    'isAllIn'   => $player->allIn,
                ];

            // ---------------- ALL-IN ----------------
            case ActionType::ALLIN:
                // TRUE all-in: shove the real stack, not the effective stack
                if ($player->stack <= 0) {
                    return [
                        'ok'      => false,
                        'message' => 'Cannot all-in with 0 chips',
                    ];
                }

                $chips = $player->stack;

                self::applyChips($player, $chips); // this will set stack=0, allIn=true
                $player->actedThisStreet = true;

                return [
                    'ok'        => true,
                    'chipsUsed' => $chips,
                    'newBet'    => $player->bet,
                    'isAllIn'   => true,
                ];
        }

        return [
            'ok'      => false,
            'message' => 'Unknown action',
        ];
    }

    // ========================================================
    // LEGAL ACTION GENERATION
    // ========================================================
    // Uses effective stack for sizing bet/raise options so we
    // don't offer unmatchable amounts. ALL-IN remains available
    // based on the player's real stack.
    // ========================================================
    public static function getLegalActions(
        PlayerState $player,
        int $currentBet,
        int $lastRaiseAmount,
        array $allPlayers
    ): array {
        if ($player->folded || $player->allIn) {
            return [];
        }

        // Effective stack for bet/raise sizing
        $effectiveChips = self::getEffectiveStack($player, $allPlayers);

        $callAmount     = $currentBet - $player->bet;
        $availableChips = $effectiveChips;

        // Determine if there's at least one live opponent for raise eligibility
        $hasLiveOpponent = false;
        foreach ($allPlayers as $opp) {
            if ($opp !== $player && !$opp->folded && !$opp->allIn) {
                $hasLiveOpponent = true;
                break;
            }
        }

        $actions = [];

        // --- check / call / fold ---
        if ($callAmount <= 0) {
            $actions[] = ActionType::CHECK;
        } else {
            $actions[] = ActionType::CALL;
            $actions[] = ActionType::FOLD;
        }

        // --- bet ---
        if ($currentBet === 0 && $availableChips > 0) {
            $actions[] = ActionType::BET;
        }

        // --- raise ---
        if (
            $currentBet > 0 &&
            $availableChips > $callAmount &&
            $hasLiveOpponent &&
            ($player->bet + $availableChips >= $currentBet + $lastRaiseAmount)
        ) {
            $actions[] = ActionType::RAISE;
        }

        // --- all-in (true shove: any positive stack) ---
        if ($player->stack > 0) {
            $actions[] = ActionType::ALLIN;
        }

        return $actions;
    }

    // ========================================================
    // BETTING ROUND COMPLETION CHECK
    // ========================================================
    public static function isBettingRoundComplete(
        array $activePlayers,
        int $actionSeat,
        int $currentBet,
        int $lastRaiseSeat
    ): bool {
        if (count($activePlayers) <= 1) {
            return true;
        }

        // If no live (non-folded, non-all-in) players remain, round is trivially done
        $anyLive = false;
        foreach ($activePlayers as $p) {
            if (!$p->folded && !$p->allIn) {
                $anyLive = true;
                break;
            }
        }
        if (!$anyLive) {
            return true;
        }

        // If there's no live bet, everyone just needs to have acted this street
        if ($currentBet === 0) {
            foreach ($activePlayers as $p) {
                if (!$p->folded && !$p->allIn && !$p->actedThisStreet) {
                    return false;
                }
            }
            return true;
        }

        // There is a live bet: everyone must have acted AND matched currentBet
        foreach ($activePlayers as $p) {
            if (!$p->folded && !$p->allIn && !$p->actedThisStreet) {
                return false;
            }
            if (!$p->folded && !$p->allIn && $p->bet < $currentBet) {
                return false;
            }
        }

        return true;
    }

    // ========================================================
    // BLIND POSTING
    // ========================================================
    public static function postBlinds(
        array $players,
        int $smallBlindSeat,
        int $bigBlindSeat,
        int $sbAmount,
        int $bbAmount
    ): array {
        $sb = $players[$smallBlindSeat];
        $bb = $players[$bigBlindSeat];

        $pot = 0;

        // Small blind
        $smallBlindPaid = min($sbAmount, $sb->stack);
        self::applyChips($sb, $smallBlindPaid);
        $pot += $smallBlindPaid;

        // Big blind
        $bigBlindPaid = min($bbAmount, $bb->stack);
        self::applyChips($bb, $bigBlindPaid);
        $pot += $bigBlindPaid;

        return [
            'pot'        => $pot,
            'currentBet' => $bigBlindPaid,
        ];
    }
}
