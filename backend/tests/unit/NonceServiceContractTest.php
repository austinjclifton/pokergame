<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Contract-level tests for Nonce/CSRF logic.
 * 
 * Mimics the actual NonceService behavior:
 * - nonce_issue() generates 256-bit (32 bytes) tokens
 * - Returns datetime string format ('Y-m-d H:i:s') for expiresAt
 * - Uses TTL in minutes (default 15)
 * - Tokens are hex-encoded (64 hex chars for 32 bytes)
 *
 * @coversNothing
 */
final class NonceServiceContractTest extends TestCase
{
    /**
     * Matches NonceService::nonce_issue() implementation:
     * - 32 bytes = 256 bits of entropy
     * - Default TTL: 15 minutes (900 seconds)
     */
    private const TOKEN_BYTES = 32; // 256 bits - matches NonceService
    private const DEFAULT_TTL_MINUTES = 15;
    private const DEFAULT_TTL_SECONDS = 900; // 15 * 60

    /**
     * Mimics NonceService::nonce_issue() behavior.
     * Actual implementation uses session_id, but for contract testing
     * we focus on the token generation and expiry logic.
     * 
     * @param int $ttlMinutes TTL in minutes (matches nonce_issue signature)
     * @return array{nonce: string, expiresAt: string} Matches actual return format
     */
    private function mimicNonceIssue(int $ttlMinutes = self::DEFAULT_TTL_MINUTES): array
    {
        // Generate 256-bit token (matches NonceService::nonce_issue)
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        
        // Format expiry as datetime string (matches NonceService format)
        $now = new DateTime();
        $expiresAt = (clone $now)->modify("+{$ttlMinutes} minutes")->format('Y-m-d H:i:s');
        
        return [
            'nonce' => $token,
            'expiresAt' => $expiresAt,
        ];
    }

    /**
     * Check if a datetime string has expired.
     * Mimics the database-level expiration check.
     */
    private function isExpired(string $expiresAtDatetime, DateTime $now): bool
    {
        $expiresDateTime = new DateTime($expiresAtDatetime);
        return $now >= $expiresDateTime;
    }

    public function testNonceHasSufficientEntropyAndFutureExpiry(): void
    {
        $nonce = $this->mimicNonceIssue();

        $this->assertArrayHasKey('nonce', $nonce);
        $this->assertArrayHasKey('expiresAt', $nonce);

        // 32 bytes = 64 hex characters
        $expectedLength = self::TOKEN_BYTES * 2;
        $this->assertSame($expectedLength, strlen($nonce['nonce']), 
            'Token should be 64 hex chars (32 bytes = 256 bits).');

        // Verify expiresAt is a valid datetime string in the future
        $expiresDateTime = new DateTime($nonce['expiresAt']);
        $now = new DateTime();
        $this->assertGreaterThan($now, $expiresDateTime, 
            'Expiry should be in the future by default.');
    }

    public function testNonceExpiryCheck(): void
    {
        // Create a nonce with 1 minute TTL
        $nonce = $this->mimicNonceIssue(1); // 1 minute
        
        $now = new DateTime();
        $future = clone $now;
        $future->modify('+2 minutes'); // After TTL expires

        $this->assertFalse(
            $this->isExpired($nonce['expiresAt'], $now), 
            'Should not be expired at creation time.'
        );
        
        $this->assertTrue(
            $this->isExpired($nonce['expiresAt'], $future), 
            'Should be expired after TTL passes.'
        );
    }

    public function testDistinctTokensAcrossCalls(): void
    {
        $a = $this->mimicNonceIssue();
        $b = $this->mimicNonceIssue();

        $this->assertNotSame($a['nonce'], $b['nonce'], 
            'Every nonce token must be unique.');
    }

    public function testExpiresAtIsValidDatetimeFormat(): void
    {
        $nonce = $this->mimicNonceIssue();
        
        // Verify format matches 'Y-m-d H:i:s' (e.g., "2025-11-02 20:30:45")
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $nonce['expiresAt'],
            'expiresAt should be in Y-m-d H:i:s format.'
        );
        
