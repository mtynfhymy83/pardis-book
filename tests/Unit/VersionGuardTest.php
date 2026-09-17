<?php

declare(strict_types=1);

use App\Shared\Concurrency\VersionGuard;
use App\Shared\Exceptions\ApiException;
use PHPUnit\Framework\TestCase;

final class VersionGuardTest extends TestCase
{
    public function testAcceptsCurrentVersion(): void
    {
        VersionGuard::assertCurrent(4, 4, 'cart');
        self::assertTrue(true);
    }

    public function testRejectsStaleVersion(): void
    {
        try {
            VersionGuard::assertCurrent(3, 4, 'cart');
            self::fail('ApiException was not thrown.');
        } catch (ApiException $exception) {
            self::assertSame('RESOURCE_VERSION_CONFLICT', $exception->errorCode);
            self::assertSame(409, $exception->getStatusCode());
            self::assertSame(4, $exception->details['currentVersion']);
        }
    }
}
