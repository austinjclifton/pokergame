<?php
// backend/tests/helpers/PlayerStateHelpers.php
// -----------------------------------------------------------------------------
// Helper trait for creating and manipulating PlayerState objects in tests.
// Provides utilities for setting up player states without going through
// the full game service initialization.
// -----------------------------------------------------------------------------

declare(strict_types=1);

require_once __DIR__ . '/../../app/services/game/rules/GameTypes.php';

trait PlayerStateHelpers
{
    /**
     * Create a PlayerState object with specified properties
     * 
     * @param int $seat Player seat number
     * @param int $stack Player stack size
     * @param array<string, mixed> $options Additional properties:
     *   - 'bet' => int (default: 0)
     *   - 'folded' => bool (default: false)
     *   - 'allIn' => bool (default: false)
     *   - 'cards' => array (default: [])
     *   - 'handRank' => int|null (default: null)
     *   - 'handDescription' => string|null (default: null)
     *   - 'actedThisStreet' => bool (default: false)
     *   - 'totalInvested' => int (default: 0)
     * @return PlayerState
     */
    protected function makePlayerState(int $seat, int $stack, array $options = []): PlayerState
    {
        $player = new PlayerState(
            seat: $seat,
            stack: $stack,
            bet: $options['bet'] ?? 0,
            folded: $options['folded'] ?? false,
            allIn: $options['allIn'] ?? false,
            cards: $options['cards'] ?? [],
            handRank: $options['handRank'] ?? null,
            handDescription: $options['handDescription'] ?? null
        );
        
        if (isset($options['actedThisStreet'])) {
            $player->actedThisStreet = (bool)$options['actedThisStreet'];
        }
        
        if (isset($options['totalInvested'])) {
            $player->totalInvested = (int)$options['totalInvested'];
        }
        
        return $player;
    }

    /**
     * Create an array of PlayerState objects from configuration
     * 
     * @param array<int, array{seat: int, stack: int, ...}> $configs Array of player configs
     *   Each config should have 'seat' and 'stack', plus optional properties
     * @return array<int, PlayerState> Array keyed by seat number
     */
    protected function makePlayerArray(array $configs): array
    {
        $players = [];
        foreach ($configs as $config) {
            $seat = (int)$config['seat'];
            $stack = (int)$config['stack'];
            $options = $config;
            unset($options['seat'], $options['stack']);
            $players[$seat] = $this->makePlayerState($seat, $stack, $options);
        }
        return $players;
    }

    /**
     * Set totalInvested on a PlayerState
     * 
     * @param PlayerState $player Player state object
     * @param int $amount Total invested amount
     * @return void
     */
    protected function setTotalInvested(PlayerState $player, int $amount): void
    {
        $player->totalInvested = $amount;
    }

    /**
     * Set hand rank and description on a PlayerState
     * 
     * @param PlayerState $player Player state object
     * @param int $rank Hand rank value
     * @param string $description Hand description
     * @return void
     */
    protected function setHandRank(PlayerState $player, int $rank, string $description): void
    {
        $player->handRank = $rank;
        $player->handDescription = $description;
    }

    /**
     * Set folded status on a PlayerState
     * 
     * @param PlayerState $player Player state object
     * @param bool $folded Folded status
     * @return void
     */
    protected function setFolded(PlayerState $player, bool $folded): void
    {
        $player->folded = $folded;
    }

    /**
     * Set all-in status on a PlayerState
     * 
     * @param PlayerState $player Player state object
     * @param bool $allIn All-in status
     * @return void
     */
    protected function setAllIn(PlayerState $player, bool $allIn): void
    {
        $player->allIn = $allIn;
    }

    /**
     * Set hole cards on a PlayerState
     * 
     * @param PlayerState $player Player state object
     * @param array<string> $cards Array of card strings (e.g., ['As', 'Kh'])
     * @return void
     */
    protected function setCards(PlayerState $player, array $cards): void
    {
        $player->cards = $cards;
    }
}

