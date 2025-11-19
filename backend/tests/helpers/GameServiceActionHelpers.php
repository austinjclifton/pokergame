<?php
// backend/tests/helpers/GameServiceActionHelpers.php
// -----------------------------------------------------------------------------
// Helper trait for performing game actions and simulating game flow in tests.
// Provides utilities for executing sequences of actions, advancing phases,
// and comparing game states.
// -----------------------------------------------------------------------------

declare(strict_types=1);

require_once __DIR__ . '/../../app/services/game/GameService.php';
require_once __DIR__ . '/../../app/services/game/rules/GameTypes.php';
require_once __DIR__ . '/GameServiceStateHelpers.php';

trait GameServiceActionHelpers
{
    use GameServiceStateHelpers;

    /**
     * Simulate a complete betting round by executing a sequence of actions
     * 
     * @param GameService $game Game service instance
     * @param array<int, array{action: ActionType, amount?: int}> $actions Array of seat => action config
     *   Each action config should have 'action' (ActionType) and optionally 'amount' (int)
     * @return void
     * @throws \Exception If action execution fails
     */
    protected function simulateBettingRound(GameService $game, array $actions): void
    {
        foreach ($actions as $seat => $actionConfig) {
            $action = $actionConfig['action'];
            $amount = $actionConfig['amount'] ?? 0;
            
            $result = $game->playerAction($seat, $action, $amount);
            
            if (!$result['ok']) {
                throw new \Exception("Action failed for seat {$seat}: " . ($result['message'] ?? 'Unknown error'));
            }
        }
    }

    /**
     * Execute a single player action
     * 
     * @param GameService $game Game service instance
     * @param int $seat Player seat number
     * @param ActionType $action Action type
     * @param int $amount Action amount (for bet/raise)
     * @return array{ok: bool, message?: string, state?: array}
     */
    protected function executeAction(GameService $game, int $seat, ActionType $action, int $amount = 0): array
    {
        return $game->playerAction($seat, $action, $amount);
    }

    /**
     * Advance game to target street by completing betting rounds
     * 
     * @param GameService $game Game service instance
     * @param Phase $targetPhase Target phase (FLOP, TURN, or RIVER)
     * @return void
     * @throws \Exception If phase advancement fails
     */
    protected function advanceToStreet(GameService $game, Phase $targetPhase): void
    {
        $currentPhase = $this->getPhase($game);
        
        // Map phases to their order (using enum value as key)
        $phaseOrder = [
            Phase::PREFLOP->value => 0,
            Phase::FLOP->value => 1,
            Phase::TURN->value => 2,
            Phase::RIVER->value => 3,
            Phase::SHOWDOWN->value => 4,
        ];
        
        $currentOrder = $phaseOrder[$currentPhase->value] ?? -1;
        $targetOrder = $phaseOrder[$targetPhase->value] ?? -1;
        
        if ($targetOrder <= $currentOrder) {
            throw new \Exception("Cannot advance to {$targetPhase->value}, already at or past that phase");
        }
        
        // Complete betting rounds and advance phase by phase
        while ($currentOrder < $targetOrder) {
            // Complete current betting round
            $this->completeBettingRound($game);
            
            // Advance to next phase using advancePhaseIfNeeded()
            // This will automatically advance when betting round is complete
            $game->advancePhaseIfNeeded();
            
            $newPhase = $this->getPhase($game);
            $newOrder = $phaseOrder[$newPhase->value] ?? -1;
            
            // If phase didn't advance, something went wrong
            if ($newOrder <= $currentOrder) {
                throw new \Exception("Phase did not advance from {$currentPhase->value}");
            }
            
            $currentPhase = $newPhase;
            $currentOrder = $newOrder;
        }
    }

