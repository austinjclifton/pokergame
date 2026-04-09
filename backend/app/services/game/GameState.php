<?php
declare(strict_types=1);

require_once __DIR__ . '/PlayerState.php';
require_once __DIR__ . '/rules/GameTypes.php';
require_once __DIR__ . '/cards/DealerService.php';

final class GameState
{
    /** @var array<int,PlayerState> */
    public array $players = [];

    /** @var array<string> */
    public array $board = [];

    public Phase $phase;

    public int $pot = 0;
    public int $currentBet = 0;

    public int $dealerSeat = 0;
    public int $smallBlindSeat = 0;
    public int $bigBlindSeat = 0;

    public int $smallBlindAmount = 10;
    public int $bigBlindAmount = 20;

    public int $actionSeat = 0;
    public int $lastRaiseSeat = -1;
    public int $lastRaiseAmount = 0;

    public int $handIndex = 0;

    public ?DealerService $dealer = null;

    /** Optional testing deck seed */
    public ?int $deckSeed = null;

    /** Beginning-of-hand stacks (for UI replay/etc.) */
    public array $handStartingStacks = [];

    /** Ending hand result */
    public ?array $lastHandResult = null;

    public function __construct()
    {
        $this->phase = Phase::PREFLOP;
    }

    /**
     * Initialize seated players
     *
     * @param array<int, array{seat:int, stack:int, user_id?:int}> $players
     */
    public function initializePlayers(array $players): void
    {
        $this->players = [];

        foreach ($players as $p) {
            $seat  = (int)$p['seat'];
            $stack = (int)$p['stack'];
            $player = new PlayerState($seat, $stack);
            if (isset($p['user_id'])) {
                $player->user_id = (int)$p['user_id'];
            }

            $this->players[$seat] = $player;
        }
    }

    /**
     * Convert to array for WS/UI
     */
    public function toArray(): array
    {
        $playersArr = [];

        foreach ($this->players as $seat => $p) {
            $playersArr[$seat] = [
                'seat'            => $p->seat,
                'stack'           => $p->stack,
                'bet'             => $p->bet,
                'folded'          => $p->folded,
                'allIn'           => $p->allIn,
                'actedThisStreet' => $p->actedThisStreet,
                'totalInvested'   => $p->totalInvested,
                'contribution'    => $p->contribution,
                'user_id'         => $p->user_id,
                'cards'           => $p->cards,
                'handRank'        => $p->handRank,
                'handDescription' => $p->handDescription,
            ];
        }

        return [
            'phase'          => $this->phase->value,
            'board'          => $this->board,
            'pot'            => $this->pot,
            'currentBet'     => $this->currentBet,
            'dealerSeat'     => $this->dealerSeat,
            'smallBlindSeat' => $this->smallBlindSeat,
            'bigBlindSeat'   => $this->bigBlindSeat,
            'smallBlindAmount' => $this->smallBlindAmount,
            'bigBlindAmount' => $this->bigBlindAmount,
            'actionSeat'     => $this->actionSeat,
            'lastRaiseSeat'  => $this->lastRaiseSeat,
            'lastRaiseAmount' => $this->lastRaiseAmount,
            'players'        => $playersArr,
            'lastHandResult' => $this->lastHandResult,
            'handIndex'      => $this->handIndex,
            'handStartingStacks' => $this->handStartingStacks,
            'deckSeed'       => $this->deckSeed,
            'dealer'         => $this->dealer?->exportState(),
        ];
    }

