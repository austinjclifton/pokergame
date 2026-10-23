<?php
declare(strict_types=1);

require_once __DIR__ . '/GameState.php';
require_once __DIR__ . '/HandStarter.php';
require_once __DIR__ . '/ActionProcessor.php';
require_once __DIR__ . '/engine/BettingEngine.php';
require_once __DIR__ . '/engine/PhaseEngine.php';
require_once __DIR__ . '/GamePersistence.php';
require_once __DIR__ . '/cards/HandEvaluator.php';
require_once __DIR__ . '/rules/WinnerCalculator.php';

final class GameService
{
    private GameState $state;
    private GamePersistence $persistence;
    private int $smallBlind;
    private int $bigBlind;
    private ?int $gameId = null;
    private int $version = 0;

    private bool $handBootstrapped = false;

    public function __construct(
        GamePersistence $persistence,
        int $smallBlind = 10,
        int $bigBlind   = 20
    ) {
        $this->persistence = $persistence;
        $this->smallBlind  = $smallBlind;
        $this->bigBlind    = $bigBlind;
        $this->state       = new GameState();
    }

    public function setGameId(?int $id): void { $this->gameId = $id; }
    public function getGameId(): ?int { return $this->gameId; }
    public function getVersion(): int { return $this->version; }

    public function restoreSnapshot(array $state, int $version): void
    {
        $this->state->restoreFromArray($state);
        $this->version = $version;
        $this->handBootstrapped = (bool)($state['_handBootstrapped'] ?? true);
    }

    public function getSnapshot(): array
    {
        return $this->state->toArray();
    }

    public function isHandBootstrapped(): bool
    {
        return $this->handBootstrapped;
    }

    public function loadPlayers(array $players): void
    {
        $this->state->initializePlayers($players);
    }

    // =========================================================================
    // START A NEW HAND
    // =========================================================================
    public function startHand(?int $seed = null): array
    {
        if (count($this->state->players) < 2) {
            return [
                'ok' => false,
                'message' => 'At least 2 players are required to start a hand',
            ];
        }

        if ($this->handBootstrapped) {
            return $this->buildStateResult($this->state->toArray());
        }

        // Before starting → detect match end FIRST
        $matchEnd = $this->detectMatchEnd();
        if ($matchEnd !== null) {
            return $matchEnd;
        }

        $stateBefore = $this->state->toArray();

        try {
            // Normal dealing
            HandStarter::startHand(
                $this->state,
                $this->smallBlind,
                $this->bigBlind,
                $seed
            );

            $openingSummary = null;
            $phaseInfo = PhaseEngine::advance($this->state);
            if ($phaseInfo !== null && ($phaseInfo['handEnded'] ?? false)) {
                $openingSummary = $this->runShowdownSettlement();
            }

            // Bump the version and persist the new hand before it is published.
            if ($this->gameId !== null) {
                $stateArr = $this->state->toArray();
                $stateArr['_handBootstrapped'] = $openingSummary === null;
                $this->version = $this->persistence->persistTransition(
                    $this->gameId,
                    $this->version,
                    $stateArr,
                );
            }
        } catch (\Throwable $e) {
            $this->state->restoreFromArray($stateBefore);
            throw $e;
        }

        $this->handBootstrapped = true;

        if ($openingSummary !== null) {
            return $this->buildResolvedHandResult($openingSummary);
        }

        return $this->buildStateResult($this->state->toArray());
    }

