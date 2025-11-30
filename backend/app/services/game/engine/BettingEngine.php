<?php
declare(strict_types=1);

require_once __DIR__ . '/../rules/GameTypes.php';
require_once __DIR__ . '/../PlayerState.php';

final class BettingEngine
{
    // ========================================================
    // EFFECTIVE STACK HELPER
    // ========================================================
    // Determines the maximum amount a player can ever wager
    // (prevents overshoves and eliminates sidepots).
    // Effective stack = min(my stack, any opponent's stack+bet)
    // ========================================================
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
     * ========================================================
     * EXECUTE BETTING ACTION
     * ========================================================
     * Mutates PlayerState ONLY.
     * GameState-level changes (pot/currentBet/etc.) handled
     * by ActionProcessor.
     * ========================================================
     */
    public static function executeAction(
        PlayerState $player,
        ActionType $action,
        int $amount,
        int $currentBet,
        int $bigBlindAmount,
        int $lastRaiseAmount,
        array $allPlayers = []   // *** ADDED to allow effective-stack logic ***
    ): array {

        // ================================
        // Effective stack enforcement
        // ================================
        $effectiveChips = self::getEffectiveStack($player, $allPlayers);

        $callAmount = $currentBet - $player->bet;
        $availableChips = $effectiveChips;

        switch ($action) {

            // ---------------- FOLD ----------------
            case ActionType::FOLD:
                $player->folded = true;
                $player->actedThisStreet = true;
                return ['ok' => true, 'chipsUsed' => 0];

            // ---------------- CHECK ----------------
            case ActionType::CHECK:
                if ($callAmount > 0) {
                    return ['ok' => false, 'message' => 'Cannot check, must call or fold'];
                }
                $player->actedThisStreet = true;
                return ['ok' => true, 'chipsUsed' => 0];

            // ---------------- CALL ----------------
            case ActionType::CALL:
                if ($callAmount <= 0) {
                    $player->actedThisStreet = true;
                    return [
                        'ok'       => true,
                        'chipsUsed'=> 0,
                        'newBet'   => $player->bet
                    ];
                }

                // Cap call to effective stack
                $chips = min($callAmount, $availableChips);

                // Apply chips
                $player->stack         -= $chips;
                $player->bet           += $chips;
                $player->totalInvested += $chips;
                $player->contribution  += $chips;

                if ($player->stack === 0) {
                    $player->allIn = true;
                }

                $player->actedThisStreet = true;

                return [
                    'ok'       => true,
                    'chipsUsed'=> $chips,
                    'newBet'   => $player->bet,
                    'isAllIn'  => $player->allIn
                ];

            // ---------------- BET ----------------
            case ActionType::BET:
                if ($currentBet > 0) {
                    return ['ok' => false, 'message' => 'Cannot bet, bet already exists'];
                }
                if ($amount <= 0 || $amount > $availableChips) {
                    return ['ok' => false, 'message' => 'Bet exceeds effective stack'];
                }

                $chips = $amount;

                // Apply chips
                $player->stack         -= $chips;
                $player->bet           += $chips;
                $player->totalInvested += $chips;
                $player->contribution  += $chips;

                if ($player->stack === 0) {
                    $player->allIn = true;
                }

                $player->actedThisStreet = true;

                return [
                    'ok'       => true,
                    'chipsUsed'=> $chips,
                    'newBet'   => $player->bet,
                    'isAllIn'  => $player->allIn
                ];

            // ---------------- RAISE ----------------
            case ActionType::RAISE:
                if ($currentBet === 0) {
                    return ['ok' => false, 'message' => 'Cannot raise, no live bet'];
                }

                $minRaiseTo = $currentBet + $lastRaiseAmount;
                $raiseTo    = $currentBet + $amount;

                if ($raiseTo < $minRaiseTo) {
                    return ['ok' => false, 'message' => "Minimum raise is to {$minRaiseTo}"];
                }

                $chipsNeeded = $raiseTo - $player->bet;

                if ($chipsNeeded > $availableChips) {
                    return ['ok' => false, 'message' => 'Raise exceeds effective stack'];
                }

                // Apply chips
                $player->stack         -= $chipsNeeded;
                $player->bet           += $chipsNeeded;
                $player->totalInvested += $chipsNeeded;
                $player->contribution  += $chipsNeeded;

                if ($player->stack === 0) {
                    $player->allIn = true;
                }

                $player->actedThisStreet = true;

                return [
                    'ok'       => true,
                    'chipsUsed'=> $chipsNeeded,
                    'newBet'   => $player->bet,
                    'isAllIn'  => $player->allIn
                ];

            // ---------------- ALL-IN ----------------
            case ActionType::ALLIN:
                if ($availableChips <= 0) {
                    return ['ok' => false, 'message' => 'Cannot all-in with 0 chips'];
                }

                // All-in is now only for effective stack
                $chips = $availableChips;

                // Apply chips
                $player->stack         -= $chips;
                $player->bet           += $chips;
                $player->totalInvested += $chips;
                $player->contribution  += $chips;

                if ($player->stack === 0) {
                    $player->allIn = true;
                }

                $player->actedThisStreet = true;

                return [
                    'ok'       => true,
                    'chipsUsed'=> $chips,
                    'newBet'   => $player->bet,
                    'isAllIn'  => true
                ];
        }

        return ['ok' => false, 'message' => 'Unknown action'];
    }

    /**
     * ========================================================
     * LEGAL ACTION GENERATION
     * ========================================================
     * Uses effective stack instead of real stack to prevent
     * offering raises/bets that cannot be matched.
     * ========================================================
     */
    public static function getLegalActions(
        PlayerState $player,
        int $currentBet,
        int $lastRaiseAmount,
        array $allPlayers
    ): array {

        if ($player->folded || $player->allIn) {
            return [];
        }

        // Effective stack override
        $effectiveChips = self::getEffectiveStack($player, $allPlayers);
        $callAmount = $currentBet - $player->bet;
        $availableChips = $effectiveChips;

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

        // --- all-in (always allowed if >0 chips) ---
        if ($availableChips > 0) {
            $actions[] = ActionType::ALLIN;
        }

        return $actions;
    }

    /**
     * PhaseEngine requirement — unchanged
     */
    public static function isBettingRoundComplete(
        array $activePlayers,
        int $actionSeat,
        int $currentBet,
        int $lastRaiseSeat
    ): bool {

        if (count($activePlayers) <= 1) {
            return true;
        }

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

        if ($currentBet === 0) {
            foreach ($activePlayers as $p) {
                if (!$p->folded && !$p->allIn && !$p->actedThisStreet) {
                    return false;
                }
            }
            return true;
        }

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

    /**
     * BLIND POSTING — unchanged
     */
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
        $s = min($sbAmount, $sb->stack);
        $sb->stack -= $s;
        $sb->bet += $s;
        $sb->totalInvested += $s;
        $sb->contribution += $s;
        $pot += $s;
        if ($sb->stack === 0) $sb->allIn = true;

        // Big blind
        $b = min($bbAmount, $bb->stack);
        $bb->stack -= $b;
        $bb->bet += $b;
        $bb->totalInvested += $b;
        $bb->contribution += $b;
        $pot += $b;
        if ($bb->stack === 0) $bb->allIn = true;

        return [
            'pot'        => $pot,
            'currentBet' => $b
        ];
    }
}
