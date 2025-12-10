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
    public function setVersion(int $v): void { $this->version = $v; }

    public function getSnapshot(): array
    {
        return $this->state->toArray();
    }

    public function isHandBootstrapped(): bool
    {
        return $this->handBootstrapped;
    }

    public function markHandBootstrapped(): void
    {
        $this->handBootstrapped = true;
    }

    public function clearBootstrapFlag(): void
    {
        $this->handBootstrapped = false;
    }

    public function loadPlayers(array $players): void
    {
        $this->state->initializePlayers($players);
    }

    // =========================================================================
    // START A NEW HAND — includes MATCH END PATCH
    // =========================================================================
    public function startHand(?int $seed = null): array
    {
        if ($this->handBootstrapped) {
            $state = $this->persistence->snapshot($this->state);
            return [
                'ok'         => true,
                'state'      => $state,
                'handEnded'  => false,
                'summary'    => null,
                'matchEnded' => false,
                'winner'     => null,
                'loser'      => null,
            ];
        }

        // Before starting → detect match end FIRST
        $matchEnd = $this->detectMatchEnd();
        if ($matchEnd !== null) {
            return $matchEnd;
        }

        // Normal dealing
        HandStarter::startHand(
            $this->state,
            $this->smallBlind,
            $this->bigBlind,
            $seed
        );

        // Bump version, persist snapshot, and broadcast hand_start event
        if ($this->gameId !== null) {
            $newVersion    = $this->persistence->bumpVersion($this->gameId);
            $this->version = $newVersion;

            $stateArr = $this->state->toArray();
            $this->persistence->snapshotForced($this->gameId, $newVersion, $stateArr);

            // Broadcast hand_start event (GameSocket class will be loaded by the time this is called)
            if (class_exists('GameSocket') && method_exists('GameSocket', 'broadcastByGameId')) {
                try {
                    GameSocket::broadcastByGameId($this->gameId, [
                        'type'    => 'hand_start',
                        'state'   => $stateArr,
                        'version' => $newVersion
                    ]);
                } catch (\Throwable $e) {
                    error_log("[GameService] Failed to broadcast hand_start: " . $e->getMessage());
                }
            }
        }

        $this->handBootstrapped = true;

        $state = $this->persistence->snapshot($this->state);
        return [
            'ok'         => true,
            'state'      => $state,
            'handEnded'  => false,
            'summary'    => null,
            'matchEnded' => false,
            'winner'     => null,
            'loser'      => null,
        ];
    }

    // =========================================================================
    // APPLY ACTION — includes MATCH END PATCH in all ending paths
    // =========================================================================
    public function applyAction(int $seat, string $action, int $amount = 0): array
    {
        $normalized = strtolower($action);

        // ----------------------------------------------------------
        // SPECIAL CASE: FORFEIT (match-level, not a betting action)
        // ----------------------------------------------------------
        if ($normalized === 'forfeit') {
            return $this->handleForfeit($seat);
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
            $state   = $this->persistence->snapshot($this->state);

            $this->handBootstrapped = false;

            // ⭐ MATCH END CHECK (PATCH)
            $matchEnd = $this->detectMatchEnd();
            if ($matchEnd !== null) {
                return $matchEnd;
            }

            return [
                'ok'         => true,
                'state'      => $state,
                'handEnded'  => true,
                'summary'    => $summary,
                'matchEnded' => false,
                'winner'     => null,
                'loser'      => null,
            ];
        }

        // ------------------------- 3. Phase advancement ------------------------
        $evaluator = new HandEvaluator();
        $phaseInfo = PhaseEngine::advance($this->state, $evaluator);

        // ---------- 4. Hand ended because phase reached showdown ----------
        if ($phaseInfo !== null && ($phaseInfo['handEnded'] ?? false)) {
            $summary = $this->runShowdownSettlement();
            $state   = $this->persistence->snapshot($this->state);

            $this->handBootstrapped = false;

            // ⭐ MATCH END CHECK (PATCH)
            $matchEnd = $this->detectMatchEnd();
            if ($matchEnd !== null) {
                return $matchEnd;
            }

            return [
                'ok'         => true,
                'state'      => $state,
                'handEnded'  => true,
                'summary'    => $summary,
                'matchEnded' => false,
                'winner'     => null,
                'loser'      => null,
            ];
        }

        // ---------------------- 5. Normal non-ending action --------------------
        $state = $this->persistence->snapshot($this->state);

        return [
            'ok'         => true,
            'state'      => $state,
            'handEnded'  => false,
            'summary'    => null,
            'matchEnded' => false,
            'winner'     => null,
            'loser'      => null,
        ];
    }

    public function startNextHand(?int $seed = null): array
    {
        return $this->startHand($seed);
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
            return [
                'ok'         => true,
                'state'      => $this->persistence->snapshot($this->state),
                'handEnded'  => false,
                'summary'    => null,
                'matchEnded' => true,
                'winner'     => [
                    'seat'    => $forfeiterSeat,
                    'user_id' => $forfeiter->user_id,
                    'stack'   => $forfeiter->stack,
                ],
                'loser'      => null,
                'reason'     => 'forfeit',
            ];
        }

        // Award all remaining chips + pot to opponent
        $totalAward = $this->state->pot + $forfeiter->stack;

        if ($totalAward > 0) {
            $opponent->stack += $totalAward;
        }

        // Forfeiter is now effectively "busted"
        $forfeiter->stack = 0;

        // Clear any outstanding pot/bets
        $this->state->resetPot();

        // No hand is considered active anymore
        $this->handBootstrapped = false;

        // Reuse normal match-end detection to build winner/loser structure
        $matchEnd = $this->detectMatchEnd();
        if ($matchEnd !== null) {
            $matchEnd['reason'] = 'forfeit';
            return $matchEnd;
        }

        // Fallback (should not be hit if heads-up and logic is consistent)
        return [
            'ok'         => true,
            'state'      => $this->persistence->snapshot($this->state),
            'handEnded'  => false,
            'summary'    => null,
            'matchEnded' => true,
            'winner'     => [
                'seat'    => $opponentSeat,
                'user_id' => $opponent->user_id,
                'stack'   => $opponent->stack,
            ],
            'loser'      => [
                'seat'    => $forfeiterSeat,
                'user_id' => $forfeiter->user_id,
                'stack'   => $forfeiter->stack,
            ],
            'reason'     => 'forfeit',
        ];
    }

    // =========================================================================
    // MATCH END DETECTION (HEADS-UP) — CORE OF A PATCH
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

        // FINAL match-end object - normalized structure
        return [
            'ok'         => true,
            'state'      => $this->persistence->snapshot($this->state),
            'handEnded'  => false,
            'summary'    => null,
            'matchEnded' => true,
            'winner'     => [
                'seat'    => $winnerSeat,
                'user_id' => $winner->user_id,
                'stack'   => $winner->stack,
            ],
            'loser'      => [
                'seat'    => $loserSeat,
                'user_id' => $loser->user_id,
                'stack'   => $loser->stack,
            ],
        ];
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
        $this->state->resetPot();

        // Build players array for consistency with showdown summary
        $players = [];
        foreach ($this->state->players as $seat => $p) {
            $players[$seat] = [
                'seat'         => $seat,
                'user_id'      => $p->user_id,
                'cards'        => $p->cards,
                'folded'       => $p->folded,
                'stack'        => $p->stack,
                'bet'          => $p->bet ?? 0,
                'contribution' => $p->contribution,
            ];
        }

        return [
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
            'players' => $players,
        ];
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

        // Apply payouts
        foreach ($this->state->players as $seat => $p) {
            $delta = $wc['payouts'][$seat] ?? 0;
            if ($delta > 0) {
                $p->stack += $delta;
            }
        }

        $this->state->resetPot();

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

        // Full per-hand summary
        $players = [];
        foreach ($this->state->players as $seat => $p) {
            $players[$seat] = [
                'seat'         => $seat,
                'user_id'      => $p->user_id,
                'cards'        => $p->cards,
                'folded'       => $p->folded,
                'stack'        => $p->stack,
                'bet'          => $p->bet ?? 0,
                'contribution' => $p->contribution,
            ];
        }

        return [
            'event'   => 'hand_end',
            'reason'  => 'showdown',
            'pot'     => $wc['totalPot'],
            'board'   => $this->state->board,
            'winners' => $winners,
            'players' => $players,
        ];
    }
}
