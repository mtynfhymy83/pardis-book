<?php

declare(strict_types=1);

namespace App\Shared\Logging;

use App\Framework\Coroutine\Context;

final class StructuredLogger
{
    private const SENSITIVE_KEY = '/password|secret|token|authorization|cookie|phone|address|postal|payload/i';

    public static function info(string $event, array $context = []): void
    {
        self::write('info', $event, $context);
    }

    public static function error(string $event, array $context = []): void
    {
        self::write('error', $event, $context);
    }

    public static function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match(self::SENSITIVE_KEY, $key) === 1) {
            return '[REDACTED]';
        }
        if (!is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $itemKey => $item) {
            $redacted[$itemKey] = self::redact($item, (string) $itemKey);
        }
        return $redacted;
    }

    private static function write(string $level, string $event, array $context): void
    {
        $record = [
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => $level,
            'event' => $event,
            'requestId' => Context::get('request_id'),
            'context' => self::redact($context),
        ];
        error_log((string) json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
