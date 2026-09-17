<?php

declare(strict_types=1);

namespace App\Shared\Http;

final class RequestId
{
    private const PATTERN = '/^[A-Za-z0-9._:-]{8,100}$/';

    public static function resolve(?string $incoming): string
    {
        $incoming = trim((string) $incoming);
        if ($incoming !== '' && preg_match(self::PATTERN, $incoming) === 1) {
            return $incoming;
        }

        return 'req_' . bin2hex(random_bytes(12));
    }
}
