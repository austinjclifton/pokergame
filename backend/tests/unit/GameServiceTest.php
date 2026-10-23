<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GameService.
 * 
 * Tests the core poker hand state machine including:
 * - Hand initialization
 * - Betting rounds
 * - Phase transitions
 * - Player actions
 * - Hand evaluation at showdown
 * 
 * @covers \GameService
 * @covers \DealerService
 * @covers \HandEvaluator
 */
final class GameServiceTest extends TestCase
{
    private GameService $game;

    protected function setUp(): void
    {
        // Require all dependencies in correct order
        require_once __DIR__ . '/../../app/services/game/rules/GameTypes.php';
        require_once __DIR__ . '/../../app/services/game/cards/DealerService.php';
        require_once __DIR__ . '/../../app/services/game/cards/HandEvaluator.php';
        require_once __DIR__ . '/../../app/services/game/engine/BettingEngine.php';
        require_once __DIR__ . '/../../app/services/game/engine/TurnOrder.php';
        require_once __DIR__ . '/../../app/services/game/GamePersistence.php';
        require_once __DIR__ . '/../../app/services/game/rules/WinnerCalculator.php';
        require_once __DIR__ . '/../../app/services/game/GameService.php';

        $this->game = new GameService(new GamePersistence(new PDO('sqlite::memory:')), 10, 20);
    }

    private function startHandWithPlayers(array $players): array
    {
        $this->game->loadPlayers($players);
        return $this->game->startHand();
    }

    private function act(int $seat, ActionType $action, int $amount = 0): array
    {
        return $this->game->applyAction($seat, $action->value, $amount);
    }

    private function legalActions(int $seat): array
    {
        return $this->game->getLegalActionsForSeat($seat);
    }

