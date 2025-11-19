<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DealerService.
 * 
 * Tests deck management, shuffling, and dealing functionality:
 * - Deck contains 52 unique cards
 * - Dealing removes cards properly
 * - Deterministic output given same seed
 * 
 * @covers \DealerService
 */
final class DealerServiceTest extends TestCase
{
    /**
     * Test that deck contains 52 unique cards
     */
    public function testDeckContains52UniqueCards(): void
    {
        require_once __DIR__ . '/../../app/services/game/cards/DealerService.php';
        
        $dealer = new DealerService();
        $dealer->shuffleDeck();
        
        // Get all cards by dealing them all
        $allCards = [];
        for ($i = 0; $i < 52; $i++) {
            $allCards[] = $dealer->dealCard();
        }
        
        $this->assertCount(52, $allCards, 'Deck should contain exactly 52 cards');
        
        // Check all cards are unique
        $uniqueCards = array_unique($allCards);
        $this->assertCount(52, $uniqueCards, 'All cards should be unique');
        
        // Verify card format (rank + suit)
        foreach ($allCards as $card) {
            $this->assertMatchesRegularExpression('/^[2-9TJQKA][SHDC]$/', $card, 
                "Card '{$card}' should match format: rank (2-9,T,J,Q,K,A) + suit (S,H,D,C)");
        }
    }

    /**
     * Test that dealing removes cards from deck
     */
    public function testDealingRemovesCardsFromDeck(): void
    {
        require_once __DIR__ . '/../../app/services/game/cards/DealerService.php';
        
        $dealer = new DealerService();
        $dealer->shuffleDeck();
        
        $initialCount = $dealer->getRemainingCount();
        $this->assertEquals(52, $initialCount, 'Deck should start with 52 cards');
        
        // Deal one card
        $card1 = $dealer->dealCard();
        $this->assertIsString($card1);
        $this->assertEquals(51, $dealer->getRemainingCount(), 'Deck should have 51 cards after dealing one');
        
        // Deal another card
        $card2 = $dealer->dealCard();
        $this->assertNotEquals($card1, $card2, 'Dealt cards should be different');
        $this->assertEquals(50, $dealer->getRemainingCount(), 'Deck should have 50 cards after dealing two');
    }

    /**
     * Test that dealing all cards throws exception
     */
    public function testDealingFromEmptyDeckThrowsException(): void
    {
        require_once __DIR__ . '/../../app/services/game/cards/DealerService.php';
        
        $dealer = new DealerService();
        $dealer->shuffleDeck();
        
        // Deal all 52 cards
        for ($i = 0; $i < 52; $i++) {
            $dealer->dealCard();
        }
        
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Not enough cards');
        
        $dealer->dealCard();
    }

    /**
     * Test deterministic shuffling with same seed
     */
    public function testDeterministicShufflingWithSameSeed(): void
    {
        require_once __DIR__ . '/../../app/services/game/cards/DealerService.php';
        
        $seed = 12345;
        
        // Create two dealers with same seed
        $dealer1 = new DealerService($seed);
        $dealer2 = new DealerService($seed);
        
        $dealer1->shuffleDeck();
        $dealer2->shuffleDeck();
        
        // Deal all cards from both decks
        $cards1 = [];
        $cards2 = [];
        
        for ($i = 0; $i < 52; $i++) {
            $cards1[] = $dealer1->dealCard();
            $cards2[] = $dealer2->dealCard();
        }
        
        $this->assertEquals($cards1, $cards2, 'Decks with same seed should produce identical card order');
    }

    /**
     * Test different seeds produce different orders
     */
    public function testDifferentSeedsProduceDifferentOrders(): void
    {
        require_once __DIR__ . '/../../app/services/game/cards/DealerService.php';
        
        $dealer1 = new DealerService(111);
        $dealer2 = new DealerService(222);
        
        $dealer1->shuffleDeck();
        $dealer2->shuffleDeck();
        
        // Deal first 10 cards from both
        $cards1 = [];
        $cards2 = [];
        
        for ($i = 0; $i < 10; $i++) {
            $cards1[] = $dealer1->dealCard();
            $cards2[] = $dealer2->dealCard();
        }
        
        $this->assertNotEquals($cards1, $cards2, 'Decks with different seeds should produce different card orders');
    }

    /**
     * Test dealHoleCards deals correct number of cards
     */
    public function testDealHoleCards(): void
    {
        require_once __DIR__ . '/../../app/services/game/cards/DealerService.php';
        
        $dealer = new DealerService();
        $dealer->shuffleDeck();
        
        $players = [
            ['seat' => 1],
            ['seat' => 2],
            ['seat' => 3],
        ];
        
        $dealer->dealHoleCards($players, 2);
        
        $this->assertCount(2, $players[0]['cards'], 'Player 1 should have 2 hole cards');
        $this->assertCount(2, $players[1]['cards'], 'Player 2 should have 2 hole cards');
        $this->assertCount(2, $players[2]['cards'], 'Player 3 should have 2 hole cards');
        
        // Verify all cards are different
        $allCards = array_merge(
            $players[0]['cards'],
            $players[1]['cards'],
            $players[2]['cards']
        );
        $uniqueCards = array_unique($allCards);
        $this->assertCount(6, $uniqueCards, 'All hole cards should be unique');
        
        // Verify 6 cards were dealt from deck
        $this->assertEquals(46, $dealer->getRemainingCount(), 'Deck should have 46 cards remaining');
    }

