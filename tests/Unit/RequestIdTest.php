<?php

declare(strict_types=1);

use App\Shared\Http\RequestId;
use PHPUnit\Framework\TestCase;

final class RequestIdTest extends TestCase
{
    public function testAcceptsSafeIncomingRequestId(): void
    {
        self::assertSame('client_12345678', RequestId::resolve('client_12345678'));
    }

    public function testReplacesUnsafeIncomingRequestId(): void
    {
        $id = RequestId::resolve("bad\nheader");
        self::assertMatchesRegularExpression('/^req_[a-f0-9]{24}$/', $id);
    }
}
