<?php
// backend/app/services/game/rules/GameTypes.php
// -----------------------------------------------------------------------------
// Shared types, enums, and exceptions for the poker game engine.
// -----------------------------------------------------------------------------

declare(strict_types=1);

/**
 * Exception thrown when game version mismatch is detected
 */
final class GameVersionMismatchException extends \Exception
{
    public function __construct(int $expectedVersion, int $actualVersion, string $message = '')
    {
        $msg = $message ?: "Game version mismatch: expected {$expectedVersion}, got {$actualVersion}";
        parent::__construct($msg);
    }
}

/**
 * Enum for game phases (streets) in Texas Hold'em
 */
enum Phase: string
{
    case PREFLOP = 'preflop';
    case FLOP = 'flop';
    case TURN = 'turn';
    case RIVER = 'river';
    case SHOWDOWN = 'showdown';
}

/**
 * Enum for player actions
 */
enum ActionType: string
{
    case CHECK = 'check';
    case CALL = 'call';
    case FOLD = 'fold';
    case BET = 'bet';
    case RAISE = 'raise';
    case ALLIN = 'allin';
}


