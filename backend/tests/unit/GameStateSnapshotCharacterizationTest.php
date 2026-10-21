<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/services/game/GameState.php';

final class GameStateSnapshotCharacterizationTest extends TestCase
{
    public function testCurrentSnapshotShapeAndRoundTripRemainStable(): void
    {
        $state = new GameState();
        $state->initializePlayers([
            ['seat' => 1, 'stack' => 875, 'user_id' => 101],
            ['seat' => 2, 'stack' => 925, 'user_id' => 202],
        ]);
        $state->phase = Phase::TURN;
        $state->board = ['AS', 'KD', '7C', '2H'];
        $state->pot = 200;
        $state->currentBet = 50;
        $state->dealerSeat = 1;
        $state->smallBlindSeat = 1;
        $state->bigBlindSeat = 2;
        $state->actionSeat = 2;
        $state->lastRaiseSeat = 1;
        $state->lastRaiseAmount = 50;
        $state->handIndex = 4;
        $state->deckSeed = 12345;
        $state->dealer = new DealerService($state->deckSeed);
        $state->dealer->shuffleDeck();
        $state->dealer->skipCards(6);
        $state->handStartingStacks = [1 => 1000, 2 => 1000];
        $state->players[1]->cards = ['AH', 'AD'];
        $state->players[1]->bet = 50;
        $state->players[1]->totalInvested = 125;
        $state->players[1]->contribution = 125;
        $state->players[1]->actedThisStreet = true;

        $snapshot = $state->toArray();

        $this->assertSame([
            'phase', 'board', 'pot', 'currentBet', 'dealerSeat',
            'smallBlindSeat', 'bigBlindSeat', 'smallBlindAmount',
            'bigBlindAmount', 'actionSeat', 'lastRaiseSeat',
            'lastRaiseAmount', 'players', 'lastHandResult', 'handIndex',
            'handStartingStacks', 'deckSeed', 'dealer',
        ], array_keys($snapshot));
        $this->assertSame([
            'seat', 'stack', 'bet', 'folded', 'allIn', 'actedThisStreet',
            'totalInvested', 'contribution', 'user_id', 'cards', 'handRank',
            'handDescription',
        ], array_keys($snapshot['players'][1]));

        $restored = new GameState();
        $restored->restoreFromArray(json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));

        $this->assertSame($snapshot, $restored->toArray());
    }
}
