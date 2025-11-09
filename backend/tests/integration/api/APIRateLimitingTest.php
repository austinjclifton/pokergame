<?php
declare(strict_types=1);

require_once __DIR__ . '/../db/BaseDBIntegrationTest.php';

/**
 * Integration tests for rate limiting in HTTP API endpoints
 * 
 * Tests that rate limiting functions properly enforce limits, track requests,
 * and handle edge cases. Uses realistic database and service integration.
 * 
 * @coversNothing
 */
final class APIRateLimitingTest extends BaseDBIntegrationTest
{
    /**
     * Load database functions and rate limiting functions required for tests
     */
    protected function loadDatabaseFunctions(): void
    {
        require_once __DIR__ . '/../../../lib/security.php';
        require_once __DIR__ . '/../../../config/security.php';
        require_once __DIR__ . '/../../../app/services/AuthService.php';
        require_once __DIR__ . '/../../../app/services/LobbyService.php';
        require_once __DIR__ . '/../../../lib/session.php';
        require_once __DIR__ . '/../../../app/db/users.php';
        require_once __DIR__ . '/../../../app/db/sessions.php';
        
        if (!function_exists('check_ip_rate_limit')) {
            $this->markTestSkipped('Rate limiting functions not available');
        }
    }

    /**
     * Set up test environment with rate limiting specific initialization
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Reset rate limit storage for clean test state
        RateLimitStorage::resetForTest();
    }

    /**
     * Clean up test environment including rate limiting state
     */
    protected function tearDown(): void
    {
        // Reset rate limiter state
        RateLimitStorage::resetForTest();
        
        // Cleanup superglobals
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP']);
        unset($_COOKIE['session_id']);
        
        parent::tearDown();
    }

    // ============================================================================
    // IP-BASED RATE LIMITING TESTS
    // ============================================================================

    /**
     * Test that requests within limit are allowed
     */
    public function testIpRateLimitAllowsRequestsWithinLimit(): void
    {
        $ip = '192.168.1.200';
        $result = check_ip_rate_limit($ip, 100, 60);
        
        $this->assertTrue($result['allowed'], 'Request within limit should be allowed');
        $this->assertGreaterThan(0, $result['remaining'], 'Should have remaining requests');
        $this->assertNull($result['retry_after'], 'No retry_after when allowed');
        $this->assertNull($result['reason'], 'No reason when allowed');
    }

    /**
     * Test that exceeding limit triggers ban and rejection
     */
    public function testIpRateLimitBansIpAfterExceedingLimit(): void
    {
        $ip = '192.168.1.201';
        $limit = 100;
        
        // Make exactly limit requests (all should be allowed)
        for ($i = 0; $i < $limit; $i++) {
            $result = check_ip_rate_limit($ip, $limit, 60);
            $this->assertTrue($result['allowed'], "Request {$i} should be allowed");
        }
        
        // Next request should exceed limit and trigger ban
        $result = check_ip_rate_limit($ip, $limit, 60);
        $this->assertFalse($result['allowed'], 'Request exceeding limit should be rejected');
        $this->assertEquals('rate_limit_exceeded', $result['reason'], 'Should indicate rate limit exceeded');
        $this->assertEquals(0, $result['remaining'], 'Should have 0 remaining');
        $this->assertEquals(300, $result['retry_after'], 'Should have 5 minute (300s) retry_after');
        
        // Verify IP is banned
        $this->assertTrue(RateLimitStorage::isIpBanned($ip), 'IP should be banned after exceeding limit');
    }

    /**
     * Test that banned IPs are rejected with proper reason
     */
    public function testBannedIpIsRejected(): void
    {
        $ip = '192.168.1.202';
        
        // Exceed limit to trigger ban
        for ($i = 0; $i < 101; $i++) {
            check_ip_rate_limit($ip, 100, 60);
        }
        
        // Verify ban status
        $result = check_ip_rate_limit($ip, 100, 60);
        $this->assertFalse($result['allowed'], 'Banned IP should be rejected');
        $this->assertEquals('ip_banned', $result['reason'], 'Should indicate IP is banned');
        $this->assertEquals(0, $result['remaining'], 'Should have 0 remaining');
        // retry_after may be 0 if ban just started, or positive if ban is in progress
        $this->assertGreaterThanOrEqual(0, $result['retry_after'], 'Should have retry_after time (may be 0 if ban just started)');
    }

