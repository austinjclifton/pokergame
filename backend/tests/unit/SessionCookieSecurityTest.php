<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/session.php';

/** @coversNothing */
final class SessionCookieSecurityTest extends TestCase
{
    private bool $hadHttps;
    private mixed $originalHttps;

    protected function setUp(): void
    {
        $this->hadHttps = array_key_exists('HTTPS', $_SERVER);
        $this->originalHttps = $_SERVER['HTTPS'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->hadHttps) {
            $_SERVER['HTTPS'] = $this->originalHttps;
        } else {
            unset($_SERVER['HTTPS']);
        }
    }

    public function testCookieIsNotMarkedSecureWhenHttpsIsAbsent(): void
    {
        unset($_SERVER['HTTPS']);

        $this->assertFalse(sessionCookieIsSecure());
    }

    public function testCookieIsNotMarkedSecureWhenHttpsIsExplicitlyOff(): void
    {
        $_SERVER['HTTPS'] = 'off';

        $this->assertFalse(sessionCookieIsSecure());
    }

    public function testCookieIsMarkedSecureForHttpsRequests(): void
    {
        $_SERVER['HTTPS'] = 'on';

        $this->assertTrue(sessionCookieIsSecure());
    }
}