    // =========================================================================
    // APPLY ACTION
    // =========================================================================
    public function applyAction(int $seat, string $action, int $amount = 0): array
    {
        $normalized = strtolower($action);
        $stateBefore = $this->state->toArray();
        $handBootstrappedBefore = $this->handBootstrapped;

        // ----------------------------------------------------------
        // SPECIAL CASE: FORFEIT (match-level, not a betting action)
        // ----------------------------------------------------------
        if ($normalized === 'forfeit') {
            $result = $this->handleForfeit($seat);
            return $this->persistAcceptedAction($result, $stateBefore, $handBootstrappedBefore);
        }

        try {
            $a = ActionType::from($normalized);
        } catch (\ValueError) {
            return ['ok' => false, 'message' => 'Invalid action'];
        }

        // ------------------------- 1. Apply user action ------------------------
        $result = ActionProcessor::apply($this->state, $seat, $a, $amount);
        if (!($result['ok'] ?? false)) {
            return $result;
        }

        // ---------- 2. Hand ended immediately from fold ----------
        if ($result['handEnded'] ?? false) {
            $summary = $this->buildFoldSummary();
            $result = $this->buildResolvedHandResult($summary);
            return $this->persistAcceptedAction($result, $stateBefore, $handBootstrappedBefore);
        }

        // ------------------------- 3. Phase advancement ------------------------
        $phaseInfo = PhaseEngine::advance($this->state);

        // ---------- 4. Hand ended because phase reached showdown ----------
        if ($phaseInfo !== null && ($phaseInfo['handEnded'] ?? false)) {
            $summary = $this->runShowdownSettlement();
            $result = $this->buildResolvedHandResult($summary);
            return $this->persistAcceptedAction($result, $stateBefore, $handBootstrappedBefore);
        }

        // ---------------------- 5. Normal non-ending action --------------------
        $result = $this->buildStateResult($this->state->toArray());
        return $this->persistAcceptedAction($result, $stateBefore, $handBootstrappedBefore);
    }

    private function persistAcceptedAction(
        array $result,
        array $stateBefore,
        bool $handBootstrappedBefore
    ): array {
        if (!($result['ok'] ?? false) || $this->gameId === null) {
            return $result;
        }

        try {
            $persistedState = $result['state'];
            $persistedState['_handBootstrapped'] = $this->handBootstrapped;
            $this->version = $this->persistence->persistTransition(
                $this->gameId,
                $this->version,
                $persistedState,
            );
            return $result;
        } catch (\Throwable $e) {
            $this->state->restoreFromArray($stateBefore);
            $this->handBootstrapped = $handBootstrappedBefore;
            throw $e;
        }
    }

    public function restoreLatestSnapshot(): bool
    {
        $snapshot = $this->persistence->loadLatest($this->gameId ?? 0);
        if ($snapshot === null || empty($snapshot['state'])) {
            return false;
        }

        $state = $snapshot['state'];
        if (isset($state['state']) && is_array($state['state']) && !isset($state['phase'])) {
            $state = $state['state'];
        }

        $this->restoreSnapshot($state, (int)$snapshot['version']);
        return true;
    }

    public function advancePhaseIfNeeded(): ?array
    {
        return PhaseEngine::advance($this->state);
    }

    public function evaluateWinners(): array
    {
        if ($this->state->phase !== Phase::SHOWDOWN) {
            return [
                'ok' => false,
                'message' => 'Hand must be at showdown before winner evaluation',
            ];
        }

        $summary = $this->runShowdownSettlement();
        $this->handBootstrapped = false;

        $winners = array_map(
            static fn(array $winner): array => [
                'seat' => (int)$winner['seat'],
                'amount' => (int)$winner['amount'],
                'reason' => (string)$winner['handDescription'],
            ],
            $summary['winners']
        );

        return [
            'ok' => true,
            'state' => $this->state->toArray(),
            'summary' => $summary,
            'winners' => $winners,
            'handEnded' => true,
        ];
    }

    // =========================================================================
    // LEGAL ACTION HELPERS
    // =========================================================================
    public function getSeatCards(int $seat): array
    {
        return $this->state->players[$seat]->cards ?? [];
    }

    public function getLegalActionsForSeat(int $seat): array
    {
        if (!isset($this->state->players[$seat])) return [];
        if ($this->state->actionSeat !== $seat) return [];

        $p = $this->state->players[$seat];

        return BettingEngine::getLegalActions(
            $p,
            $this->state->currentBet,
            $this->state->lastRaiseAmount,
            $this->state->players
        );
    }

