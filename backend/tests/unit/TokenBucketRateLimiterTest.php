<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TokenBucketRateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../lib/TokenBucketRateLimiter.php';
    }

    public function testSeedStartsWithFullBucket(): void
    {
        $state = TokenBucketRateLimiter::seed(5.0);

        $this->assertSame(5.0, $state['tokens']);
        $this->assertGreaterThan(0.0, $state['ts']);
    }

    public function testAllowConsumesOneTokenWhenBucketHasCapacity(): void
    {
        $state = ['ts' => 10.0, 'tokens' => 2.0];

        $allowed = TokenBucketRateLimiter::allow($state, 2.0, 1.5, 10.1);

        $this->assertTrue($allowed);
        $this->assertSame(10.1, $state['ts']);
        $this->assertSame(1.0, $state['tokens']);
    }

    public function testAllowRejectsWhenBucketIsStillBelowOneToken(): void
    {
        $state = ['ts' => 10.0, 'tokens' => 0.5];

        $allowed = TokenBucketRateLimiter::allow($state, 5.0, 2.0, 10.2);

        $this->assertFalse($allowed);
        $this->assertSame(10.2, $state['ts']);
        $this->assertEqualsWithDelta(0.9, $state['tokens'], 0.0000001);
    }

    public function testAllowRefillsUpToCapacityBeforeConsuming(): void
    {
        $state = ['ts' => 10.0, 'tokens' => 0.0];

        $allowed = TokenBucketRateLimiter::allow($state, 5.0, 10.0, 20.0);

        $this->assertTrue($allowed);
        $this->assertSame(20.0, $state['ts']);
        $this->assertSame(4.0, $state['tokens']);
    }

    public function testAllowSeedsMissingStateAndAllowsFirstRequest(): void
    {
        $state = null;

        $allowed = TokenBucketRateLimiter::allow($state, 3.0, 1.0, 25.0);

        $this->assertTrue($allowed);
        $this->assertSame(25.0, $state['ts']);
        $this->assertSame(2.0, $state['tokens']);
    }
}