<?php
declare(strict_types=1);

require_once __DIR__ . '/GameState.php';
require_once __DIR__ . '/engine/BettingEngine.php';
require_once __DIR__ . '/rules/GameTypes.php';

final class ActionProcessor
{
    /**
     * ========================================================
     * ACTION PROCESSOR
     * ========================================================
     * Executes a player's action and mutates GameState.
     *
     * IMPORTANT:
     *  - NEVER changes player->stack
     *  - NEVER changes player->contribution
     *  - NEVER changes player->totalInvested
     *
     * BettingEngine is the ONLY class allowed to mutate:
     *    • stack
     *    • bet
     *    • allIn
     *    • totalInvested
     *    • contribution
     *
     * This class ONLY:
     *    • updates pot (adding chipsUsed)
     *    • updates currentBet / lastRaiseAmount / lastRaiseSeat
     *    • rotates action seat
     *    • detects fold → hand ended
     * ========================================================
     */
    public static function apply(
        GameState $state,
        int $seat,
        ActionType $action,
        int $amount = 0
    ): array {

        // ================================
        // BASIC VALIDATION
        // ================================
        if (!isset($state->players[$seat])) {
            return ['ok' => false, 'message' => 'Invalid seat'];
        }

        $player = $state->players[$seat];

        if ($player->folded) {
            return ['ok' => false, 'message' => 'Player already folded'];
        }

        if ($player->allIn) {
            return ['ok' => false, 'message' => 'Player is all-in'];
        }

        if ($seat !== $state->actionSeat) {
            return ['ok' => false, 'message' => 'Not your turn'];
        }

        // ================================
        // LOAD LEGAL ACTIONS
        // ================================
        $legal = BettingEngine::getLegalActions(
            $player,
            $state->currentBet,
            $state->lastRaiseAmount,
            $state->players
        );

        if (!in_array($action, $legal, true)) {
            return ['ok' => false, 'message' => 'Illegal action'];
        }

        // ================================
        // EXECUTE ACTION (stack/bet mutations)
        // ================================
        $oldPot = $state->pot;
        $oldContrib = $player->contribution;

        // ========================================================
        // NEW: pass allPlayers to allow effective-stack enforcement
        // ========================================================
        $result = BettingEngine::executeAction(
            $player,
            $action,
            $amount,
            $state->currentBet,
            $state->bigBlindAmount,
            $state->lastRaiseAmount,
            $state->players  // <—— REQUIRED FOR EFFECTIVE STACK LOGIC
        );

        if (!($result['ok'] ?? false)) {
            return $result;
        }

        // ================================
        // APPLY chipsUsed TO POT
        // ================================
        if (isset($result['chipsUsed'])) {
            $chips = (int)$result['chipsUsed'];
            $state->pot += $chips;
        }

        // ================================
        // UPDATE CURRENT BET & RAISE METADATA
        // ================================
        if (isset($result['newBet'])) {
            $newBet = $result['newBet'];

            if ($newBet > $state->currentBet) {

                // Was this a true BET or RAISE?
                if ($action === ActionType::BET || $action === ActionType::RAISE) {
                    $state->lastRaiseAmount = $newBet - $state->currentBet;
                    $state->lastRaiseSeat   = $seat;
                }

                $state->currentBet = $newBet;
            }
        }

        // ================================
        // FOLD → HAND MAY END IMMEDIATELY
        // ================================
        if ($action === ActionType::FOLD) {

            $active = array_filter(
                $state->players,
                static fn(PlayerState $p) => !$p->folded
            );

            if (count($active) === 1) {
                return [
                    'ok'        => true,
                    'handEnded' => true,
                    'winner'    => null, // Winner determined in HandEnder
                ];
            }
        }

        // ================================
        // ROTATE ACTION SEAT
        // ================================
        $state->actionSeat = self::nextActive($state, $seat);

        return [
            'ok'        => true,
            'handEnded' => false,
            'winner'    => null,
        ];
    }

    /**
     * ========================================================
     * FIND NEXT ACTION SEAT
     * ========================================================
     * Finds next seat that is:
     *    • not folded
     *    • not all-in
     * ========================================================
     */
    private static function nextActive(GameState $state, int $start): int
    {
        $seats = array_keys($state->players);
        sort($seats);

        $startIndex = array_search($start, $seats, true);
        if ($startIndex === false) {
            return -1;
        }

        $n = count($seats);

        for ($i = 1; $i <= $n; $i++) {
            $s = $seats[($startIndex + $i) % $n];
            $p = $state->players[$s];

            if (!$p->folded && !$p->allIn) {
                return $s;
            }
        }

        return -1;
    }
}
