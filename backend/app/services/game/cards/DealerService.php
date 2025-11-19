<?php
// backend/app/services/game/cards/DealerService.php
// -----------------------------------------------------------------------------
// Service for managing deck shuffling and dealing cards.
// Supports deterministic shuffling via seed for replay/verification.
// Pure logic - no database or networking.
// -----------------------------------------------------------------------------

declare(strict_types=1);

/**
 * Service for dealing cards from a shuffled deck
 */
final class DealerService
{
    private array $deck = [];
    private int $deckIndex = 0;
    private ?int $seed = null;

    /**
     * @param ?int $seed Optional seed for deterministic shuffling (useful for testing/replay)
     */
    public function __construct(?int $seed = null)
    {
        $this->seed = $seed;
    }

    /**
     * Initialize and shuffle a standard 52-card deck
     * Uses deterministic shuffle if seed was provided in constructor
     */
    public function shuffleDeck(): void
    {
        $suits = ['S', 'H', 'D', 'C']; // Spades, Hearts, Diamonds, Clubs
        $ranks = ['2', '3', '4', '5', '6', '7', '8', '9', 'T', 'J', 'Q', 'K', 'A'];
        
        $this->deck = [];
        foreach ($suits as $suit) {
            foreach ($ranks as $rank) {
                $this->deck[] = $rank . $suit; // e.g., "AS", "KD", "2C"
            }
        }
        
        // Use deterministic shuffle if seed is provided
        if ($this->seed !== null) {
            mt_srand($this->seed);
        }
        
        // Fisher-Yates shuffle
        for ($i = count($this->deck) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$this->deck[$i], $this->deck[$j]] = [$this->deck[$j], $this->deck[$i]];
        }
        
        // Reset RNG if we used a seed (to avoid affecting other code)
        if ($this->seed !== null) {
            mt_srand(); // Reset to random seed
        }
        
        $this->deckIndex = 0;
    }

    /**
     * Deal a single card from the deck
     * 
     * @return string Card string (e.g., "AS", "KD")
     * @throws RuntimeException if deck is empty
     */
    public function dealCard(): string
    {
        if ($this->deckIndex >= count($this->deck)) {
            throw new RuntimeException('Not enough cards in deck');
        }

        $card = $this->deck[$this->deckIndex];
        $this->deckIndex++;
        
        return $card;
    }

    /**
     * Deal multiple cards from the deck
     * 
     * @param int $count Number of cards to deal
     * @return array<string> Array of card strings
     * @throws RuntimeException if deck doesn't have enough cards
     */
    public function dealCards(int $count): array
    {
        $cards = [];
        for ($i = 0; $i < $count; $i++) {
            $cards[] = $this->dealCard();
        }
        return $cards;
    }

    /**
     * Deal hole cards to players
     * 
     * @param array<int, array{cards?: array<string>}> $players Array of player data (modified in place)
     * @param int $cardsPerPlayer Number of cards per player (default: 2)
     * @return void
     */
    public function dealHoleCards(array &$players, int $cardsPerPlayer = 2): void
    {
        foreach ($players as $key => &$player) {
            if (!isset($player['cards'])) {
                $player['cards'] = [];
            }
            
            for ($i = 0; $i < $cardsPerPlayer; $i++) {
                $player['cards'][] = $this->dealCard();
            }
        }
        unset($player); // Break reference
    }

    /**
     * Deal the flop (3 community cards)
     * 
     * @param array<string> $community Community cards array (modified in place)
     * @return void
     */
    public function dealFlop(array &$community): void
    {
        for ($i = 0; $i < 3; $i++) {
            $community[] = $this->dealCard();
        }
    }

    /**
     * Deal the turn (4th community card)
     * 
     * @param array<string> $community Community cards array (modified in place)
     * @return void
     */
    public function dealTurn(array &$community): void
    {
        $community[] = $this->dealCard();
    }

    /**
     * Deal the river (5th community card)
     * 
     * @param array<string> $community Community cards array (modified in place)
     * @return void
     */
    public function dealRiver(array &$community): void
    {
        $community[] = $this->dealCard();
    }

    /**
     * Get remaining cards in deck (for testing/debugging)
     * 
     * @return array<string>
     */
    public function getRemainingCards(): array
    {
        return array_slice($this->deck, $this->deckIndex);
    }

    /**
     * Get number of cards remaining in deck
     * 
     * @return int
     */
    public function getRemainingCount(): int
    {
        return count($this->deck) - $this->deckIndex;
    }

    /**
     * Reset deck index (useful for testing)
     * 
     * @return void
     */
    public function reset(): void
    {
        $this->deckIndex = 0;
    }
}

