<?php
declare(strict_types=1);

final class TokenBucketRateLimiter
{
    /**
     * @return array{ts:float,tokens:float}
     */
    public static function seed(float $capacity): array
    {
        return [
            'ts' => self::now(),
            'tokens' => $capacity,
        ];
    }

    /**
     * @param array{ts:float,tokens:float}|null $state
     */
    public static function allow(?array &$state, float $capacity, float $refillPerSecond, ?float $now = null): bool
    {
        if ($capacity < 1.0) {
            throw new InvalidArgumentException('Token bucket capacity must be at least 1');
        }

        if ($refillPerSecond < 0.0) {
            throw new InvalidArgumentException('Token bucket refill rate cannot be negative');
        }

        $currentTime = $now ?? self::now();

        if (
            $state === null
            || !isset($state['ts'], $state['tokens'])
            || !is_numeric($state['ts'])
            || !is_numeric($state['tokens'])
        ) {
            $state = self::seed($capacity);
        }

        $elapsed = max(0.0, $currentTime - (float) $state['ts']);
        $availableTokens = min(
            $capacity,
            (float) $state['tokens'] + ($elapsed * $refillPerSecond)
        );

        $state = [
            'ts' => $currentTime,
            'tokens' => $availableTokens,
        ];

        if ($availableTokens < 1.0) {
            return false;
        }

        $state['tokens'] = $availableTokens - 1.0;
        return true;
    }

    private static function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}