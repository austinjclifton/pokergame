<?php
declare(strict_types=1);

require_once __DIR__ . '/GameState.php';
require_once __DIR__ . '/engine/BettingEngine.php';
require_once __DIR__ . '/engine/TurnOrder.php';
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
                    'winner'    => null,
                ];
            }
        }

        // ================================
        // ROTATE ACTION SEAT
        // ================================
        $state->actionSeat = TurnOrder::nextActiveSeat($state->players, $seat);

        return [
            'ok'        => true,
            'handEnded' => false,
            'winner'    => null,
        ];
    }
}