    /**
     * Deal to showdown by completing all betting rounds
     * 
     * @param GameService $game Game service instance
     * @return void
     * @throws \Exception If advancement fails
     */
    protected function dealToShowdown(GameService $game): void
    {
        $this->advanceToStreet($game, Phase::RIVER);
        
        // Complete river betting round
        $this->completeBettingRound($game);
        
        // Advance to showdown
        $game->advancePhaseIfNeeded();
    }

    /**
     * Complete current hand to end
     * 
     * @param GameService $game Game service instance
     * @return void
     * @throws \Exception If hand completion fails
     */
    protected function completeHand(GameService $game): void
    {
        // Advance to showdown if not already there
        $currentPhase = $this->getPhase($game);
        
        if ($currentPhase !== Phase::SHOWDOWN) {
            $this->dealToShowdown($game);
        }
        
        // Evaluate winners if not already done
        $game->evaluateWinners();
    }

    /**
     * Start next hand (calls internal method)
     * 
     * @param GameService $game Game service instance
     * @return void
     */
    protected function startNextHand(GameService $game): void
    {
        $reflection = new \ReflectionClass($game);
        $method = $reflection->getMethod('startNextHand');
        $method->setAccessible(true);
        $method->invoke($game);
    }

    /**
     * Replay actions from database format
     * 
     * @param GameService $game Game service instance
     * @param array<int, array{seat: int, action: string, amount?: int, ...}> $actions Array of action records
     *   Each action should have 'seat', 'action' (string like 'call', 'raise'), and optionally 'amount'
     * @return void
     * @throws \Exception If action replay fails
     */
    protected function replayActions(GameService $game, array $actions): void
    {
        $actionTypeMap = [
            'check' => ActionType::CHECK,
            'call' => ActionType::CALL,
            'fold' => ActionType::FOLD,
            'bet' => ActionType::BET,
            'raise' => ActionType::RAISE,
            'allin' => ActionType::ALLIN,
        ];
        
        foreach ($actions as $actionRecord) {
            $seat = (int)$actionRecord['seat'];
            $actionString = strtolower($actionRecord['action'] ?? '');
            $amount = (int)($actionRecord['amount'] ?? 0);
            
            if (!isset($actionTypeMap[$actionString])) {
                throw new \Exception("Unknown action type: {$actionString}");
            }
            
            $action = $actionTypeMap[$actionString];
            $result = $game->playerAction($seat, $action, $amount);
            
            if (!$result['ok']) {
                throw new \Exception("Replay failed for action: " . ($result['message'] ?? 'Unknown error'));
            }
        }
    }

