<?php

declare(strict_types=1);

namespace App\Shared\Concurrency;

use App\Shared\Exceptions\ApiException;

final class VersionGuard
{
    public static function assertCurrent(int $expected, int $current, string $resource = 'resource'): void
    {
        if ($expected !== $current) {
            throw new ApiException(
                'RESOURCE_VERSION_CONFLICT',
                'منبع توسط درخواست دیگری تغییر کرده است.',
                409,
                details: ['resource' => $resource, 'expectedVersion' => $expected, 'currentVersion' => $current],
            );
        }
    }

    public static function assertUpdated(int $affectedRows, string $resource = 'resource'): void
    {
        if ($affectedRows !== 1) {
            throw new ApiException(
                'RESOURCE_VERSION_CONFLICT',
                'نسخه منبع منقضی شده است.',
                409,
                details: ['resource' => $resource],
            );
        }
    }
}
