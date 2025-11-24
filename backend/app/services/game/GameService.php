<?php
declare(strict_types=1);

require_once __DIR__ . '/GameState.php';
require_once __DIR__ . '/HandStarter.php';
require_once __DIR__ . '/ActionProcessor.php';
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

    /** Hard guard to prevent multiple startHand calls */
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

    // ---------------------------------------------------------------------
    // Basic getters/setters
    // ---------------------------------------------------------------------
    public function setGameId(?int $id): void { $this->gameId = $id; }
    public function getGameId(): ?int { return $this->gameId; }
    public function getVersion(): int { return $this->version; }
    public function setVersion(int $v): void { $this->version = $v; }
    public function getSnapshot(): array { return $this->state->toArray(); }

    public function isHandBootstrapped(): bool { return $this->handBootstrapped; }
    public function markHandBootstrapped(): void { $this->handBootstrapped = true; }
    public function clearBootstrapFlag(): void { $this->handBootstrapped = false; }

    // ---------------------------------------------------------------------
    // Load seated players
    // ---------------------------------------------------------------------
    public function loadPlayers(array $players): void
    {
        $this->state->initializePlayers($players);
    }

    // ---------------------------------------------------------------------
    // Start a new hand (GUARDED)
    // ---------------------------------------------------------------------
    public function startHand(?int $seed = null): array
    {
        if ($this->handBootstrapped) {
            error_log("[GameService] Ignored duplicate startHand() — already bootstrapped");
            return $this->getSnapshot();
        }

        HandStarter::startHand(
            $this->state,
            $this->smallBlind,
            $this->bigBlind,
            $seed
        );

        $this->handBootstrapped = true;

        return $this->persistence->snapshot($this->state);
    }

    // ---------------------------------------------------------------------
    // Main action handler
    // ---------------------------------------------------------------------
    public function applyAction(int $seat, string $action, int $amount = 0): array
    {
        try {
            $a = ActionType::from(strtolower($action));
        } catch (\ValueError) {
            return ['ok' => false, 'message' => 'Invalid action'];
        }

        // 1. Apply betting or fold
        $result = ActionProcessor::apply($this->state, $seat, $a, $amount);
        if (!($result['ok'] ?? false)) {
            return $result;
        }

        // 2. Fold → hand ends
        if ($result['handEnded'] ?? false) {
            $summary = $this->buildFoldSummary();
            $state   = $this->persistence->snapshot($this->state);

            $this->handBootstrapped = false;

            return [
                'ok'        => true,
                'state'     => $state,
                'handEnded' => true,
                'summary'   => $summary,
            ];
        }

        // 3. Phase progression
        $eval      = new HandEvaluator();
        $phaseInfo = PhaseEngine::advance($this->state, $eval);

        // 4. Showdown
        if ($phaseInfo !== null && ($phaseInfo['handEnded'] ?? false)) {
            $summary = $this->runShowdownSettlement();
            $state   = $this->persistence->snapshot($this->state);

            $this->handBootstrapped = false;

            return [
                'ok'        => true,
                'state'     => $state,
                'handEnded' => true,
                'summary'   => $summary,
            ];
        }

        // 5. Normal action
        $state = $this->persistence->snapshot($this->state);

        return [
            'ok'        => true,
            'state'     => $state,
            'handEnded' => false,
            'summary'   => null,
        ];
    }

    // ---------------------------------------------------------------------
    // Start next hand
    // ---------------------------------------------------------------------
    public function startNextHand(?int $seed = null): array
    {
        return $this->startHand($seed);
    }

    // ---------------------------------------------------------------------
    // Legal helpers
    // ---------------------------------------------------------------------
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

    // ---------------------------------------------------------------------
    // INTERNAL — Fold settlement
    // ---------------------------------------------------------------------
    private function buildFoldSummary(): array
    {
        $active = array_filter($this->state->players, fn(PlayerState $p) => !$p->folded);
        $winnerSeat = array_keys($active)[0];

        $amount = $this->state->pot;
        $winner = $this->state->players[$winnerSeat];

        $winner->stack += $amount;
        $this->state->resetPot();

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
        ];
    }

    // ---------------------------------------------------------------------
    // INTERNAL — Showdown settlement
    // ---------------------------------------------------------------------
    private function runShowdownSettlement(): array
    {
        $input = [];
    
        foreach ($this->state->players as $seat => $p) {
            $input[] = [
                'seat'        => $seat,
                'user_id'     => $p->user_id,
                'cards'       => $p->cards,
                'folded'      => $p->folded,
                'contribution'=> $p->contribution,
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
    
        // Build winner info (winners[])
        $winners = [];
        foreach ($wc['handRanks'] as $info) {
            $seat = $info['seat'];
            $amt  = $wc['payouts'][$seat] ?? 0;
            if ($amt > 0) {
                $winners[] = [
                    'seat'            => $seat,
                    'amount'          => $amt,
                    'handDescription' => $info['name'],
                    'bestHand'        => $info['bestCards'],
                ];
            }
        }
    
        // ⭐ ADD THIS: include *full* player data including hole cards
        $playersForSummary = [];
        foreach ($this->state->players as $seat => $p) {
            $playersForSummary[$seat] = [
                'seat'            => $seat,
                'user_id'         => $p->user_id,
                'username'        => $p->username ?? null,
                'name'            => $p->name ?? null,
                'cards'           => $p->cards,     // <-- CRITICAL LINE
                'folded'          => $p->folded,
                'stack'           => $p->stack,
                'bet'             => $p->bet ?? 0,
                'handDescription' => null,
            ];
        }
    
        return [
            'event'   => 'hand_end',
            'reason'  => 'showdown',
            'pot'     => $wc['totalPot'],
            'board'   => $this->state->board,
            'winners' => $winners,
            'players' => $playersForSummary,   // <-- CRITICAL FIX
        ];
    }    
}
