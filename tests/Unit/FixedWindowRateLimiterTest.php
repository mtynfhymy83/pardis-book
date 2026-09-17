<?php

declare(strict_types=1);

use App\Shared\Exceptions\ApiException;
use App\Shared\Security\FixedWindowRateLimiter;
use PHPUnit\Framework\TestCase;

final class FixedWindowRateLimiterTest extends TestCase
{
    protected function tearDown(): void { FixedWindowRateLimiter::reset(); }

    public function testBlocksRequestsAfterTheConfiguredLimit(): void
    {
        FixedWindowRateLimiter::assert('test-key', 2, 60);
        FixedWindowRateLimiter::assert('test-key', 2, 60);
        try {
            FixedWindowRateLimiter::assert('test-key', 2, 60);
            self::fail('Expected rate limiter to throw.');
        } catch (ApiException $exception) {
            self::assertSame('RATE_LIMITED', $exception->errorCode);
            self::assertSame(429, $exception->getCode());
        }
    }
}
