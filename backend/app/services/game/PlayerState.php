<?php
declare(strict_types=1);

final class PlayerState
{
    public int $seat;
    public int $stack;

    public int $bet = 0;
    public bool $folded = false;
    public bool $allIn = false;
    public bool $actedThisStreet = false;

    /** @var array<string> */
    public array $cards = [];

    /** showdown fields */
    public ?int $handRank = null;
    public ?string $handDescription = null;

    /** total chips put in during this hand */
    public int $totalInvested = 0;

    /** NEW: amount contributed to pot (used by WinnerCalculator) */
    public int $contribution = 0;

    /** NEW: link back to user ID */
    public int $user_id = 0;

    public function __construct(int $seat, int $stack)
    {
        $this->seat  = $seat;
        $this->stack = $stack;
        $this->contribution = 0;
    }

    /**
     * Reset per-street state (FLOP → TURN → RIVER transitions)
     */
    public function resetForNewStreet(): void
    {
        $this->bet = 0;
        $this->actedThisStreet = false;
    }

    /**
     * Reset all per-hand state (not including seat or stack)
     */
    public function resetForNewHand(): void
    {
        $this->bet = 0;
        $this->folded = false;
        $this->allIn = false;
        $this->actedThisStreet = false;

        $this->totalInvested = 0;
        $this->contribution  = 0;

        $this->handRank = null;
        $this->handDescription = null;

        $this->cards = [];
    }
}
