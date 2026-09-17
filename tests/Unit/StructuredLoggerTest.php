<?php

declare(strict_types=1);

use App\Shared\Logging\StructuredLogger;
use PHPUnit\Framework\TestCase;

final class StructuredLoggerTest extends TestCase
{
    public function testSensitiveContextIsRedactedRecursively(): void
    {
        $redacted = StructuredLogger::redact([
            'userId' => 'usr_1',
            'phone' => '+989121234567',
            'nested' => ['refreshToken' => 'secret-value', 'result' => 'ok'],
        ]);

        self::assertSame('usr_1', $redacted['userId']);
        self::assertSame('[REDACTED]', $redacted['phone']);
        self::assertSame('[REDACTED]', $redacted['nested']['refreshToken']);
        self::assertSame('ok', $redacted['nested']['result']);
    }
}