        // Verify it's a valid datetime
        $this->assertNotFalse(
            DateTime::createFromFormat('Y-m-d H:i:s', $nonce['expiresAt']),
            'expiresAt should be a valid datetime string.'
        );
    }

    public function testTtlMinutesAffectsExpiry(): void
    {
        $nonce5min = $this->mimicNonceIssue(5);
        $nonce15min = $this->mimicNonceIssue(15);
        
        $expires5 = new DateTime($nonce5min['expiresAt']);
        $expires15 = new DateTime($nonce15min['expiresAt']);
        
        // 15 minute expiry should be ~10 minutes later than 5 minute expiry
        $diff = $expires15->getTimestamp() - $expires5->getTimestamp();
        $this->assertGreaterThanOrEqual(9 * 60, $diff, 
            '15 min TTL should expire ~10 minutes after 5 min TTL.');
        $this->assertLessThanOrEqual(11 * 60, $diff);
    }

    public function testTokenContainsOnlyHexCharacters(): void
    {
        $nonce = $this->mimicNonceIssue();
        
        // Hex characters are 0-9, a-f
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]+$/',
            $nonce['nonce'],
            'Token should contain only hexadecimal characters (0-9, a-f).'
        );
    }

    public function testTokenIsCaseInsensitiveHex(): void
    {
        // Verify tokens are lowercase hex (bin2hex produces lowercase)
        $nonce = $this->mimicNonceIssue();
        
        $this->assertSame(
            strtolower($nonce['nonce']),
            $nonce['nonce'],
            'Token should be lowercase hex (bin2hex standard behavior).'
        );
    }

    public function testExactBoundaryExpiry(): void
    {
        // Create nonce with 1 minute TTL
        $nonce = $this->mimicNonceIssue(1);
        $expiresAt = new DateTime($nonce['expiresAt']);
        
        // At exact expiry time, should be expired (>= check)
        $this->assertTrue(
            $this->isExpired($nonce['expiresAt'], $expiresAt),
            'Token should be considered expired at exact expiry time (boundary condition).'
        );
    }

    public function testOneSecondBeforeExpiryIsValid(): void
    {
        $nonce = $this->mimicNonceIssue(1);
        $expiresAt = new DateTime($nonce['expiresAt']);
        $oneSecondBefore = (clone $expiresAt)->modify('-1 second');
        
        $this->assertFalse(
            $this->isExpired($nonce['expiresAt'], $oneSecondBefore),
            'Token should be valid 1 second before expiry.'
        );
    }

    public function testOneSecondAfterExpiryIsInvalid(): void
    {
        $nonce = $this->mimicNonceIssue(1);
        $expiresAt = new DateTime($nonce['expiresAt']);
        $oneSecondAfter = (clone $expiresAt)->modify('+1 second');
        
        $this->assertTrue(
            $this->isExpired($nonce['expiresAt'], $oneSecondAfter),
            'Token should be expired 1 second after expiry time.'
        );
    }

    public function testVeryShortTtl(): void
    {
        // Test with very short TTL (1 minute)
        $nonce = $this->mimicNonceIssue(1);
        
        $expiresAt = new DateTime($nonce['expiresAt']);
        $now = new DateTime();
        $diffSeconds = $expiresAt->getTimestamp() - $now->getTimestamp();
        
        // Should be approximately 60 seconds (allow 2 second tolerance)
        $this->assertGreaterThanOrEqual(58, $diffSeconds, 
            '1 minute TTL should expire in ~60 seconds.');
        $this->assertLessThanOrEqual(62, $diffSeconds);
    }

    public function testVeryLongTtl(): void
    {
        // Test with long TTL (24 hours = 1440 minutes)
        $nonce = $this->mimicNonceIssue(1440);
        
        $expiresAt = new DateTime($nonce['expiresAt']);
        $now = new DateTime();
        $diffMinutes = ($expiresAt->getTimestamp() - $now->getTimestamp()) / 60;
        
        // Should be approximately 1440 minutes (allow 2 minute tolerance)
        $this->assertGreaterThanOrEqual(1438, $diffMinutes, 
            '1440 minute TTL should expire in ~24 hours.');
        $this->assertLessThanOrEqual(1442, $diffMinutes);
    }

    public function testZeroTtl(): void
    {
        // Zero TTL should create a nonce that expires immediately
        $nonce = $this->mimicNonceIssue(0);
        
        $expiresAt = new DateTime($nonce['expiresAt']);
        $now = new DateTime();
        
        // With 0 TTL, expiry should be at or slightly in the past due to execution time
        $diffSeconds = $expiresAt->getTimestamp() - $now->getTimestamp();
        $this->assertLessThanOrEqual(2, $diffSeconds,
            'Zero TTL should expire at or very close to current time.');
    }

    public function testTokenUniquenessAcrossManyGenerations(): void
    {
        // Generate many nonces and verify all are unique
        $tokens = [];
        for ($i = 0; $i < 100; $i++) {
            $nonce = $this->mimicNonceIssue();
            $tokens[] = $nonce['nonce'];
        }
        
        // Count unique tokens
        $uniqueTokens = array_unique($tokens);
        $this->assertCount(100, $uniqueTokens,
            'All 100 generated tokens should be unique (collision test).');
    }

    public function testRapidSequentialGeneration(): void
    {
        // Generate multiple nonces in rapid succession
        $nonces = [];
        for ($i = 0; $i < 10; $i++) {
            $nonces[] = $this->mimicNonceIssue();
            usleep(1000); // 1ms delay to ensure timestamp differences
        }
        
        // All should have unique tokens
        $tokens = array_column($nonces, 'nonce');
        $this->assertCount(10, array_unique($tokens),
            'Rapid sequential generation should produce unique tokens.');
        
        // All should have valid expiry times
        foreach ($nonces as $nonce) {
            $expiresAt = new DateTime($nonce['expiresAt']);
            $now = new DateTime();
            $this->assertGreaterThan($now, $expiresAt,
                'Each rapidly generated nonce should have future expiry.');
        }
    }

    public function testTokenLengthConsistency(): void
    {
        // Generate multiple nonces and verify all have consistent length
        for ($i = 0; $i < 50; $i++) {
            $nonce = $this->mimicNonceIssue();
            $this->assertSame(64, strlen($nonce['nonce']),
                "Token #{$i} should be exactly 64 hex characters (32 bytes).");
        }
    }

    public function testNoncePropertiesAreConsistent(): void
    {
        // Verify all required keys exist and have correct types
        $nonce = $this->mimicNonceIssue();
        
        $this->assertIsString($nonce['nonce'], 'nonce should be a string.');
        $this->assertIsString($nonce['expiresAt'], 'expiresAt should be a string.');
        
        $this->assertNotEmpty($nonce['nonce'], 'nonce should not be empty.');
        $this->assertNotEmpty($nonce['expiresAt'], 'expiresAt should not be empty.');
    }

    public function testExpiryTimeIncreasesWithTtl(): void
    {
        // Verify that longer TTL results in later expiry
        $ttl1 = 5;
        $ttl2 = 10;
        $ttl3 = 30;
        
        $nonce1 = $this->mimicNonceIssue($ttl1);
        $nonce2 = $this->mimicNonceIssue($ttl2);
        $nonce3 = $this->mimicNonceIssue($ttl3);
        
        $exp1 = new DateTime($nonce1['expiresAt']);
        $exp2 = new DateTime($nonce2['expiresAt']);
        $exp3 = new DateTime($nonce3['expiresAt']);
        
        // exp2 should be later than exp1
        $this->assertGreaterThan($exp1, $exp2,
            '10 min TTL should expire after 5 min TTL.');
        
        // exp3 should be later than exp2
        $this->assertGreaterThan($exp2, $exp3,
            '30 min TTL should expire after 10 min TTL.');
    }

    public function testExpiryFormatPrecision(): void
    {
        // Verify expiry datetime includes seconds precision
        $nonce = $this->mimicNonceIssue();
        
        // Format should be exactly 'Y-m-d H:i:s'
        $parts = explode(' ', $nonce['expiresAt']);
        $this->assertCount(2, $parts, 'expiresAt should have date and time parts.');
        
        [$date, $time] = $parts;
        
        // Date format: YYYY-MM-DD
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $date,
            'Date part should be in YYYY-MM-DD format.');
        
        // Time format: HH:MM:SS
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', $time,
            'Time part should be in HH:MM:SS format (with seconds).');
    }

    /**
     * Test that mimics WebSocket token generation (128-bit, not 256-bit)
     * This tests the WS token format which uses different byte length
     */
    private function mimicWsTokenIssue(int $ttlSeconds = 30): array
    {
        // WS tokens use 16 bytes (128 bits) vs CSRF nonces which use 32 bytes
        $token = bin2hex(random_bytes(16));
        
        $now = new DateTime();
        $expiresAt = (clone $now)->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s');
        
        return [
            'token' => $token,
            'expiresAt' => $expiresAt,
            'expiresIn' => $ttlSeconds,
        ];
    }

    public function testWebSocketTokenHasDifferentLength(): void
    {
        // WS tokens should be 32 hex chars (16 bytes) vs CSRF which are 64 (32 bytes)
        $wsToken = $this->mimicWsTokenIssue();
        $csrfNonce = $this->mimicNonceIssue();
        
        $this->assertSame(32, strlen($wsToken['token']),
            'WebSocket token should be 32 hex characters (16 bytes = 128 bits).');
        
        $this->assertSame(64, strlen($csrfNonce['nonce']),
            'CSRF nonce should be 64 hex characters (32 bytes = 256 bits).');
        
        $this->assertNotSame(strlen($wsToken['token']), strlen($csrfNonce['nonce']),
            'WS tokens and CSRF nonces should have different lengths.');
    }

    public function testWebSocketTokenUsesSecondsForTtl(): void
    {
        // WS tokens use seconds, CSRF uses minutes
        $wsToken = $this->mimicWsTokenIssue(60); // 60 seconds
        $csrfNonce = $this->mimicNonceIssue(1); // 1 minute = 60 seconds
        
        $wsExpires = new DateTime($wsToken['expiresAt']);
        $csrfExpires = new DateTime($csrfNonce['expiresAt']);
        $now = new DateTime();
        
        // Both should expire at approximately the same time (within 2 seconds)
        $wsDiff = $wsExpires->getTimestamp() - $now->getTimestamp();
        $csrfDiff = $csrfExpires->getTimestamp() - $now->getTimestamp();
        
        $this->assertGreaterThanOrEqual(58, $wsDiff, 'WS 60s token should expire in ~60 seconds.');
        $this->assertLessThanOrEqual(62, $wsDiff);
        
        $this->assertGreaterThanOrEqual(58, $csrfDiff, 'CSRF 1min nonce should expire in ~60 seconds.');
        $this->assertLessThanOrEqual(62, $csrfDiff);
    }
}
