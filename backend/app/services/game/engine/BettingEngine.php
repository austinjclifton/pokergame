<?php
declare(strict_types=1);

require_once __DIR__ . '/../rules/GameTypes.php';
require_once __DIR__ . '/../PlayerState.php';

final class BettingEngine
{
    /**
     * Execute a player betting action.
     * Mutates PlayerState ONLY.
     *
     * GameState-level updates (pot, currentBet, raises, contributions)
     * happen in ActionProcessor.
     */
    public static function executeAction(
        PlayerState $player,
        ActionType $action,
        int $amount,
        int $currentBet,
        int $bigBlindAmount,
        int $lastRaiseAmount
    ): array {

        $callAmount     = $currentBet - $player->bet;
        $availableChips = $player->stack;

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

                $chips = min($callAmount, $availableChips);

                // CHIP TRACE
                $oldStack = $player->stack;
                $oldContrib = $player->contribution;
                error_log("[CHIP TRACE] " . __FILE__ . ":" . __LINE__ . " seat={$player->seat} user={$player->user_id} BEFORE stack={$oldStack} contribution={$oldContrib}");
                error_log("[CHIP TRACE] BETTING: action=CALL amount={$chips} oldContrib={$oldContrib} newContrib=" . ($oldContrib + $chips));

                $player->stack         -= $chips;
                $player->bet           += $chips;
                $player->totalInvested += $chips;
                $player->contribution  += $chips;

                // CHIP TRACE
                error_log("[CHIP TRACE] " . __FILE__ . ":" . __LINE__ . " seat={$player->seat} user={$player->user_id} AFTER stack={$player->stack} contribution={$player->contribution}");

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
                    return ['ok' => false, 'message' => 'Invalid bet amount'];
                }

                $chips = $amount;

                // CHIP TRACE
                $oldStack = $player->stack;
                $oldContrib = $player->contribution;
                error_log("[CHIP TRACE] " . __FILE__ . ":" . __LINE__ . " seat={$player->seat} user={$player->user_id} BEFORE stack={$oldStack} contribution={$oldContrib}");
                error_log("[CHIP TRACE] BETTING: action=BET amount={$chips} oldContrib={$oldContrib} newContrib=" . ($oldContrib + $chips));

                $player->stack         -= $chips;
                $player->bet           += $chips;
                $player->totalInvested += $chips;
                $player->contribution  += $chips;

                // CHIP TRACE
                error_log("[CHIP TRACE] " . __FILE__ . ":" . __LINE__ . " seat={$player->seat} user={$player->user_id} AFTER stack={$player->stack} contribution={$player->contribution}");

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
                    return ['ok' => false, 'message' => 'Not enough chips'];
                }

                // CHIP TRACE
                $oldStack = $player->stack;
                $oldContrib = $player->contribution;
                error_log("[CHIP TRACE] " . __FILE__ . ":" . __LINE__ . " seat={$player->seat} user={$player->user_id} BEFORE stack={$oldStack} contribution={$oldContrib}");
                error_log("[CHIP TRACE] BETTING: action=RAISE amount={$chipsNeeded} oldContrib={$oldContrib} newContrib=" . ($oldContrib + $chipsNeeded));

                $player->stack         -= $chipsNeeded;
                $player->bet           += $chipsNeeded;
                $player->totalInvested += $chipsNeeded;
                $player->contribution  += $chipsNeeded;

                // CHIP TRACE
                error_log("[CHIP TRACE] " . __FILE__ . ":" . __LINE__ . " seat={$player->seat} user={$player->user_id} AFTER stack={$player->stack} contribution={$player->contribution}");

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

                $chips = $availableChips;

                // CHIP TRACE
                $oldStack = $player->stack;
                $oldContrib = $player->contribution;
                error_log("[CHIP TRACE] " . __FILE__ . ":" . __LINE__ . " seat={$player->seat} user={$player->user_id} BEFORE stack={$oldStack} contribution={$oldContrib}");
                error_log("[CHIP TRACE] BETTING: action=ALLIN amount={$chips} oldContrib={$oldContrib} newContrib=" . ($oldContrib + $chips));

                $player->stack          = 0;
                $player->bet           += $chips;
                $player->totalInvested += $chips;
                $player->contribution  += $chips;

                // CHIP TRACE
                error_log("[CHIP TRACE] " . __FILE__ . ":" . __LINE__ . " seat={$player->seat} user={$player->user_id} AFTER stack={$player->stack} contribution={$player->contribution}");
                $player->allIn          = true;
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
     * Return legal actions for the player.
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

        $callAmount     = $currentBet - $player->bet;
        $availableChips = $player->stack;

        $hasLiveOpponent = false;
        foreach ($allPlayers as $opp) {
            if ($opp !== $player && !$opp->folded && !$opp->allIn) {
                $hasLiveOpponent = true;
                break;
            }
        }

        $actions = [];

        if ($callAmount <= 0) {
            $actions[] = ActionType::CHECK;
        } else {
            $actions[] = ActionType::CALL;
            $actions[] = ActionType::FOLD;
        }

        if ($currentBet === 0 && $availableChips > 0) {
            $actions[] = ActionType::BET;
        }

        if (
            $currentBet > 0 &&
            $availableChips > $callAmount &&
            $hasLiveOpponent &&
            ($player->bet + $availableChips >= $currentBet + $lastRaiseAmount)
        ) {
            $actions[] = ActionType::RAISE;
        }

        if ($availableChips > 0) {
            $actions[] = ActionType::ALLIN;
        }

        return $actions;
    }

    /**
     * Required by PhaseEngine.
     * Determines whether betting round is complete.
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
     * Posting blinds at the start of hand.
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
        // CHIP TRACE
        error_log("[CHIP TRACE] BLIND: SB deduction " . __FILE__ . ":" . __LINE__ . " seat={$smallBlindSeat} user={$sb->user_id} amount={$s} BEFORE stack={$sb->stack}");
        $sb->stack          -= $s;
        $sb->bet            += $s;
        $sb->totalInvested  += $s;
        $sb->contribution   += $s;
        $pot                += $s;
        // CHIP TRACE
        error_log("[CHIP TRACE] BLIND: SB deduction " . __FILE__ . ":" . __LINE__ . " seat={$smallBlindSeat} user={$sb->user_id} AFTER stack={$sb->stack} contribution={$sb->contribution}");
        if ($sb->stack === 0) $sb->allIn = true;

        // Big blind
        $b = min($bbAmount, $bb->stack);
        // CHIP TRACE
        error_log("[CHIP TRACE] BLIND: BB deduction " . __FILE__ . ":" . __LINE__ . " seat={$bigBlindSeat} user={$bb->user_id} amount={$b} BEFORE stack={$bb->stack}");
        $bb->stack          -= $b;
        $bb->bet            += $b;
        $bb->totalInvested  += $b;
        $bb->contribution   += $b;
        $pot                += $b;
        // CHIP TRACE
        error_log("[CHIP TRACE] BLIND: BB deduction " . __FILE__ . ":" . __LINE__ . " seat={$bigBlindSeat} user={$bb->user_id} AFTER stack={$bb->stack} contribution={$bb->contribution}");
        if ($bb->stack === 0) $bb->allIn = true;

        return [
            'pot'        => $pot,
            'currentBet' => $b
        ];
    }
}