    // =========================================================================
    // FORFEIT HANDLER (MATCH-LEVEL)
    // =========================================================================
    private function handleForfeit(int $seat): array
    {
        if (!isset($this->state->players[$seat])) {
            return ['ok' => false, 'message' => 'Invalid seat'];
        }

        // Heads-up: find opponent seat
        $forfeiterSeat = $seat;
        $forfeiter     = $this->state->players[$forfeiterSeat];

        $opponentSeat = null;
        $opponent     = null;
        foreach ($this->state->players as $s => $p) {
            if ($s !== $forfeiterSeat) {
                $opponentSeat = $s;
                $opponent     = $p;
                break;
            }
        }

        // If somehow no opponent, just treat as trivial match end
        if ($opponent === null) {
            return $this->buildMatchEndResponse($forfeiterSeat, $forfeiter, null, null, 'forfeit');
        }

        // Award all remaining chips + pot to opponent
        $totalAward = $this->state->pot + $forfeiter->stack;

        if ($totalAward > 0) {
            $opponent->stack += $totalAward;
        }

        // Forfeiter is now effectively "busted"
        $forfeiter->stack = 0;

        // Clear per-street betting state while keeping hand totals intact.
        $this->state->resetBettingRound();

        // No hand is considered active anymore
        $this->handBootstrapped = false;

        // Reuse normal match-end detection to build winner/loser structure
        $matchEnd = $this->detectMatchEnd();
        if ($matchEnd !== null) {
            $matchEnd['reason'] = 'forfeit';
            return $matchEnd;
        }

        // Fallback (should not be hit if heads-up and logic is consistent)
        return $this->buildMatchEndResponse($opponentSeat, $opponent, $forfeiterSeat, $forfeiter, 'forfeit');
    }

    // =========================================================================
    // MATCH END DETECTION (HEADS-UP)
    // =========================================================================
    private function detectMatchEnd(): ?array
    {
        if (empty($this->state->players)) return null;

        $alive = [];
        foreach ($this->state->players as $seat => $p) {
            if ($p->stack > 0) {
                $alive[$seat] = $p;
            }
        }

        // Continue if both players still have chips
        if (count($alive) !== 1) {
            return null;
        }

        // Winner is the only non-broke player
        $winnerSeat = array_key_first($alive);
        $winner     = $alive[$winnerSeat];

        // Loser is the other seat
        $loserSeat = null;
        $loser     = null;
        foreach ($this->state->players as $s => $p) {
            if ($s !== $winnerSeat) {
                $loserSeat = $s;
                $loser     = $p;
                break;
            }
        }

        // Validate that we found both winner and loser
        if ($loser === null || $winnerSeat === null) {
            error_log("[GameService] detectMatchEnd: Could not find both winner and loser. WinnerSeat: " . ($winnerSeat ?? 'null') . ", Loser: " . ($loser === null ? 'null' : 'found'));
            return null;
        }

        return $this->buildMatchEndResponse($winnerSeat, $winner, $loserSeat, $loser);
    }

    // =========================================================================
    // FOLD SETTLEMENT
    // =========================================================================
    private function buildFoldSummary(): array
    {
        $active = array_filter(
            $this->state->players,
            fn(PlayerState $p) => !$p->folded
        );

        $winnerSeat = array_keys($active)[0];
        $amount     = $this->state->pot;
        $winner     = $this->state->players[$winnerSeat];

        $winner->stack += $amount;
        $this->state->resetBettingRound();

        $summary = [
            'event'   => 'hand_end',
            'reason'  => 'fold',
            'pot'     => $amount,
            'board'   => $this->state->board,
            'winners' => [[
                'seat'            => $winnerSeat,
                'amount'          => $amount,
                'handDescription' => 'Wins by fold',
                'bestHand'        => [],
            ]],
            'players' => $this->buildSummaryPlayers(),
        ];

        $this->state->lastHandResult = $summary;

        return $summary;
    }

