<?php
// backend/tests/helpers/GameServiceStateHelpers.php
// -----------------------------------------------------------------------------
// Helper trait for reading and writing internal GameService state in tests.
// Uses Reflection to access private properties, allowing tests to set up
// specific game states without going through normal game flow.
// -----------------------------------------------------------------------------

declare(strict_types=1);

require_once __DIR__ . '/../../app/services/game/GameService.php';
require_once __DIR__ . '/../../app/services/game/rules/GameTypes.php';

trait GameServiceStateHelpers
{
    /**
     * Force set the game phase
     * 
     * @param GameService $game Game service instance
     * @param Phase $phase Phase to set
     * @return void
     */
    protected function forcePhase(GameService $game, Phase $phase): void
    {
        $reflection = new \ReflectionClass($game);
        $property = $reflection->getProperty('phase');
        $property->setAccessible(true);
        $property->setValue($game, $phase);
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
        $reflection = new \ReflectionClass($game);
        $playersProperty = $reflection->getProperty('players');
        $playersProperty->setAccessible(true);
        $players = $playersProperty->getValue($game);
        
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
        $reflection = new \ReflectionClass($game);
        $property = $reflection->getProperty('currentBet');
        $property->setAccessible(true);
        $property->setValue($game, $bet);
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
        $reflection = new \ReflectionClass($game);
        $property = $reflection->getProperty('lastRaiseAmount');
        $property->setAccessible(true);
        $property->setValue($game, $amount);
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
        $reflection = new \ReflectionClass($game);
        $playersProperty = $reflection->getProperty('players');
        $playersProperty->setAccessible(true);
        $players = $playersProperty->getValue($game);
        
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
        $reflection = new \ReflectionClass($game);
        $playersProperty = $reflection->getProperty('players');
        $playersProperty->setAccessible(true);
        $players = $playersProperty->getValue($game);
        
        foreach ($investments as $seat => $amount) {
            if (isset($players[$seat])) {
                $players[$seat]->totalInvested = (int)$amount;
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
        $reflection = new \ReflectionClass($game);
        $playersProperty = $reflection->getProperty('players');
        $playersProperty->setAccessible(true);
        $players = $playersProperty->getValue($game);
        
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
        $reflection = new \ReflectionClass($game);
        $property = $reflection->getProperty('board');
        $property->setAccessible(true);
        $property->setValue($game, $cards);
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
        $reflection = new \ReflectionClass($game);
        $property = $reflection->getProperty('currentBet');
        $property->setAccessible(true);
        return (int)$property->getValue($game);
    }

    /**
     * Get last raise amount
     * 
     * @param GameService $game Game service instance
     * @return int Last raise amount
     */
    protected function getLastRaiseAmount(GameService $game): int
    {
        $reflection = new \ReflectionClass($game);
        $property = $reflection->getProperty('lastRaiseAmount');
        $property->setAccessible(true);
        return (int)$property->getValue($game);
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
        $reflection = new \ReflectionClass($game);
        $playersProperty = $reflection->getProperty('players');
        $playersProperty->setAccessible(true);
        $players = $playersProperty->getValue($game);
        
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
        $reflection = new \ReflectionClass($game);
        $playersProperty = $reflection->getProperty('players');
        $playersProperty->setAccessible(true);
        $players = $playersProperty->getValue($game);
        
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
        $reflection = new \ReflectionClass($game);
        $property = $reflection->getProperty('dealerSeat');
        $property->setAccessible(true);
        return (int)$property->getValue($game);
    }

    /**
     * Get small blind and big blind seats
     * 
     * @param GameService $game Game service instance
     * @return array{smallBlind: int, bigBlind: int} Array with 'smallBlind' and 'bigBlind' keys
     */
    protected function getBlindSeats(GameService $game): array
    {
        $reflection = new \ReflectionClass($game);
        $sbProperty = $reflection->getProperty('smallBlindSeat');
        $bbProperty = $reflection->getProperty('bigBlindSeat');
        $sbProperty->setAccessible(true);
        $bbProperty->setAccessible(true);
        
        return [
            'smallBlind' => (int)$sbProperty->getValue($game),
            'bigBlind' => (int)$bbProperty->getValue($game),
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
        $reflection = new \ReflectionClass($game);
        $property = $reflection->getProperty('phase');
        $property->setAccessible(true);
        return $property->getValue($game);
    }

    /**
     * Get pot amount
     * 
     * @param GameService $game Game service instance
     * @return int Pot amount
     */
    protected function getPot(GameService $game): int
    {
        $reflection = new \ReflectionClass($game);
        $property = $reflection->getProperty('pot');
        $property->setAccessible(true);
        return (int)$property->getValue($game);
    }

    /**
     * Get players array
     * 
     * @param GameService $game Game service instance
     * @return array<int, PlayerState> Players array keyed by seat
     */
    protected function getPlayers(GameService $game): array
    {
        $reflection = new \ReflectionClass($game);
        $property = $reflection->getProperty('players');
        $property->setAccessible(true);
        return $property->getValue($game);
    }

    /**
     * Get board (community cards)
     * 
     * @param GameService $game Game service instance
     * @return array<string> Board cards
     */
    protected function getBoard(GameService $game): array
    {
        $reflection = new \ReflectionClass($game);
        $property = $reflection->getProperty('board');
        $property->setAccessible(true);
        return $property->getValue($game);
    }

    /**
     * Get active players (not folded, not all-in)
     * 
     * @param GameService $game Game service instance
     * @return array<int, PlayerState> Active players array keyed by seat
     */
    protected function getActivePlayers(GameService $game): array
    {
        $reflection = new \ReflectionClass($game);
        $method = $reflection->getMethod('getActivePlayers');
        $method->setAccessible(true);
        return $method->invoke($game);
    }

    /**
     * Get action seat (current player to act)
     * 
     * @param GameService $game Game service instance
     * @return int Action seat number
     */
    protected function getActionSeat(GameService $game): int
    {
        $reflection = new \ReflectionClass($game);
        $property = $reflection->getProperty('actionSeat');
        $property->setAccessible(true);
        return (int)$property->getValue($game);
    }

    /**
     * Get last raise seat
     * 
     * @param GameService $game Game service instance
     * @return int Last raise seat number
     */
    protected function getLastRaiseSeat(GameService $game): int
    {
        $reflection = new \ReflectionClass($game);
        $property = $reflection->getProperty('lastRaiseSeat');
        $property->setAccessible(true);
        return (int)$property->getValue($game);
    }

    /**
     * Create a GameService instance with specified players and options
     * 
     * @param array<int, array{seat: int, stack: int}> $players Array of player configs
     * @param array<string, mixed> $options Optional configuration:
     *   - 'smallBlindAmount' => int (default: 10)
     *   - 'bigBlindAmount' => int (default: 20)
     *   - 'pdo' => PDO|null (default: null)
     *   - 'gameId' => int|null (default: null)
     *   - 'tableId' => int|null (default: null)
     *   - 'dealer' => DealerService|null (default: null, creates new if not provided)
     * @return GameService Game service instance with players injected
     */
    protected function createGameService(array $players, array $options = []): GameService
    {
        require_once __DIR__ . '/../../app/services/game/cards/DealerService.php';
        
        $smallBlindAmount = (int)($options['smallBlindAmount'] ?? 10);
        $bigBlindAmount = (int)($options['bigBlindAmount'] ?? 20);
        $pdo = $options['pdo'] ?? null;
        $gameId = isset($options['gameId']) ? (int)$options['gameId'] : null;
        $tableId = isset($options['tableId']) ? (int)$options['tableId'] : null;
        
        $game = new GameService($smallBlindAmount, $bigBlindAmount, $pdo, $gameId, $tableId);
        
        // Inject custom dealer if provided
        if (isset($options['dealer']) && $options['dealer'] instanceof \DealerService) {
            $reflection = new \ReflectionClass($game);
            $dealerProperty = $reflection->getProperty('dealer');
            $dealerProperty->setAccessible(true);
            $dealerProperty->setValue($game, $options['dealer']);
        }
        
        // Inject players using reflection
        if (!empty($players)) {
            $reflection = new \ReflectionClass($game);
            $playersProperty = $reflection->getProperty('players');
            $playersProperty->setAccessible(true);
            
            $playerStates = [];
            foreach ($players as $playerConfig) {
                $seat = (int)$playerConfig['seat'];
                $stack = (int)$playerConfig['stack'];
                $playerStates[$seat] = new \PlayerState(
                    seat: $seat,
                    stack: $stack
                );
            }
            
            $playersProperty->setValue($game, $playerStates);
        }
        
        return $game;
    }
}