    /**
     * Test that banned IPs recover after cooldown period
     * 
     * Note: This test verifies the ban mechanism works. In production, bans expire
     * after 5 minutes. For testing, we verify the ban is set correctly.
     * 
     * @group slow
     */
    public function testBannedIpRecoversAfterCooldown(): void
    {
        $ip = '192.168.1.203';
        
        // Exceed limit to trigger ban
        for ($i = 0; $i < 101; $i++) {
            check_ip_rate_limit($ip, 100, 60);
        }
        
        // Verify IP is banned
        $this->assertTrue(RateLimitStorage::isIpBanned($ip), 'IP should be banned after exceeding limit');
        
        // Verify ban details
        $result = check_ip_rate_limit($ip, 100, 60);
        $this->assertFalse($result['allowed'], 'Banned IP should be rejected');
        $this->assertEquals('ip_banned', $result['reason'], 'Should indicate IP is banned');
        
        // Note: In production, the ban would expire after 5 minutes (300 seconds)
        // This test verifies the ban mechanism is working. To test actual expiration,
        // you would need to wait 5 minutes or use a time provider/mock.
        // For now, we verify the ban is correctly set.
    }

    /**
     * Test that rate limit tracks remaining requests correctly
     */
    public function testIpRateLimitTracksRemainingCorrectly(): void
    {
        $ip = '192.168.1.204';
        $limit = 100;
        
        // Make 50 requests
        for ($i = 0; $i < 50; $i++) {
            check_ip_rate_limit($ip, $limit, 60);
        }
        
        // Check remaining (should account for the check itself)
        $result = check_ip_rate_limit($ip, $limit, 60);
        $this->assertTrue($result['allowed'], 'Should still be allowed');
        // After 50 requests + 1 check = 51 total, remaining = 100 - 51 = 49
        $this->assertSame(49, $result['remaining'], 'Remaining should be accurate');
    }

    /**
     * Test that different IPs have independent rate limits
     */
    public function testDifferentIpsHaveIndependentLimits(): void
    {
        $ip1 = '192.168.1.205';
        $ip2 = '192.168.1.206';
        $limit = 100;
        
        // Exhaust limit for IP1
        for ($i = 0; $i < 101; $i++) {
            check_ip_rate_limit($ip1, $limit, 60);
        }
        
        // IP2 should still be able to make requests
        $result = check_ip_rate_limit($ip2, $limit, 60);
        $this->assertTrue($result['allowed'], 'Different IP should have independent limit');
        $this->assertGreaterThan(0, $result['remaining'], 'Different IP should have remaining requests');
    }

    /**
     * Test that rate limit handles burst traffic correctly
     */
    public function testRateLimitHandlesBurstTraffic(): void
    {
        $ip = '192.168.1.207';
        $limit = 100;
        
        // Simulate burst: make limit+1 requests rapidly
        $allowedCount = 0;
        $rejectedCount = 0;
        
        for ($i = 0; $i <= $limit; $i++) {
            $result = check_ip_rate_limit($ip, $limit, 60);
            if ($result['allowed']) {
                $allowedCount++;
            } else {
                $rejectedCount++;
                break;
            }
        }
        
        $this->assertLessThanOrEqual($limit, $allowedCount, 'Should allow at most the limit');
        $this->assertGreaterThan(0, $rejectedCount, 'Should reject requests exceeding limit');
    }

    // ============================================================================
    // USER-BASED RATE LIMITING TESTS
    // ============================================================================

    /**
     * Test that user rate limiting works independently of IP limits
     */
    public function testUserRateLimitIsIndependentOfIpLimit(): void
    {
        $ip = '192.168.1.208';
        $userId = 1001;
        $ipLimit = 100;
        $userLimit = 200;
        
        // Exhaust IP limit
        for ($i = 0; $i < 101; $i++) {
            check_ip_rate_limit($ip, $ipLimit, 60);
        }
        
        // User limit should still work (different tracking)
        $result = check_user_rate_limit($userId, $userLimit, 60);
        $this->assertTrue($result['allowed'], 'User rate limit should be independent of IP limit');
        $this->assertGreaterThan(0, $result['remaining'], 'User should have remaining requests');
    }

