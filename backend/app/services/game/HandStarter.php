<?php
declare(strict_types=1);

require_once __DIR__ . '/GameState.php';
require_once __DIR__ . '/cards/DealerService.php';
require_once __DIR__ . '/engine/BettingEngine.php';

final class HandStarter
{
    /**
     * Start a new hand:
     *  - Shuffle deck
     *  - Rotate dealer
     *  - Assign blinds
     *  - Reset players
     *  - Deal hole cards
     *  - Post blinds (BettingEngine mutates stack/bet/contribution/totalInvested)
     *  - Set actionSeat to UTG
     */
    public static function startHand(
        GameState $state,
        int $smallBlind,
        int $bigBlind,
        ?int $forcedSeed = null
    ): void {

        // ---------------------------------------------------------
        // 1. Create dealer + shuffle
        // ---------------------------------------------------------
        $state->dealer = new DealerService($forcedSeed);
        $state->dealer->shuffleDeck();

        // Reset global state
        $state->board          = [];
        $state->phase          = Phase::PREFLOP;
        $state->pot            = 0;
        $state->currentBet     = 0;
        $state->lastRaiseSeat  = -1;

        // First raise amount = big blind amount
        $state->lastRaiseAmount = $bigBlind;

        // ---------------------------------------------------------
        // 2. Dealer rotation
        // ---------------------------------------------------------
        if ($state->handIndex === 0) {
            // First hand → lowest seat becomes dealer
            $seats = array_keys($state->players);
            sort($seats);
            $state->dealerSeat = $seats[0];
        } else {
            $state->dealerSeat = self::nextSeat($state->players, $state->dealerSeat);
        }

        // ---------------------------------------------------------
        // 3. Assign blinds
        // ---------------------------------------------------------
        $state->smallBlindSeat = self::nextSeat($state->players, $state->dealerSeat);
        $state->bigBlindSeat   = self::nextSeat($state->players, $state->smallBlindSeat);

        $state->smallBlindAmount = $smallBlind;
        $state->bigBlindAmount   = $bigBlind;

        // ---------------------------------------------------------
        // 4. Reset each player's per-hand state
        // ---------------------------------------------------------
        foreach ($state->players as $p) {
            $p->resetForNewHand();   // resets bet, folded, allIn, actedThisStreet,
                                     // totalInvested, contribution, handRank, etc.
            $p->cards = [];          // clear old hole cards
        }

        // ---------------------------------------------------------
        // 5. Deal hole cards (2 rounds)
        // ---------------------------------------------------------
        foreach ($state->players as $p) {
            $p->cards[] = $state->dealer->dealCard();
        }
        foreach ($state->players as $p) {
            $p->cards[] = $state->dealer->dealCard();
        }

        // ---------------------------------------------------------
        // 6. Post blinds
        //    BettingEngine mutates:
        //      - player->stack
        //      - player->bet
        //      - player->contribution
        //      - player->totalInvested
        // ---------------------------------------------------------
        $blindResult = BettingEngine::postBlinds(
            $state->players,
            $state->smallBlindSeat,
            $state->bigBlindSeat,
            $smallBlind,
            $bigBlind
        );

        $state->pot        = $blindResult['pot'];         // = SB + BB
        $state->currentBet = $blindResult['currentBet']; // = BB amount

        // ---------------------------------------------------------
        // 7. Action starts UTG (first seat after big blind)
        // ---------------------------------------------------------
        $state->actionSeat = self::nextSeat($state->players, $state->bigBlindSeat);

        // ---------------------------------------------------------
        // 8. Increment hand counter
        // ---------------------------------------------------------
        $state->handIndex++;
    }

    /**
     * Get next seat clockwise.
     */
    private static function nextSeat(array $players, int $start): int
    {
        $seats = array_keys($players);
        sort($seats);

        $i = array_search($start, $seats, true);
        if ($i === false) {
            return $seats[0];
        }

        return $seats[($i + 1) % count($seats)];
    }
}
