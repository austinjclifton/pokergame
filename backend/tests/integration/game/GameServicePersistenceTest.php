<?php
// backend/tests/integration/GameServicePersistenceTest.php
// -----------------------------------------------------------------------------
// Integration tests for GameService - database replay consistency.
// -----------------------------------------------------------------------------

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/PlayerStateHelpers.php';
require_once __DIR__ . '/../helpers/GameServiceStateHelpers.php';
require_once __DIR__ . '/../helpers/GameServiceActionHelpers.php';
require_once __DIR__ . '/../../app/services/game/GameService.php';
require_once __DIR__ . '/../../app/services/game/rules/GameTypes.php';

final class GameServicePersistenceTest extends TestCase
{
    use PlayerStateHelpers;
    use GameServiceStateHelpers;
    use GameServiceActionHelpers;

    /**
     * Scenario 15: Database Replay Consistency
     *
     * We run Game1 normally.
     * Then create a fresh Game2 and replay only the actions (as if loaded from DB).
     * Both states must match.
     */
    public function test_database_replay_consistency(): void
    {
        //
        // ---------- GAME 1: Run the real hand ----------
        //

        $game1 = $this->createGameService([
            ['seat' => 1, 'stack' => 1000],
            ['seat' => 2, 'stack' => 1000],
            ['seat' => 3, 'stack' => 1000],
        ]);

        // Start hand (posts blinds, deals cards, rotates dealer)
        $result = $game1->startHand([
            ['seat' => 1, 'stack' => 1000],
            ['seat' => 2, 'stack' => 1000],
            ['seat' => 3, 'stack' => 1000],
        ]);
        $this->assertTrue($result['ok']);

        // Deterministic card state
        $this->forceCards($game1, 1, ['AS', 'KS']);
        $this->forceCards($game1, 2, ['AD', 'KD']);
        $this->forceCards($game1, 3, ['AC', 'KC']);

        //
        // Run a normal betting sequence
        //
        $this->executeAction($game1, 1, ActionType::CALL);
        $this->executeAction($game1, 2, ActionType::RAISE, 50);
        $this->executeAction($game1, 3, ActionType::CALL);
        $this->executeAction($game1, 1, ActionType::CALL);

        // Finish the betting round and advance to flop
        $this->completeBettingRound($game1);
        $game1->advancePhaseIfNeeded();

        // Capture snapshot
        $finalPhase1   = $this->getPhase($game1);
        $finalPot1     = $this->getPot($game1);
        $finalPlayers1 = $this->getPlayers($game1);
        $finalBoard1   = $this->getBoard($game1);



        //
        // ---------- GAME 2: Replay From Database ----------
        //

        $game2 = $this->createGameService([
            ['seat' => 1, 'stack' => 1000],
            ['seat' => 2, 'stack' => 1000],
            ['seat' => 3, 'stack' => 1000],
        ]);

        // Start hand with identical seat stacks
        $result = $game2->startHand([
            ['seat' => 1, 'stack' => 1000],
            ['seat' => 2, 'stack' => 1000],
            ['seat' => 3, 'stack' => 1000],
        ]);
        $this->assertTrue($result['ok']);

        // Force identical player hole cards
        $this->forceCards($game2, 1, ['AS', 'KS']);
        $this->forceCards($game2, 2, ['AD', 'KD']);
        $this->forceCards($game2, 3, ['AC', 'KC']);

        //
        // Replay exact actions from “database”
        //
        $this->replayActions($game2, [
            ['seat' => 1, 'action' => 'call'],
            ['seat' => 2, 'action' => 'raise', 'amount' => 50],
            ['seat' => 3, 'action' => 'call'],
            ['seat' => 1, 'action' => 'call'],
        ]);

        // Finish the betting round & advance
        $this->completeBettingRound($game2);
        $game2->advancePhaseIfNeeded();



        //
        // ---------- State Comparison ----------
        //

        $this->assertEquals(
            $finalPhase1,
            $this->getPhase($game2),
            'Phase should match after replay'
        );

        $this->assertEquals(
            $finalPot1,
            $this->getPot($game2),
            'Pot should match after replay'
        );

        // Compare investments & stacks
        foreach ($finalPlayers1 as $seat => $p1) {
            $p2 = $this->getPlayers($game2)[$seat] ?? null;
            $this->assertNotNull($p2, "Player $seat must exist in replay");

            $this->assertEquals(
                $p1->stack,
                $p2->stack,
                "Stack mismatch seat $seat"
            );

            $this->assertEquals(
                $p1->totalInvested,
                $p2->totalInvested,
                "totalInvested mismatch seat $seat"
            );
        }
    }
}
