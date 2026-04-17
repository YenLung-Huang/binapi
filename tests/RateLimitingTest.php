<?php

declare(strict_types=1);

namespace Binapi\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests for rate limiting logic.
 */
class RateLimitingTest extends BinapiTestCase
{
    private array $store = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = [];
    }

    public function testFirstRequestIsAllowed(): void
    {
        $result = $this->simulateRateLimitCheck($this->store, '192.168.1.1', 60, 60);
        $this->assertTrue($result);
        $this->assertCount(1, $this->store['192.168.1.1']);
    }

    public function testRequestsWithinLimitAreAllowed(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $result = $this->simulateRateLimitCheck($this->store, '192.168.1.1', 60, 60);
            $this->assertTrue($result, "Request $i should be allowed");
        }
    }

    public function testRequestsExceedingLimitAreBlocked(): void
    {
        // Fill up to limit
        for ($i = 0; $i < 60; $i++) {
            $this->simulateRateLimitCheck($this->store, '192.168.1.1', 60, 60);
        }

        // Next request should be blocked
        $result = $this->simulateRateLimitCheck($this->store, '192.168.1.1', 60, 60);
        $this->assertFalse($result);
    }

    public function testDifferentIpsHaveSeparateLimits(): void
    {
        // Fill up IP1
        for ($i = 0; $i < 60; $i++) {
            $this->simulateRateLimitCheck($this->store, '192.168.1.1', 60, 60);
        }

        // IP2 should still be allowed
        $result = $this->simulateRateLimitCheck($this->store, '192.168.1.2', 60, 60);
        $this->assertTrue($result);
    }

    public function testOldRequestsAreCleanedUp(): void
    {
        // Simulate with very short window
        $this->store['192.168.1.1'] = [time() - 120]; // 2 minutes old

        $result = $this->simulateRateLimitCheck($this->store, '192.168.1.1', 60, 60);
        $this->assertTrue($result);
        $this->assertCount(1, $this->store['192.168.1.1']);
    }

    public function testEmptyStoreInitializesCorrectly(): void
    {
        $this->assertArrayNotHasKey('192.168.1.1', $this->store);
        $result = $this->simulateRateLimitCheck($this->store, '192.168.1.1', 60, 60);
        $this->assertTrue($result);
        $this->assertArrayHasKey('192.168.1.1', $this->store);
    }

    public function testCustomLimitAndWindow(): void
    {
        $customStore = [];

        // Test with limit 5, window 60
        for ($i = 0; $i < 5; $i++) {
            $result = $this->simulateRateLimitCheck($customStore, '10.0.0.1', 5, 60);
            $this->assertTrue($result, "Request $i should be allowed");
        }

        // 6th request should be blocked
        $result = $this->simulateRateLimitCheck($customStore, '10.0.0.1', 5, 60);
        $this->assertFalse($result);
    }
}
