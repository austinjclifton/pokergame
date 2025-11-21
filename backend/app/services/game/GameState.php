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
     * @param array<int, array{seat:int, stack:int}> $players
     */
    public function initializePlayers(array $players): void
    {
        $this->players = [];

        foreach ($players as $p) {
            $seat  = (int)$p['seat'];
            $stack = (int)$p['stack'];
            $this->players[$seat] = new PlayerState($seat, $stack);
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
            'actionSeat'     => $this->actionSeat,
            'players'        => $playersArr,
            'lastHandResult' => $this->lastHandResult,
            'handIndex'      => $this->handIndex,
        ];
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
        // CHIP TRACE
        $contributions = [];
        foreach ($this->players as $seat => $p) {
            $contributions[$seat] = $p->contribution;
        }
        error_log("[CHIP TRACE] RESET POT " . __FILE__ . ":" . __LINE__ . " oldPot={$this->pot} contributions=" . json_encode($contributions) . " (resetting betting state for new street)");
        
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
