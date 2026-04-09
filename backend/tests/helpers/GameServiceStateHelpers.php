<?php
// backend/tests/helpers/GameServiceStateHelpers.php
// -----------------------------------------------------------------------------
// Helper trait for reading and writing internal GameService state in tests.
// Uses Reflection to access private properties, allowing tests to set up
// specific game states without going through normal game flow.
// -----------------------------------------------------------------------------

declare(strict_types=1);

require_once __DIR__ . '/../../app/services/game/GameService.php';
require_once __DIR__ . '/../../app/services/game/GamePersistence.php';
require_once __DIR__ . '/../../app/services/game/rules/GameTypes.php';

trait GameServiceStateHelpers
{
    private function getGameState(GameService $game): GameState
    {
        $reflection = new \ReflectionClass($game);
        $property = $reflection->getProperty('state');
        $property->setAccessible(true);

        return $property->getValue($game);
    }

    private function getGameStateProperty(GameService $game, string $property): mixed
    {
        $state = $this->getGameState($game);
        return $state->{$property};
    }

    private function setGameStateProperty(GameService $game, string $property, mixed $value): void
    {
        $state = $this->getGameState($game);
        $state->{$property} = $value;
    }

    /**
     * Force set the game phase
     * 
     * @param GameService $game Game service instance
     * @param Phase $phase Phase to set
     * @return void
     */
    protected function forcePhase(GameService $game, Phase $phase): void
    {
        $this->setGameStateProperty($game, 'phase', $phase);
    }

    /**
     * Force set player bets
     * 
     * @param GameService $game Game service instance
     * @param array<int, int> $bets Array of seat => bet amount
     * @return void
     */
    protected function forceBets(GameService $game, array $bets): void
    {
        $players = $this->getPlayers($game);
        
        foreach ($bets as $seat => $bet) {
            if (isset($players[$seat])) {
                $players[$seat]->bet = (int)$bet;
            }
        }
    }

    /**
     * Force set current bet
     * 
     * @param GameService $game Game service instance
     * @param int $bet Current bet amount
     * @return void
     */
    protected function forceCurrentBet(GameService $game, int $bet): void
    {
        $this->setGameStateProperty($game, 'currentBet', $bet);
    }

    /**
     * Force set last raise amount
     * 
     * @param GameService $game Game service instance
     * @param int $amount Last raise amount
     * @return void
     */
    protected function forceLastRaiseAmount(GameService $game, int $amount): void
    {
        $this->setGameStateProperty($game, 'lastRaiseAmount', $amount);
    }

    /**
     * Force set actedThisStreet for players
     * 
     * @param GameService $game Game service instance
     * @param array<int, bool> $acted Array of seat => actedThisStreet value
     * @return void
     */
    protected function forceActedThisStreet(GameService $game, array $acted): void
    {
        $players = $this->getPlayers($game);
        
        foreach ($acted as $seat => $value) {
            if (isset($players[$seat])) {
                $players[$seat]->actedThisStreet = (bool)$value;
            }
        }
    }

    /**
     * Force set totalInvested for players
     * 
     * @param GameService $game Game service instance
     * @param array<int, int> $investments Array of seat => totalInvested amount
     * @return void
     */
    protected function forceTotalInvested(GameService $game, array $investments): void
    {
        $players = $this->getPlayers($game);
        
        foreach ($investments as $seat => $amount) {
            if (isset($players[$seat])) {
                $players[$seat]->totalInvested = (int)$amount;
                $players[$seat]->contribution = (int)$amount;
            }
        }
    }

    /**
     * Force set hole cards for a player
     * 
     * @param GameService $game Game service instance
     * @param int $seat Player seat number
     * @param array<string> $cards Array of card strings (e.g., ['As', 'Kh'])
     * @return void
     */
    protected function forceCards(GameService $game, int $seat, array $cards): void
    {
        $players = $this->getPlayers($game);
        
        if (isset($players[$seat])) {
            $players[$seat]->cards = $cards;
        }
    }

    /**
     * Force set community cards (board)
     * 
     * @param GameService $game Game service instance
     * @param array<string> $cards Array of card strings (e.g., ['As', 'Kh', 'Qd'])
     * @return void
     */
    protected function forceBoard(GameService $game, array $cards): void
    {
        $this->setGameStateProperty($game, 'board', $cards);
    }

    protected function forcePot(GameService $game, int $amount): void
    {
        $this->setGameStateProperty($game, 'pot', $amount);
    }

    protected function forceDealerSeat(GameService $game, int $seat): void
    {
        $this->setGameStateProperty($game, 'dealerSeat', $seat);
    }

    protected function forceBlindSeats(GameService $game, int $smallBlindSeat, int $bigBlindSeat): void
    {
        $this->setGameStateProperty($game, 'smallBlindSeat', $smallBlindSeat);
        $this->setGameStateProperty($game, 'bigBlindSeat', $bigBlindSeat);
    }

    protected function forceActionSeat(GameService $game, int $seat): void
    {
        $this->setGameStateProperty($game, 'actionSeat', $seat);
    }

    /**
     * Alias for forceBoard() - force set community cards
     * 
     * @param GameService $game Game service instance
     * @param array<string> $cards Array of card strings
     * @return void
     */
    protected function forceCommunityCards(GameService $game, array $cards): void
    {
        $this->forceBoard($game, $cards);
    }

    /**
     * Get current bet
     * 
     * @param GameService $game Game service instance
     * @return int Current bet amount
     */
    protected function getCurrentBet(GameService $game): int
    {
        return (int)$this->getGameStateProperty($game, 'currentBet');
    }