    /**
     * Test dealFlop deals 3 cards
     */
    public function testDealFlop(): void
    {
        require_once __DIR__ . '/../../app/services/game/cards/DealerService.php';
        
        $dealer = new DealerService();
        $dealer->shuffleDeck();
        
        $community = [];
        $dealer->dealFlop($community);
        
        $this->assertCount(3, $community, 'Flop should contain 3 cards');
        $this->assertEquals(49, $dealer->getRemainingCount(), 'Deck should have 49 cards remaining');
        
        // Verify all cards are different
        $uniqueCards = array_unique($community);
        $this->assertCount(3, $uniqueCards, 'All flop cards should be unique');
    }

    /**
     * Test dealTurn deals 1 card
     */
    public function testDealTurn(): void
    {
        require_once __DIR__ . '/../../app/services/game/cards/DealerService.php';
        
        $dealer = new DealerService();
        $dealer->shuffleDeck();
        
        $community = ['AS', 'KS', 'QS']; // Existing flop
        $initialCount = count($community);
        
        $dealer->dealTurn($community);
        
        $this->assertCount($initialCount + 1, $community, 'Turn should add 1 card');
        $this->assertEquals(48, $dealer->getRemainingCount(), 'Deck should have 48 cards remaining');
    }

    /**
     * Test dealRiver deals 1 card
     */
    public function testDealRiver(): void
    {
        require_once __DIR__ . '/../../app/services/game/cards/DealerService.php';
        
        $dealer = new DealerService();
        $dealer->shuffleDeck();
        
        $community = ['AS', 'KS', 'QS', 'JS']; // Existing flop + turn
        $initialCount = count($community);
        
        $dealer->dealRiver($community);
        
        $this->assertCount($initialCount + 1, $community, 'River should add 1 card');
        $this->assertEquals(47, $dealer->getRemainingCount(), 'Deck should have 47 cards remaining');
    }

    /**
     * Test complete dealing sequence
     */
    public function testCompleteDealingSequence(): void
    {
        require_once __DIR__ . '/../../app/services/game/cards/DealerService.php';
        
        $dealer = new DealerService(999);
        $dealer->shuffleDeck();
        
        // Deal to 2 players
        $players = [
            ['seat' => 1],
            ['seat' => 2],
        ];
        $dealer->dealHoleCards($players, 2);
        $this->assertEquals(48, $dealer->getRemainingCount());
        
        // Deal flop
        $community = [];
        $dealer->dealFlop($community);
        $this->assertCount(3, $community);
        $this->assertEquals(45, $dealer->getRemainingCount());
        
        // Deal turn
        $dealer->dealTurn($community);
        $this->assertCount(4, $community);
        $this->assertEquals(44, $dealer->getRemainingCount());
        
        // Deal river
        $dealer->dealRiver($community);
        $this->assertCount(5, $community);
        $this->assertEquals(43, $dealer->getRemainingCount());
        
        // Verify all cards are unique
        $allCards = array_merge(
            $players[0]['cards'],
            $players[1]['cards'],
            $community
        );
        $uniqueCards = array_unique($allCards);
        $this->assertCount(9, $uniqueCards, 'All dealt cards should be unique (2+2+5)');
    }

    /**
     * Test card format matches expected pattern
     */
    public function testCardFormat(): void
    {
        require_once __DIR__ . '/../../app/services/game/cards/DealerService.php';
        
        $dealer = new DealerService();
        $dealer->shuffleDeck();
        
        // Deal a few cards and verify format
        for ($i = 0; $i < 10; $i++) {
            $card = $dealer->dealCard();
            $this->assertMatchesRegularExpression('/^[2-9TJQKA][SHDC]$/', $card,
                "Card '{$card}' should match format: rank + suit");
        }
    }

    /**
     * Test that shuffleDeck resets deck index
     */
    public function testShuffleDeckResetsIndex(): void
    {
        require_once __DIR__ . '/../../app/services/game/cards/DealerService.php';
        
        $dealer = new DealerService();
        $dealer->shuffleDeck();
        
        // Deal some cards
        $dealer->dealCard();
        $dealer->dealCard();
        $this->assertEquals(50, $dealer->getRemainingCount());
        
        // Shuffle again
        $dealer->shuffleDeck();
        $this->assertEquals(52, $dealer->getRemainingCount(), 'Shuffling should reset deck to 52 cards');
    }

    /**
     * Test that flop, turn, and river cards are all unique across streets
     */
    public function testCommunityCardsAreGloballyUnique(): void
    {
        require_once __DIR__ . '/../../app/services/game/cards/DealerService.php';

        $dealer = new DealerService(555);
        $dealer->shuffleDeck();

        $community = [];

        // Deal flop
        $dealer->dealFlop($community);
        $this->assertCount(3, $community);

        // Deal turn
        $dealer->dealTurn($community);
        $this->assertCount(4, $community);

        // Deal river
        $dealer->dealRiver($community);
        $this->assertCount(5, $community);

        // Verify all cards are unique
        $unique = array_unique($community);
        $this->assertCount(5, $unique, 'All community cards should be unique across flop/turn/river');
    }
}