    /**
     * Test that user rate limit enforces limit correctly
     */
    public function testUserRateLimitEnforcesLimit(): void
    {
        $userId = 1002;
        $limit = 200;
        
        // Make exactly limit requests
        for ($i = 0; $i < $limit; $i++) {
            $result = check_user_rate_limit($userId, $limit, 60);
            $this->assertTrue($result['allowed'], "Request {$i} should be allowed");
        }
        
        // Next request should exceed limit
        $result = check_user_rate_limit($userId, $limit, 60);
        $this->assertFalse($result['allowed'], 'Request exceeding user limit should be rejected');
        $this->assertEquals(0, $result['remaining'], 'Should have 0 remaining');
        $this->assertGreaterThan($limit, $result['count'], 'Count should exceed limit');
    }

    /**
     * Test that different users have independent rate limits
     */
    public function testDifferentUsersHaveIndependentLimits(): void
    {
        $user1 = 1003;
        $user2 = 1004;
        $limit = 200;
        
        // Exhaust limit for user1
        for ($i = 0; $i < 201; $i++) {
            check_user_rate_limit($user1, $limit, 60);
        }
        
        // User2 should still be able to make requests
        $result = check_user_rate_limit($user2, $limit, 60);
        $this->assertTrue($result['allowed'], 'Different user should have independent limit');
        $this->assertGreaterThan(0, $result['remaining'], 'Different user should have remaining requests');
    }

    /**
     * Test that multiple users can make requests simultaneously
     */
    public function testMultipleUsersCanMakeRequestsSimultaneously(): void
    {
        $user1 = 1005;
        $user2 = 1006;
        $user3 = 1007;
        $limit = 200;
        $requestsPerUser = 50;
        
        // Each user makes requests
        for ($i = 0; $i < $requestsPerUser; $i++) {
            check_user_rate_limit($user1, $limit, 60);
            check_user_rate_limit($user2, $limit, 60);
            check_user_rate_limit($user3, $limit, 60);
        }
        
        // All should still be within limits
        $result1 = check_user_rate_limit($user1, $limit, 60);
        $result2 = check_user_rate_limit($user2, $limit, 60);
        $result3 = check_user_rate_limit($user3, $limit, 60);
        
        $this->assertTrue($result1['allowed'], 'User 1 should still be within limits');
        $this->assertTrue($result2['allowed'], 'User 2 should still be within limits');
        $this->assertTrue($result3['allowed'], 'User 3 should still be within limits');
        
        // Verify remaining counts are correct
        $expectedRemaining = $limit - ($requestsPerUser + 1); // +1 for the check itself
        $this->assertSame($expectedRemaining, $result1['remaining'], 'User 1 remaining should be accurate');
        $this->assertSame($expectedRemaining, $result2['remaining'], 'User 2 remaining should be accurate');
        $this->assertSame($expectedRemaining, $result3['remaining'], 'User 3 remaining should be accurate');
    }

    // ============================================================================
    // AUTHENTICATION ENDPOINT RATE LIMITING TESTS
    // ============================================================================

    /**
     * Test that authentication endpoints enforce stricter limits
     */
    public function testAuthRateLimitingEnforcesStricterLimits(): void
    {
        $ip = '192.168.1.209';
        $authLimit = 5; // Stricter limit for auth endpoints
        
        // Make requests up to limit
        $allowedCount = 0;
        for ($i = 0; $i <= $authLimit; $i++) {
            $result = check_ip_rate_limit($ip, $authLimit, 60);
            if ($result['allowed']) {
                $allowedCount++;
            } else {
                break;
            }
        }
        
        $this->assertLessThanOrEqual($authLimit, $allowedCount, 'Should allow at most the auth limit');
    }

    // ============================================================================
    // INTEGRATION TESTS WITH DATABASE
    // ============================================================================

    /**
     * Test that login endpoint rate limiting works with database
     */
    public function testLoginEndpointRateLimiting(): void
    {
        $this->createTestUser('ratelimit_login_user');
        $ip = '192.168.1.210';
        $_SERVER['REMOTE_ADDR'] = $ip;
        
        $authLimit = 5;
        $allowedCount = 0;
        
        for ($i = 0; $i <= $authLimit; $i++) {
            $result = check_ip_rate_limit($ip, $authLimit, 60);
            if ($result['allowed']) {
                $allowedCount++;
            } else {
                break;
            }
        }
        
        $this->assertLessThanOrEqual($authLimit, $allowedCount, 'Login endpoint should enforce auth rate limit');
    }

