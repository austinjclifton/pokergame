<?php
// backend/app/services/game/rules/GameTypes.php
// -----------------------------------------------------------------------------
// Shared enums for the poker game engine.
// -----------------------------------------------------------------------------

declare(strict_types=1);

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


