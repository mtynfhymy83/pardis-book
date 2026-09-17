<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Shared\Exceptions\ApiException;

/** Per-worker fallback limiter. Use a shared Redis limiter when scaling across hosts. */
final class FixedWindowRateLimiter
{
    /** @var array<string,array{startedAt:int,count:int}> */
    private static array $windows = [];

    public static function assert(string $key, int $limit, int $windowSeconds): void
    {
        $now = time(); $current = self::$windows[$key] ?? null;
        if ($current === null || $now - $current['startedAt'] >= $windowSeconds) self::$windows[$key] = ['startedAt' => $now, 'count' => 1];
        else { self::$windows[$key]['count']++; if (self::$windows[$key]['count'] > $limit) throw new ApiException('RATE_LIMITED', 'تعداد درخواست بیش از حد مجاز است.', 429, details: ['retryAfterSeconds' => max(1, $windowSeconds - ($now - $current['startedAt']))]); }
    }

    public static function reset(): void { self::$windows = []; }
}