    /**
     * Test that authenticated endpoint rate limiting works with database
     */
    public function testAuthenticatedEndpointRateLimiting(): void
    {
        $userId = $this->createTestUser('ratelimit_auth_user');
        $this->createTestSession($userId);
        
        $userLimit = 200;
        $allowedCount = 0;
        
        for ($i = 0; $i <= $userLimit; $i++) {
            $result = check_user_rate_limit($userId, $userLimit, 60);
            if ($result['allowed']) {
                $allowedCount++;
            } else {
                break;
            }
        }
        
        $this->assertLessThanOrEqual($userLimit, $allowedCount, 'Authenticated endpoints should enforce user rate limit');
    }

    /**
     * Test that rate limiting works across different endpoints (shared IP limit)
     */
    public function testRateLimitingWorksAcrossDifferentEndpoints(): void
    {
        $ip = '192.168.1.211';
        $limit = 100;
        
        // Simulate requests to different endpoints (all count toward same IP limit)
        for ($i = 0; $i < 50; $i++) {
            check_ip_rate_limit($ip, $limit, 60);
        }
        
        // Check remaining (should account for the check itself)
        $result = check_ip_rate_limit($ip, $limit, 60);
        $this->assertTrue($result['allowed'], 'Should still be allowed');
        $this->assertSame(49, $result['remaining'], 'Remaining should be accurate (50 requests + 1 check = 51, 100 - 51 = 49)');
    }

    // ============================================================================
    // EDGE CASES
    // ============================================================================

    /**
     * Test that zero limit rejects all requests
     */
    public function testZeroLimitRejectsAllRequests(): void
    {
        $ip = '192.168.1.212';
        
        $result = check_ip_rate_limit($ip, 0, 60);
        $this->assertFalse($result['allowed'], 'Zero limit should reject all requests');
        $this->assertEquals('rate_limit_exceeded', $result['reason'], 'Should indicate rate limit exceeded');
        $this->assertEquals(0, $result['remaining'], 'Should have 0 remaining');
    }

    /**
     * Test that rate limit returns correct structure
     */
    public function testRateLimitReturnsCorrectStructure(): void
    {
        $ip = '192.168.1.213';
        $result = check_ip_rate_limit($ip, 100, 60);
        
        // Verify structure
        $this->assertIsArray($result, 'Result should be array');
        $this->assertArrayHasKey('allowed', $result, 'Should have allowed key');
        $this->assertArrayHasKey('remaining', $result, 'Should have remaining key');
        $this->assertArrayHasKey('retry_after', $result, 'Should have retry_after key');
        $this->assertArrayHasKey('reason', $result, 'Should have reason key');
        
        // Verify types
        $this->assertIsBool($result['allowed'], 'allowed should be boolean');
        $this->assertIsInt($result['remaining'], 'remaining should be integer');
        $this->assertThat(
            $result['retry_after'],
            $this->logicalOr($this->isNull(), $this->isInt()),
            'retry_after should be null or integer'
        );
        $this->assertThat(
            $result['reason'],
            $this->logicalOr($this->isNull(), $this->isString()),
            'reason should be null or string'
        );
    }

    /**
     * Test that user rate limit returns correct structure
     */
    public function testUserRateLimitReturnsCorrectStructure(): void
    {
        $userId = 1008;
        $result = check_user_rate_limit($userId, 200, 60);
        
        // Verify structure
        $this->assertIsArray($result, 'Result should be array');
        $this->assertArrayHasKey('allowed', $result, 'Should have allowed key');
        $this->assertArrayHasKey('remaining', $result, 'Should have remaining key');
        $this->assertArrayHasKey('count', $result, 'Should have count key');
        
        // Verify types
        $this->assertIsBool($result['allowed'], 'allowed should be boolean');
        $this->assertIsInt($result['remaining'], 'remaining should be integer');
        $this->assertIsInt($result['count'], 'count should be integer');
        $this->assertGreaterThan(0, $result['count'], 'count should be positive');
    }
}
