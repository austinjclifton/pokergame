<?php
declare(strict_types=1);

require_once __DIR__ . '/../GameState.php';
require_once __DIR__ . '/../PlayerState.php';
require_once __DIR__ . '/../rules/GameTypes.php';
require_once __DIR__ . '/../engine/BettingEngine.php';
require_once __DIR__ . '/../engine/TurnOrder.php';

final class PhaseEngine
{
    /**
     * Advance the hand when the current betting round is complete.
     *
     * In heads-up all-in scenarios, remaining streets are dealt immediately
     * before the hand is moved to showdown.
     */
    public static function advance(GameState $state): ?array
    {
        $active = array_filter(
            $state->players,
            static fn(PlayerState $p) => !$p->folded
        );

        if (count($active) === 0) {
            return null;
        }

        $isHeadsUp = (count($active) <= 2);

        $bettingComplete = BettingEngine::isBettingRoundComplete(
            $active,
            $state->actionSeat,
            $state->currentBet,
            $state->lastRaiseSeat
        );

        $someoneAllIn = self::anyPlayerAllIn($active);

        $autoComplete =
            $state->actionSeat === -1 ||
            self::everyoneAllInOrFolded($active);

        if (
            !$bettingComplete &&
            !$autoComplete
        ) {
            return null;
        }

        if ($isHeadsUp && $someoneAllIn) {
            while (true) {
                switch ($state->phase) {
                    case Phase::PREFLOP:
                        self::onFlop($state);
                        break;

                    case Phase::FLOP:
                        self::onTurn($state);
                        break;

                    case Phase::TURN:
                        self::onRiver($state);
                        break;

                    case Phase::RIVER:
                        return self::onShowdown($state);

                    default:
                        return null;
                }

                $active = array_filter(
                    $state->players,
                    static fn(PlayerState $p) => !$p->folded
                );
                if (empty($active)) {
                    return null;
                }
            }
        }

        return match ($state->phase) {
            Phase::PREFLOP => self::onFlop($state),
            Phase::FLOP    => self::onTurn($state),
            Phase::TURN    => self::onRiver($state),
            Phase::RIVER   => self::onShowdown($state),
            default        => null,
        };
    }

    private static function anyPlayerAllIn(array $players): bool
    {
        foreach ($players as $p) {
            if ($p->allIn) {
                return true;
            }
        }
        return false;
    }

    private static function everyoneAllInOrFolded(array $players): bool
    {
        foreach ($players as $p) {
            if (!$p->folded && !$p->allIn) {
                return false;
            }
        }
        return true;
    }

    private static function onFlop(GameState $state): ?array
    {
        self::advanceStreet($state, Phase::FLOP, 3);

        return null;
    }

    private static function onTurn(GameState $state): ?array
    {
        self::advanceStreet($state, Phase::TURN, 1);

        return null;
    }

    private static function onRiver(GameState $state): ?array
    {
        self::advanceStreet($state, Phase::RIVER, 1);

        return null;
    }

    private static function advanceStreet(GameState $state, Phase $nextPhase, int $cardCount): void
    {
        $state->board = array_merge($state->board, $state->dealer->dealCards($cardCount));
        $state->phase = $nextPhase;
        $state->resetBettingRound();

        $openingSeat = TurnOrder::nextActiveSeat($state->players, $state->dealerSeat);
        $state->actionSeat = TurnOrder::normalizeActiveSeat($state->players, $openingSeat);
    }

    private static function onShowdown(GameState $state): array
    {
        $state->phase = Phase::SHOWDOWN;
        $state->actionSeat = -1;

        return [
            'handEnded' => true,
            'reason'    => 'showdown',
        ];
    }
}
