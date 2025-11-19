<?php
declare(strict_types=1);

require_once __DIR__ . '/GameState.php';
require_once __DIR__ . '/engine/BettingEngine.php';
require_once __DIR__ . '/rules/GameTypes.php';

final class ActionProcessor
{
    /**
     * Executes a player's action and mutates GameState.
     *
     * IMPORTANT:
     *  - NEVER changes player->stack
     *  - NEVER changes player->contribution
     *  - NEVER changes player->totalInvested
     *  - ONLY updates:
     *        • state->pot (from chipsUsed)
     *        • state->currentBet / lastRaiseAmount / lastRaiseSeat
     *        • actionSeat rotation
     *        • fold → immediate hand end
     *
     * BettingEngine *is the only class* that mutates:
     *    bet, stack, allIn, totalInvested, contribution
     */
    public static function apply(
        GameState $state,
        int $seat,
        ActionType $action,
        int $amount = 0
    ): array {

        // ---------------------------------------------------------
        // Basic validation
        // ---------------------------------------------------------
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

        // ---------------------------------------------------------
        // Get legal actions
        // ---------------------------------------------------------
        $legal = BettingEngine::getLegalActions(
            $player,
            $state->currentBet,
            $state->lastRaiseAmount,
            $state->players
        );

        if (!in_array($action, $legal, true)) {
            return ['ok' => false, 'message' => 'Illegal action'];
        }

        // ---------------------------------------------------------
        // Execute via BettingEngine
        // (mutates: bet, stack, contribution, totalInvested, allIn)
        // ---------------------------------------------------------
        $result = BettingEngine::executeAction(
            $player,
            $action,
            $amount,
            $state->currentBet,
            $state->bigBlindAmount,
            $state->lastRaiseAmount
        );

        if (!($result['ok'] ?? false)) {
            return $result;
        }

        // ---------------------------------------------------------
        // Add chipsUsed to pot — THIS IS THE ONLY CHIP CHANGE HERE
        // ---------------------------------------------------------
        if (isset($result['chipsUsed'])) {
            $chips = (int)$result['chipsUsed'];
            $state->pot += $chips;
        }

        // ---------------------------------------------------------
        // Update currentBet + raise metadata
        // ---------------------------------------------------------
        if (isset($result['newBet'])) {
            $newBet = $result['newBet'];

            // Raise or bet increased the global current bet
            if ($newBet > $state->currentBet) {

                if ($action === ActionType::BET || $action === ActionType::RAISE) {
                    $state->lastRaiseAmount = $newBet - $state->currentBet;
                    $state->lastRaiseSeat   = $seat;
                }

                $state->currentBet = $newBet;
            }
        }

        // ---------------------------------------------------------
        // FOLD → instant win if only 1 survives
        // ---------------------------------------------------------
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

        // ---------------------------------------------------------
        // Rotate action seat
        // ---------------------------------------------------------
        $state->actionSeat = self::nextActive($state, $seat);

        return [
            'ok'        => true,
            'handEnded' => false,
            'winner'    => null,
        ];
    }

    /**
     * Get next non-folded, non-all-in seat clockwise.
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
