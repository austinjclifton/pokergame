<?php
declare(strict_types=1);

require_once __DIR__ . '/../PlayerState.php';

final class TurnOrder
{
    /** @param array<int, PlayerState> $players */
    public static function nextSeat(array $players, int $startSeat): int
    {
        $seats = self::sortedSeats($players);
        if ($seats === []) {
            return -1;
        }

        $index = array_search($startSeat, $seats, true);
        if ($index === false) {
            return $seats[0];
        }

        return $seats[($index + 1) % count($seats)];
    }

    /** @param array<int, PlayerState> $players */
    public static function nextActiveSeat(array $players, int $startSeat): int
    {
        $seats = self::sortedSeats($players);
        if ($seats === []) {
            return -1;
        }

        $startIndex = array_search($startSeat, $seats, true);
        if ($startIndex === false) {
            return -1;
        }

        $count = count($seats);
        for ($offset = 1; $offset <= $count; $offset++) {
            $seat = $seats[($startIndex + $offset) % $count];
            $player = $players[$seat];

            if (self::isActive($player)) {
                return $seat;
            }
        }

        return -1;
    }

    /** @param array<int, PlayerState> $players */
    public static function normalizeActiveSeat(array $players, int $seat): int
    {
        if ($seat === -1) {
            return -1;
        }

        if (isset($players[$seat]) && self::isActive($players[$seat])) {
            return $seat;
        }

        foreach (self::sortedSeats($players) as $candidate) {
            if (self::isActive($players[$candidate])) {
                return $candidate;
            }
        }

        return -1;
    }

    /** @param array<int, PlayerState> $players
     *  @return list<int>
     */
    private static function sortedSeats(array $players): array
    {
        $seats = array_keys($players);
        sort($seats);
        return $seats;
    }

    private static function isActive(PlayerState $player): bool
    {
        return !$player->folded && !$player->allIn;
    }
}