    /**
     * Restore state from a persisted snapshot.
     *
     * @param array<string, mixed> $state
     */
    public function restoreFromArray(array $state): void
    {
        $this->players = [];
        if (isset($state['players']) && is_array($state['players'])) {
            foreach ($state['players'] as $seat => $row) {
                if (!is_array($row)) {
                    continue;
                }

                $seatNo = (int)($row['seat'] ?? $seat);
                $player = new PlayerState($seatNo, (int)($row['stack'] ?? 0));
                $player->bet = (int)($row['bet'] ?? 0);
                $player->folded = (bool)($row['folded'] ?? false);
                $player->allIn = (bool)($row['allIn'] ?? false);
                $player->actedThisStreet = (bool)($row['actedThisStreet'] ?? false);

                $cards = $row['cards'] ?? [];
                $player->cards = is_array($cards)
                    ? array_values(array_map(static fn($card): string => (string) $card, $cards))
                    : [];

                $player->handRank = array_key_exists('handRank', $row) && $row['handRank'] !== null
                    ? (int)$row['handRank']
                    : null;
                $player->handDescription = array_key_exists('handDescription', $row) && $row['handDescription'] !== null
                    ? (string)$row['handDescription']
                    : null;
                $player->totalInvested = (int)($row['totalInvested'] ?? 0);
                $player->contribution = (int)($row['contribution'] ?? $player->totalInvested);
                $player->user_id = (int)($row['user_id'] ?? 0);

                $this->players[$seatNo] = $player;
            }

            ksort($this->players);
        }

        $board = $state['board'] ?? [];
        $this->board = is_array($board)
            ? array_values(array_map(static fn($card): string => (string) $card, $board))
            : [];
        $this->phase = Phase::from((string)($state['phase'] ?? Phase::PREFLOP->value));
        $this->pot = (int)($state['pot'] ?? 0);
        $this->currentBet = (int)($state['currentBet'] ?? 0);
        $this->dealerSeat = (int)($state['dealerSeat'] ?? 0);
        $this->smallBlindSeat = (int)($state['smallBlindSeat'] ?? 0);
        $this->bigBlindSeat = (int)($state['bigBlindSeat'] ?? 0);
        $this->smallBlindAmount = (int)($state['smallBlindAmount'] ?? $this->smallBlindAmount);
        $this->bigBlindAmount = (int)($state['bigBlindAmount'] ?? $this->bigBlindAmount);
        $this->actionSeat = (int)($state['actionSeat'] ?? 0);
        $this->lastRaiseSeat = (int)($state['lastRaiseSeat'] ?? -1);
        $this->lastRaiseAmount = (int)($state['lastRaiseAmount'] ?? 0);
        $this->handIndex = (int)($state['handIndex'] ?? 0);
        $this->handStartingStacks = isset($state['handStartingStacks']) && is_array($state['handStartingStacks'])
            ? $state['handStartingStacks']
            : [];
        $this->lastHandResult = isset($state['lastHandResult']) && is_array($state['lastHandResult'])
            ? $state['lastHandResult']
            : null;
        $this->deckSeed = array_key_exists('deckSeed', $state) && $state['deckSeed'] !== null
            ? (int)$state['deckSeed']
            : null;
        $this->dealer = $this->restoreDealerFromArray($state);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function restoreDealerFromArray(array $state): ?DealerService
    {
        $dealerState = $state['dealer'] ?? null;
        if (is_array($dealerState)) {
            $dealer = new DealerService();
            $dealer->restoreState($dealerState);
            return $dealer;
        }

        if ($this->deckSeed === null) {
            return null;
        }

        $dealer = new DealerService($this->deckSeed);
        $dealer->shuffleDeck();
        $dealer->skipCards($this->countDealtCards());
        return $dealer;
    }

    private function countDealtCards(): int
    {
        $count = count($this->board);

        foreach ($this->players as $player) {
            $count += count($player->cards);
        }

        return $count;
    }

    /**
     * Reset only per-STREET data (NOT chips).
     *
     * This must NOT touch:
     * - contribution
     * - totalInvested
     * - stack
     */
    public function resetPot(): void
    {
        // Reset pot and betting state for a *new street*, not a new hand
        $this->currentBet      = 0;
        $this->lastRaiseSeat   = -1;
        $this->lastRaiseAmount = 0;

        foreach ($this->players as $p) {
            // per-street bet
            $p->bet = 0;

            // allow them to act on next street unless folded/all-in
            if (!$p->folded && !$p->allIn) {
                $p->actedThisStreet = false;
            }
        }

        // DO NOT:
        // - zero contribution
        // - zero totalInvested
        // - zero pot
        // These are needed for showdown settlement.
    }
}