    // =========================================================================
    // SHOWDOWN SETTLEMENT
    // =========================================================================
    private function runShowdownSettlement(): array
    {
        $input = [];
        foreach ($this->state->players as $seat => $p) {
            $input[] = [
                'seat'         => $seat,
                'user_id'      => $p->user_id,
                'cards'        => $p->cards,
                'folded'       => $p->folded,
                'contribution' => $p->contribution,
            ];
        }

        $eval = new HandEvaluator();
        $calc = new WinnerCalculator($eval);
        $wc   = $calc->calculate($input, $this->state->board);

        foreach ($wc['handRanks'] as $info) {
            $seat = (int)$info['seat'];
            if (!isset($this->state->players[$seat])) {
                continue;
            }

            $this->state->players[$seat]->handRank = (int)$info['rank'];
            $this->state->players[$seat]->handDescription = (string)$info['name'];
        }

        // Apply payouts
        foreach ($this->state->players as $seat => $p) {
            $delta = $wc['payouts'][$seat] ?? 0;
            if ($delta > 0) {
                $p->stack += $delta;
            }
        }

        $this->state->resetBettingRound();

        // Winners list
        $winners = [];
        foreach ($wc['handRanks'] as $info) {
            $seat = $info['seat'];
            $gain = $wc['payouts'][$seat] ?? 0;
            if ($gain > 0) {
                $winners[] = [
                    'seat'            => $seat,
                    'amount'          => $gain,
                    'handDescription' => $info['name'],
                    'bestHand'        => $info['bestCards'],
                ];
            }
        }

        $summary = [
            'event'   => 'hand_end',
            'reason'  => 'showdown',
            'pot'     => $wc['totalPot'],
            'board'   => $this->state->board,
            'winners' => $winners,
            'players' => $this->buildSummaryPlayers(),
        ];

        $this->state->lastHandResult = $summary;

        return $summary;
    }

    private function buildStateResult(array $state, bool $handEnded = false, ?array $summary = null): array
    {
        return [
            'ok'         => true,
            'state'      => $state,
            'handEnded'  => $handEnded,
            'summary'    => $summary,
            'matchEnded' => false,
            'winner'     => null,
            'loser'      => null,
        ];
    }

    private function buildResolvedHandResult(array $summary): array
    {
        $this->handBootstrapped = false;

        $matchEnd = $this->detectMatchEnd();
        if ($matchEnd !== null) {
            return $matchEnd;
        }

        return $this->buildStateResult(
            $this->state->toArray(),
            true,
            $summary
        );
    }

    private function buildMatchEndResponse(
        ?int $winnerSeat,
        ?PlayerState $winner,
        ?int $loserSeat,
        ?PlayerState $loser,
        ?string $reason = null
    ): array {
        $response = [
            'ok'         => true,
            'state'      => $this->state->toArray(),
            'handEnded'  => false,
            'summary'    => null,
            'matchEnded' => true,
            'winner'     => $winnerSeat === null || $winner === null
                ? null
                : [
                    'seat'    => $winnerSeat,
                    'user_id' => $winner->user_id,
                    'stack'   => $winner->stack,
                ],
            'loser'      => $loserSeat === null || $loser === null
                ? null
                : [
                    'seat'    => $loserSeat,
                    'user_id' => $loser->user_id,
                    'stack'   => $loser->stack,
                ],
        ];

        if ($reason !== null) {
            $response['reason'] = $reason;
        }

        return $response;
    }

    /** @return array<int, array{seat:int,user_id:int,cards:array<string>,folded:bool,stack:int,bet:int,contribution:int}> */
    private function buildSummaryPlayers(): array
    {
        $players = [];

        foreach ($this->state->players as $seat => $player) {
            $players[$seat] = [
                'seat' => $seat,
                'user_id' => $player->user_id,
                'cards' => $player->cards,
                'folded' => $player->folded,
                'stack' => $player->stack,
                'bet' => $player->bet,
                'contribution' => $player->contribution,
            ];
        }

        return $players;
    }
}