    /**
     * Test a complete 2-player hand where both players check down to showdown
     */
    public function testTwoPlayerHandCheckDownToShowdown(): void
    {
        // Start hand with 2 players
        $result = $this->startHandWithPlayers([
            ['seat' => 1, 'stack' => 1000],
            ['seat' => 2, 'stack' => 1000],
        ]);

        $this->assertTrue($result['ok'], 'Hand should start successfully');
        $this->assertArrayHasKey('state', $result);
        
        $state = $result['state'];
        $this->assertEquals('preflop', $state['phase']);
        $this->assertEquals(30, $state['pot']); // SB (10) + BB (20)
        $this->assertEquals(20, $state['currentBet']); // Big blind amount
        
        // Verify blinds were posted
        $this->assertEquals(990, $state['players'][1]['stack']); // Player 1 (SB) lost 10
        $this->assertEquals(980, $state['players'][2]['stack']); // Player 2 (BB) lost 20
        $this->assertEquals(10, $state['players'][1]['bet']);
        $this->assertEquals(20, $state['players'][2]['bet']);
        
        // Verify hole cards were dealt
        $this->assertCount(2, $state['players'][1]['cards']);
        $this->assertCount(2, $state['players'][2]['cards']);
        
        // Player 1 (SB) needs to act first (after BB)
        // Since BB is 20 and SB bet 10, player 1 needs to call 10 more
        $legalActions = $this->legalActions(1);
        $this->assertContains(ActionType::CALL, $legalActions);
        $this->assertContains(ActionType::FOLD, $legalActions);
        $this->assertContains(ActionType::RAISE, $legalActions);
        
        // Player 1 calls
        $result = $this->act(1, ActionType::CALL);
        $this->assertTrue($result['ok']);
        
        $state = $result['state'];
        $this->assertEquals(40, $state['pot']); // 10 + 20 + 10
        $this->assertEquals(980, $state['players'][1]['stack']); // Lost 10 more
        $this->assertEquals(20, $state['players'][1]['bet']); // Now matched BB
        
        // Player 2 (BB) can now check (no bet to call)
        $legalActions = $this->legalActions(2);
        $this->assertContains(ActionType::CHECK, $legalActions);
        
        // Player 2 checks
        $result = $this->act(2, ActionType::CHECK);
        $this->assertTrue($result['ok']);
        
        // Betting round should be complete, flop should be dealt automatically
        $state = $result['state'];
        $this->assertEquals('flop', $state['phase']);
        $this->assertCount(3, $state['board']);
        $this->assertEquals(0, $state['currentBet']); // Reset for new betting round
        
        // Verify bets were reset
        $this->assertEquals(0, $state['players'][1]['bet']);
        $this->assertEquals(0, $state['players'][2]['bet']);
        
        // Player 2 acts first on flop in heads-up play (left of dealer)
        $legalActions = $this->legalActions(2);
        $this->assertContains(ActionType::CHECK, $legalActions);
        $this->assertContains(ActionType::BET, $legalActions);
        
        // Player 2 checks
        $result = $this->act(2, ActionType::CHECK);
        $this->assertTrue($result['ok']);
        
        // Player 1 checks
        $result = $this->act(1, ActionType::CHECK);
        $this->assertTrue($result['ok']);
        
        // Turn should be dealt automatically
        $state = $result['state'];
        $this->assertEquals('turn', $state['phase']);
        $this->assertCount(4, $state['board']);
        
        // Both players check on turn
        $result = $this->act(2, ActionType::CHECK);
        $this->assertTrue($result['ok']);
        
        $result = $this->act(1, ActionType::CHECK);
        $this->assertTrue($result['ok']);
        
        // River should be dealt automatically
        $state = $result['state'];
        $this->assertEquals('river', $state['phase']);
        $this->assertCount(5, $state['board']);
        
        // Both players check on river
        $result = $this->act(2, ActionType::CHECK);
        $this->assertTrue($result['ok']);
        
        $result = $this->act(1, ActionType::CHECK);
        $this->assertTrue($result['ok']);
        
        // Showdown settlement now happens automatically on the final action
        $state = $result['state'];
        $this->assertTrue($result['handEnded']);
        $this->assertEquals('showdown', $state['phase']);
        
        // Verify hands were evaluated
        $finalState = $result['state'];
        $this->assertNotNull($finalState['players'][1]['handRank']);
        $this->assertNotNull($finalState['players'][1]['handDescription']);
        $this->assertNotNull($finalState['players'][2]['handRank']);
        $this->assertNotNull($finalState['players'][2]['handDescription']);
        
        // Verify winners array structure
        $winners = array_map(
            static fn(array $winner): array => [
                'seat' => (int)$winner['seat'],
                'amount' => (int)$winner['amount'],
                'reason' => (string)$winner['handDescription'],
            ],
            $result['summary']['winners']
        );
        $this->assertIsArray($winners);
        $this->assertGreaterThan(0, count($winners));
        
        // Verify winner structure
        foreach ($winners as $winner) {
            $this->assertArrayHasKey('seat', $winner);
            $this->assertArrayHasKey('amount', $winner);
            $this->assertArrayHasKey('reason', $winner);
            $this->assertIsInt($winner['seat']);
            $this->assertIsInt($winner['amount']);
            $this->assertIsString($winner['reason']);
        }
        
        // Total winnings should equal pot
        $totalWinnings = array_sum(array_column($winners, 'amount'));
        $this->assertEquals(40, $totalWinnings); // Pot from preflop
    }

    /**
     * Test that hand cannot start with less than 2 players
     */
    public function testStartHandRequiresAtLeastTwoPlayers(): void
    {
        $result = $this->startHandWithPlayers([
            ['seat' => 1, 'stack' => 1000],
        ]);
        
        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('message', $result);
        $this->assertStringContainsString('2 players', $result['message']);
    }

    /**
     * Test that players cannot act out of turn
     */
    public function testPlayerCannotActOutOfTurn(): void
    {
        $this->startHandWithPlayers([
            ['seat' => 1, 'stack' => 1000],
            ['seat' => 2, 'stack' => 1000],
        ]);
        
        // Try to have player 2 act when it's player 1's turn
        $result = $this->act(2, ActionType::CALL);
        
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('turn', $result['message']);
    }

    /**
     * Test that folded players cannot act
     */
    public function testFoldedPlayerCannotAct(): void
    {
        $this->startHandWithPlayers([
            ['seat' => 1, 'stack' => 1000],
            ['seat' => 2, 'stack' => 1000],
        ]);
        
        // Player 1 folds
        $result = $this->act(1, ActionType::FOLD);
        $this->assertTrue($result['ok']);
        
        // Try to have player 1 act again
        $result = $this->act(1, ActionType::CALL);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('folded', $result['message']);
    }

