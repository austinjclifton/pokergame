<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for rate limiting functions in backend/lib/security.php
 * 
 * Tests for:
 * - IP-based rate limiting
 * - User-based rate limiting
 * - IP banning functionality
 * - Rate limit storage
 * - get_client_ip() function
 * 
 * @coversNothing
 */
final class RateLimitingTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../lib/security.php';
        
        // Reset rate limit storage using public method (no reflection needed)
        RateLimitStorage::resetForTest();
    }
    
    protected function tearDown(): void
    {
        // Clean up rate limiter state after each test
        RateLimitStorage::resetForTest();
        
        // Cleanup $_SERVER
        unset($_SERVER['REMOTE_ADDR']);
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        unset($_SERVER['HTTP_X_REAL_IP']);
        unset($_SERVER['HTTP_CLIENT_IP']);
    }

    // ============================================================================
    // GET_CLIENT_IP TESTS
    // ============================================================================

    public function testGetClientIpReturnsRemoteAddrWhenNoProxyHeaders(): void
    {
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        unset($_SERVER['HTTP_X_REAL_IP']);
        unset($_SERVER['HTTP_CLIENT_IP']);
        
        $ip = get_client_ip();
        
        $this->assertSame('192.168.1.100', $ip);
    }

    public function testGetClientIpPrefersXForwardedFor(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.1, 198.51.100.1';
        $_SERVER['HTTP_X_REAL_IP'] = '203.0.113.2';
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
        
        $ip = get_client_ip();
        
        // Should use first IP from X-Forwarded-For
        $this->assertSame('203.0.113.1', $ip);
    }

    public function testGetClientIpFallsBackToXRealIp(): void
    {
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        $_SERVER['HTTP_X_REAL_IP'] = '203.0.113.2';
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
        
        $ip = get_client_ip();
        
        $this->assertSame('203.0.113.2', $ip);
    }

    public function testGetClientIpRejectsPrivateRangesInProxyHeaders(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '192.168.1.100'; // Private range
        $_SERVER['REMOTE_ADDR'] = '203.0.113.1';
        
        $ip = get_client_ip();
        
        // Should fall back to REMOTE_ADDR since private IP was rejected
        $this->assertSame('203.0.113.1', $ip);
    }

    public function testGetClientIpReturnsPlaceholderWhenNoIpAvailable(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        unset($_SERVER['HTTP_X_REAL_IP']);
        unset($_SERVER['HTTP_CLIENT_IP']);
        
        $ip = get_client_ip();
        
        $this->assertSame('0.0.0.0', $ip);
    }

    // ============================================================================
    // IP RATE LIMITING TESTS
    // ============================================================================

    public function testCheckIpRateLimitAllowsRequestsWithinLimit(): void
    {
        $ip = '192.168.1.100';
        
        // Make 5 requests (within limit of 100)
        for ($i = 0; $i < 5; $i++) {
            $result = check_ip_rate_limit($ip, 100, 60);
            $this->assertTrue($result['allowed'], "Request {$i} should be allowed");
            $this->assertGreaterThanOrEqual(95, $result['remaining'], "Remaining should decrease");
        }
    }

    public function testCheckIpRateLimitRejectsRequestsOverLimit(): void
    {
        $ip = '192.168.1.101';
        
        // Make 101 requests (over limit of 100)
        $allowedCount = 0;
        for ($i = 0; $i < 101; $i++) {
            $result = check_ip_rate_limit($ip, 100, 60);
            if ($result['allowed']) {
                $allowedCount++;
            } else {
                $this->assertFalse($result['allowed'], "Request {$i} should be rejected");
                $this->assertSame('rate_limit_exceeded', $result['reason']);
                $this->assertSame(0, $result['remaining']);
                $this->assertNotNull($result['retry_after']);
                break;
            }
        }
        
        $this->assertLessThanOrEqual(100, $allowedCount, 'Should allow at most 100 requests');
    }

    public function testCheckIpRateLimitBansIpAfterExceedingLimit(): void
    {
        $ip = '192.168.1.102';
        
        // Exceed limit to trigger ban
        for ($i = 0; $i < 101; $i++) {
            check_ip_rate_limit($ip, 100, 60);
        }
        
        // Try again - should be banned
        $result = check_ip_rate_limit($ip, 100, 60);
        $this->assertFalse($result['allowed']);
        $this->assertSame('ip_banned', $result['reason']);
        $this->assertNotNull($result['retry_after']);
    }

    public function testCheckIpRateLimitResetsAfterTimeWindow(): void
    {
        $ip = '192.168.1.103';
        
        // Use a very short window (1 second) for testing
        // Make 50 requests
        for ($i = 0; $i < 50; $i++) {
            check_ip_rate_limit($ip, 100, 1);
        }
        
        // Wait for window to expire (1 second + buffer)
        sleep(2);
        
        // Should be able to make requests again (new window)
        $result = check_ip_rate_limit($ip, 100, 1);
        $this->assertTrue($result['allowed'], 'Should allow requests in new time window');
    }

    // ============================================================================
    // USER RATE LIMITING TESTS
    // ============================================================================

    public function testCheckUserRateLimitAllowsRequestsWithinLimit(): void
    {
        $userId = 123;
        
        // Make 50 requests (within limit of 200)
        for ($i = 0; $i < 50; $i++) {
            $result = check_user_rate_limit($userId, 200, 60);
            $this->assertTrue($result['allowed'], "Request {$i} should be allowed");
            $this->assertGreaterThanOrEqual(150, $result['remaining']);
        }
    }

    public function testCheckUserRateLimitRejectsRequestsOverLimit(): void
    {
        $userId = 456;
        
        // Make 201 requests (over limit of 200)
        $allowedCount = 0;
        for ($i = 0; $i < 201; $i++) {
            $result = check_user_rate_limit($userId, 200, 60);
            if ($result['allowed']) {
                $allowedCount++;
            } else {
                $this->assertFalse($result['allowed'], "Request {$i} should be rejected");
                $this->assertSame(0, $result['remaining']);
                break;
            }
        }
        
        $this->assertLessThanOrEqual(200, $allowedCount, 'Should allow at most 200 requests');
    }

    // ============================================================================
    // RATE LIMIT STORAGE TESTS
    // ============================================================================

    public function testRateLimitStorageIncrementsRequestCount(): void
    {
        $key = 'test_ip_123';
        $windowSeconds = 60;
        
        $count1 = RateLimitStorage::incrementRequest($key, $windowSeconds);
        $this->assertSame(1, $count1);
        
        $count2 = RateLimitStorage::incrementRequest($key, $windowSeconds);
        $this->assertSame(2, $count2);
        
        $count3 = RateLimitStorage::incrementRequest($key, $windowSeconds);
        $this->assertSame(3, $count3);
    }

    public function testRateLimitStorageTracksConnections(): void
    {
        $key = 'test_connection_key';
        
        $count1 = RateLimitStorage::incrementConnection($key);
        $this->assertSame(1, $count1);
        
        $count2 = RateLimitStorage::incrementConnection($key);
        $this->assertSame(2, $count2);
        
        $count3 = RateLimitStorage::decrementConnection($key);
        $this->assertSame(1, $count3);
        
        $count4 = RateLimitStorage::decrementConnection($key);
        $this->assertSame(0, $count4);
    }

    public function testRateLimitStorageBansAndChecksIp(): void
    {
        $ip = '192.168.1.200';
        
        // IP should not be banned initially
        $this->assertFalse(RateLimitStorage::isIpBanned($ip));
        
        // Ban IP for 5 minutes
        RateLimitStorage::banIp($ip, 300);
        
        // IP should now be banned
        $this->assertTrue(RateLimitStorage::isIpBanned($ip));
    }

    public function testRateLimitStorageBanExpires(): void
    {
        $ip = '192.168.1.201';
        
        // Ban IP for 1 second
        RateLimitStorage::banIp($ip, 1);
        $this->assertTrue(RateLimitStorage::isIpBanned($ip));
        
        // Wait for ban to expire
        sleep(2);
        
        // IP should no longer be banned
        $this->assertFalse(RateLimitStorage::isIpBanned($ip));
    }

    // ============================================================================
    // EDGE CASES
    // ============================================================================

    public function testCheckIpRateLimitHandlesZeroLimit(): void
    {
        $ip = '192.168.1.104';
        
        // With limit of 0, first request should be rejected
        $result = check_ip_rate_limit($ip, 0, 60);
        $this->assertFalse($result['allowed']);
        $this->assertSame('rate_limit_exceeded', $result['reason']);
    }

    public function testCheckUserRateLimitHandlesDifferentUsers(): void
    {
        $user1 = 100;
        $user2 = 200;
        
        // Make requests as user 1
        for ($i = 0; $i < 50; $i++) {
            check_user_rate_limit($user1, 200, 60);
        }
        
        // Make requests as user 2 (should be independent)
        $result = check_user_rate_limit($user2, 200, 60);
        $this->assertTrue($result['allowed'], 'User 2 should have independent rate limit');
        $this->assertSame(199, $result['remaining'], 'User 2 should have 199 remaining');
    }

    public function testMultipleIpsAreTrackedIndependently(): void
    {
        $ip1 = '192.168.1.105';
        $ip2 = '192.168.1.106';
        
        // Make requests from IP 1
        for ($i = 0; $i < 50; $i++) {
            check_ip_rate_limit($ip1, 100, 60);
        }
        
        // Make requests from IP 2 (should be independent)
        $result = check_ip_rate_limit($ip2, 100, 60);
        $this->assertTrue($result['allowed'], 'IP 2 should have independent rate limit');
        $this->assertSame(99, $result['remaining'], 'IP 2 should have 99 remaining');
    }
}