    /**
     * Compare two game states and return array of differences
     * 
     * @param GameService $expected Expected game state
     * @param GameService $actual Actual game state
     * @return array<string, mixed> Array of differences, empty if states match
     */
    protected function compareGameStates(GameService $expected, GameService $actual): array
    {
        $differences = [];
        
        // Compare phase
        $expectedPhase = $this->getPhase($expected);
        $actualPhase = $this->getPhase($actual);
        if ($expectedPhase !== $actualPhase) {
            $differences['phase'] = [
                'expected' => $expectedPhase->value,
                'actual' => $actualPhase->value,
            ];
        }
        
        // Compare current bet
        $expectedBet = $this->getCurrentBet($expected);
        $actualBet = $this->getCurrentBet($actual);
        if ($expectedBet !== $actualBet) {
            $differences['currentBet'] = [
                'expected' => $expectedBet,
                'actual' => $actualBet,
            ];
        }
        
        // Compare pot
        $expectedPot = $this->getPot($expected);
        $actualPot = $this->getPot($actual);
        if ($expectedPot !== $actualPot) {
            $differences['pot'] = [
                'expected' => $expectedPot,
                'actual' => $actualPot,
            ];
        }
        
        // Compare players
        $expectedPlayers = $this->getPlayers($expected);
        $actualPlayers = $this->getPlayers($actual);
        
        $allSeats = array_unique(array_merge(array_keys($expectedPlayers), array_keys($actualPlayers)));
        foreach ($allSeats as $seat) {
            $expectedPlayer = $expectedPlayers[$seat] ?? null;
            $actualPlayer = $actualPlayers[$seat] ?? null;
            
            if ($expectedPlayer === null || $actualPlayer === null) {
                $differences["players.{$seat}.exists"] = [
                    'expected' => $expectedPlayer !== null,
                    'actual' => $actualPlayer !== null,
                ];
                continue;
            }
            
            // Compare stack
            if ($expectedPlayer->stack !== $actualPlayer->stack) {
                $differences["players.{$seat}.stack"] = [
                    'expected' => $expectedPlayer->stack,
                    'actual' => $actualPlayer->stack,
                ];
            }
            
            // Compare bet
            if ($expectedPlayer->bet !== $actualPlayer->bet) {
                $differences["players.{$seat}.bet"] = [
                    'expected' => $expectedPlayer->bet,
                    'actual' => $actualPlayer->bet,
                ];
            }
            
            // Compare totalInvested
            if ($expectedPlayer->totalInvested !== $actualPlayer->totalInvested) {
                $differences["players.{$seat}.totalInvested"] = [
                    'expected' => $expectedPlayer->totalInvested,
                    'actual' => $actualPlayer->totalInvested,
                ];
            }
            
            // Compare folded
            if ($expectedPlayer->folded !== $actualPlayer->folded) {
                $differences["players.{$seat}.folded"] = [
                    'expected' => $expectedPlayer->folded,
                    'actual' => $actualPlayer->folded,
                ];
            }
            
            // Compare allIn
            if ($expectedPlayer->allIn !== $actualPlayer->allIn) {
                $differences["players.{$seat}.allIn"] = [
                    'expected' => $expectedPlayer->allIn,
                    'actual' => $actualPlayer->allIn,
                ];
            }
            
            // Compare cards
            if ($expectedPlayer->cards !== $actualPlayer->cards) {
                $differences["players.{$seat}.cards"] = [
                    'expected' => $expectedPlayer->cards,
                    'actual' => $actualPlayer->cards,
                ];
            }
        }
        
        // Compare board
        $expectedBoard = $this->getBoard($expected);
        $actualBoard = $this->getBoard($actual);
        if ($expectedBoard !== $actualBoard) {
            $differences['board'] = [
                'expected' => $expectedBoard,
                'actual' => $actualBoard,
            ];
        }
        
        return $differences;
    }

    /**
     * Complete current betting round by having all active players check/call
     * 
     * @param GameService $game Game service instance
     * @return void
     * @throws \Exception If betting round cannot be completed
     */
    protected function completeBettingRound(GameService $game): void
    {
        $maxIterations = 100; // Safety limit
        $iterations = 0;
        
        while ($iterations < $maxIterations) {
            $activePlayers = $this->getActivePlayers($game);
            $actionSeat = $this->getActionSeat($game);
            $currentBet = $this->getCurrentBet($game);
            $lastRaiseSeat = $this->getLastRaiseSeat($game);
            
            $isComplete = \BettingEngine::isBettingRoundComplete(
                $activePlayers,
                $actionSeat,
                $currentBet,
                $lastRaiseSeat
            );
            
            if ($isComplete) {
                return;
            }
            
            // Get current action seat player
            $players = $this->getPlayers($game);
            if (!isset($players[$actionSeat])) {
                throw new \Exception("Action seat {$actionSeat} has no player");
            }
            
            $player = $players[$actionSeat];
            
            // Determine action: check if no bet needed, call if bet needed
            $callAmount = $currentBet - $player->bet;
            $action = ($callAmount === 0) ? ActionType::CHECK : ActionType::CALL;
            
            $result = $game->playerAction($actionSeat, $action, 0);
            
            if (!$result['ok']) {
                throw new \Exception("Failed to complete betting round: " . ($result['message'] ?? 'Unknown error'));
            }
            
            $iterations++;
        }
        
        throw new \Exception("Betting round did not complete after {$maxIterations} iterations");
    }
}