    /**
     * Test that you cannot check when a bet is required
     */
    public function testCannotCheckWhenBetRequired(): void
    {
        $this->startHandWithPlayers([
            ['seat' => 1, 'stack' => 1000],
            ['seat' => 2, 'stack' => 1000],
        ]);
        
        // Player 1 tries to check when they need to call
        $result = $this->act(1, ActionType::CHECK);
        
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('illegal action', strtolower($result['message']));
    }

    public function test_minimum_reraise_is_enforced_without_mutating_state(): void
    {
        $this->startHandWithPlayers([
            ['seat' => 1, 'stack' => 1000],
            ['seat' => 2, 'stack' => 1000],
        ]);

        $this->assertTrue($this->act(1, ActionType::RAISE, 20)['ok']);
        $before = $this->game->getSnapshot();

        $rejected = $this->act(2, ActionType::RAISE, 19);
        $this->assertFalse($rejected['ok']);
        $this->assertSame('Minimum raise is to 60', $rejected['message']);
        $this->assertSame($before, $this->game->getSnapshot());

        $accepted = $this->act(2, ActionType::RAISE, 20);
        $this->assertTrue($accepted['ok']);
        $this->assertSame(60, $accepted['state']['currentBet']);
        $this->assertSame(60, $accepted['state']['players'][2]['bet']);
    }