    /**
     * Get last raise amount
     * 
     * @param GameService $game Game service instance
     * @return int Last raise amount
     */
    protected function getLastRaiseAmount(GameService $game): int
    {
        return (int)$this->getGameStateProperty($game, 'lastRaiseAmount');
    }

    /**
     * Get totalInvested for a player
     * 
     * @param GameService $game Game service instance
     * @param int $seat Player seat number
     * @return int Total invested amount
     */
    protected function getTotalInvested(GameService $game, int $seat): int
    {
        $players = $this->getPlayers($game);
        
        if (!isset($players[$seat])) {
            return 0;
        }
        
        return (int)$players[$seat]->totalInvested;
    }

    /**
     * Get actedThisStreet for a player
     * 
     * @param GameService $game Game service instance
     * @param int $seat Player seat number
     * @return bool Acted this street status
     */
    protected function getActedThisStreet(GameService $game, int $seat): bool
    {
        $players = $this->getPlayers($game);
        
        if (!isset($players[$seat])) {
            return false;
        }
        
        return (bool)$players[$seat]->actedThisStreet;
    }

    /**
     * Get dealer seat
     * 
     * @param GameService $game Game service instance
     * @return int Dealer seat number
     */
    protected function getDealerSeat(GameService $game): int
    {
        return (int)$this->getGameStateProperty($game, 'dealerSeat');
    }

    /**
     * Get small blind and big blind seats
     * 
     * @param GameService $game Game service instance
     * @return array{smallBlind: int, bigBlind: int} Array with 'smallBlind' and 'bigBlind' keys
     */
    protected function getBlindSeats(GameService $game): array
    {
        return [
            'smallBlind' => (int)$this->getGameStateProperty($game, 'smallBlindSeat'),
            'bigBlind' => (int)$this->getGameStateProperty($game, 'bigBlindSeat'),
        ];
    }

    /**
     * Get current phase
     * 
     * @param GameService $game Game service instance
     * @return Phase Current phase
     */
    protected function getPhase(GameService $game): Phase
    {
        return $this->getGameStateProperty($game, 'phase');
    }

    /**
     * Get pot amount
     * 
     * @param GameService $game Game service instance
     * @return int Pot amount
     */
    protected function getPot(GameService $game): int
    {
        return (int)$this->getGameStateProperty($game, 'pot');
    }

    /**
     * Get players array
     * 
     * @param GameService $game Game service instance
     * @return array<int, PlayerState> Players array keyed by seat
     */
    protected function getPlayers(GameService $game): array
    {
        return $this->getGameStateProperty($game, 'players');
    }

    /**
     * Get board (community cards)
     * 
     * @param GameService $game Game service instance
     * @return array<string> Board cards
     */
    protected function getBoard(GameService $game): array
    {
        return $this->getGameStateProperty($game, 'board');
    }

    /**
     * Get active players (not folded, not all-in)
     * 
     * @param GameService $game Game service instance
     * @return array<int, PlayerState> Active players array keyed by seat
     */
    protected function getActivePlayers(GameService $game): array
    {
        return array_filter(
            $this->getPlayers($game),
            static fn(PlayerState $player): bool => !$player->folded && !$player->allIn
        );
    }

    /**
     * Get action seat (current player to act)
     * 
     * @param GameService $game Game service instance
     * @return int Action seat number
     */
    protected function getActionSeat(GameService $game): int
    {
        return (int)$this->getGameStateProperty($game, 'actionSeat');
    }

    /**
     * Get last raise seat
     * 
     * @param GameService $game Game service instance
     * @return int Last raise seat number
     */
    protected function getLastRaiseSeat(GameService $game): int
    {
        return (int)$this->getGameStateProperty($game, 'lastRaiseSeat');
    }

    protected function getNextActiveSeat(array $players, int $startSeat): int
    {
        $seats = array_keys($players);
        sort($seats);

        $startIndex = array_search($startSeat, $seats, true);
        if ($startIndex === false) {
            return $seats[0] ?? -1;
        }

        $count = count($seats);
        for ($offset = 1; $offset <= $count; $offset++) {
            $seat = $seats[($startIndex + $offset) % $count];
            $player = $players[$seat];

            if (!$player->folded && !$player->allIn) {
                return $seat;
            }
        }

        return -1;
    }

    /**
     * Create a GameService instance with specified players and options
     * 
     * @param array<int, array{seat: int, stack: int}> $players Array of player configs
     * @param array<string, mixed> $options Optional configuration:
     *   - 'smallBlindAmount' => int (default: 10)
     *   - 'bigBlindAmount' => int (default: 20)
     *   - 'pdo' => PDO|null (default: sqlite memory connection)
     *   - 'gameId' => int|null (default: null)
     *   - 'dealer' => DealerService|null (default: null)
     * @return GameService Game service instance with players injected
     */
    protected function createGameService(array $players, array $options = []): GameService
    {
        require_once __DIR__ . '/../../app/services/game/cards/DealerService.php';
        
        $smallBlindAmount = (int)($options['smallBlindAmount'] ?? 10);
        $bigBlindAmount = (int)($options['bigBlindAmount'] ?? 20);
        $pdo = $options['pdo'] ?? new \PDO('sqlite::memory:');
        $gameId = isset($options['gameId']) ? (int)$options['gameId'] : null;

        $persistence = new GamePersistence($pdo);
        $game = new GameService($persistence, $smallBlindAmount, $bigBlindAmount);

        if ($gameId !== null) {
            $game->setGameId($gameId);
        }
        
        // Inject custom dealer if provided
        if (isset($options['dealer']) && $options['dealer'] instanceof \DealerService) {
            $this->setGameStateProperty($game, 'dealer', $options['dealer']);
        }
        
        if (!empty($players)) {
            $game->loadPlayers($players);
        }

        return $game;
    }
}