    public function test_short_small_blind_does_not_remain_the_action_seat(): void
    {
        $result = $this->startHandWithPlayers([
            ['seat' => 1, 'stack' => 10],
            ['seat' => 2, 'stack' => 100],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['state']['players'][1]['allIn']);
        $this->assertSame(2, $result['state']['actionSeat']);
        $this->assertSame([ActionType::CHECK], $this->legalActions(2));

        $settled = $this->act(2, ActionType::CHECK);
        $this->assertTrue($settled['ok']);
        $this->assertTrue($settled['handEnded'] || $settled['matchEnded']);
        $this->assertSame('showdown', $settled['state']['phase']);
        $this->assertSame(110, array_sum(array_column($settled['state']['players'], 'stack')));
    }

    public function test_blinds_that_put_both_players_all_in_settle_without_an_action(): void
    {
        $result = $this->startHandWithPlayers([
            ['seat' => 1, 'stack' => 10],
            ['seat' => 2, 'stack' => 20],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['handEnded'] || $result['matchEnded']);
        $this->assertSame('showdown', $result['state']['phase']);
        $this->assertSame(-1, $result['state']['actionSeat']);
        $this->assertSame(30, array_sum(array_column($result['state']['players'], 'stack')));
    }

    public function test_fold_with_unequal_blinds_conserves_chips_into_next_hand(): void
    {
        $result = $this->startHandWithPlayers([
            ['seat' => 1, 'stack' => 1000],
            ['seat' => 2, 'stack' => 1000],
        ]);
        $this->assertTrue($result['ok']);

        $fold = $this->act(1, ActionType::FOLD);
        $this->assertTrue($fold['ok']);
        $this->assertTrue($fold['handEnded']);
        $this->assertSame(30, $fold['summary']['pot']);
        $this->assertSame(2000, array_sum(array_column($fold['state']['players'], 'stack')));
        $this->assertSame(990, $fold['state']['players'][1]['stack']);
        $this->assertSame(1010, $fold['state']['players'][2]['stack']);

        $next = $this->game->startHand(12345);
        $this->assertTrue($next['ok']);
        $this->assertSame(2000, $next['state']['pot'] + array_sum(array_column($next['state']['players'], 'stack')));
        $this->assertSame(20, $next['state']['players'][1]['bet']);
        $this->assertSame(10, $next['state']['players'][2]['bet']);
    }

    /**
     * Test betting and raising
     */
    public function testBettingAndRaising(): void
    {
        $this->startHandWithPlayers([
            ['seat' => 1, 'stack' => 1000],
            ['seat' => 2, 'stack' => 1000],
        ]);
        
        // Player 1 calls BB
        $this->act(1, ActionType::CALL);
        
        // Player 2 checks (completing preflop)
        $this->act(2, ActionType::CHECK);
        
        // Now on flop, player 2 acts first and can bet
        $result = $this->act(2, ActionType::BET, 50);
        $this->assertTrue($result['ok']);
        
        $state = $result['state'];
        $this->assertEquals(50, $state['currentBet']);
        $this->assertEquals(50, $state['players'][2]['bet']);
        $this->assertEquals(90, $state['pot']); // 40 from preflop + 50 bet
        
        // Player 1 can call, fold, or raise
        $legalActions = $this->legalActions(1);
        $this->assertContains(ActionType::CALL, $legalActions);
        $this->assertContains(ActionType::FOLD, $legalActions);
        $this->assertContains(ActionType::RAISE, $legalActions);
        
        // Player 1 raises to 100
        $result = $this->act(1, ActionType::RAISE, 50);
        $this->assertTrue($result['ok']);
        
        $state = $result['state'];
        $this->assertEquals(100, $state['currentBet']);
        $this->assertEquals(100, $state['players'][1]['bet']);
    }

    /**
     * Test all-in functionality
     */
    public function testAllIn(): void
    {
        $this->startHandWithPlayers([
            ['seat' => 1, 'stack' => 100],
            ['seat' => 2, 'stack' => 1000],
        ]);
        
        // Player 1 calls BB (10 more)
        $this->act(1, ActionType::CALL);
        
        // Player 2 checks
        $this->act(2, ActionType::CHECK);

        // Player 2 checks on the flop so player 1 can shove
        $result = $this->act(2, ActionType::CHECK);
        $this->assertTrue($result['ok']);
        
        // On flop, player 1 goes all-in
        $result = $this->act(1, ActionType::ALLIN);
        $this->assertTrue($result['ok']);
        
        $state = $result['state'];
        $this->assertTrue($state['players'][1]['allIn']);
        $this->assertEquals(0, $state['players'][1]['stack']);
        $this->assertEquals(80, $state['players'][1]['bet']);
        $this->assertEquals(100, $state['players'][1]['totalInvested']);
    }

    /**
     * Test that flop can only be dealt after preflop
     */
    public function testDealFlopOnlyAfterPreflop(): void
    {
        $result = $this->startHandWithPlayers([
            ['seat' => 1, 'stack' => 1000],
            ['seat' => 2, 'stack' => 1000],
        ]);
        $this->assertTrue($result['ok']);
        
        $state = $this->game->getSnapshot();
        $this->assertSame('preflop', $state['phase']);
        $this->assertCount(0, $state['board']);
        
        $result = $this->act(1, ActionType::CALL);
        $this->assertTrue($result['ok']);
        $this->assertSame('preflop', $result['state']['phase']);
        $this->assertCount(0, $result['state']['board']);
        
        $result = $this->act(2, ActionType::CHECK);
        $this->assertTrue($result['ok']);
        $this->assertSame('flop', $result['state']['phase']);
        $this->assertCount(3, $result['state']['board']);
    }

    /**
     * Test getState returns complete state snapshot
     */
    public function testGetStateReturnsCompleteSnapshot(): void
    {
        $result = $this->startHandWithPlayers([
            ['seat' => 1, 'stack' => 1000],
            ['seat' => 2, 'stack' => 1000],
        ]);
        $this->assertTrue($result['ok']);
        
        $state = $this->game->getSnapshot();
        
        $this->assertArrayHasKey('phase', $state);
        $this->assertArrayHasKey('board', $state);
        $this->assertArrayHasKey('pot', $state);
        $this->assertArrayHasKey('currentBet', $state);
        $this->assertArrayHasKey('actionSeat', $state);
        $this->assertArrayHasKey('dealerSeat', $state);
        $this->assertArrayHasKey('smallBlindSeat', $state);
        $this->assertArrayHasKey('bigBlindSeat', $state);
        $this->assertArrayHasKey('players', $state);
        
        $this->assertIsArray($state['players']);
        $this->assertCount(2, $state['players']);
        
        // Verify player structure
        foreach ($state['players'] as $seat => $player) {
            $this->assertArrayHasKey('seat', $player);
            $this->assertArrayHasKey('stack', $player);
            $this->assertArrayHasKey('bet', $player);
            $this->assertArrayHasKey('folded', $player);
            $this->assertArrayHasKey('allIn', $player);
            $this->assertArrayHasKey('cards', $player);
        }
    }
}

