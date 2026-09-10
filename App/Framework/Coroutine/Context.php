<?php

declare(strict_types=1);

namespace App\Framework\Coroutine;

/**
 * Request-scoped state. Backed by Swoole's per-coroutine context so values
 * never bleed across concurrent requests. Falls back to no-ops when Swoole
 * is not loaded (e.g. unit tests).
 */
final class Context
{
    /** @var array<string,mixed> CLI/test fallback; HTTP workers use coroutine context. */
    private static array $fallback = [];

    public static function set(string $key, mixed $value): void
    {
        $ctx = self::ctx();
        if ($ctx !== null) {
            $ctx[$key] = $value;
            return;
        }
        self::$fallback[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $ctx = self::ctx();
        return $ctx !== null ? ($ctx[$key] ?? $default) : (self::$fallback[$key] ?? $default);
    }

    public static function has(string $key): bool
    {
        $ctx = self::ctx();
        return $ctx !== null ? isset($ctx[$key]) : isset(self::$fallback[$key]);
    }

    public static function remove(string $key): void
    {
        $ctx = self::ctx();
        if ($ctx !== null && isset($ctx[$key])) {
            unset($ctx[$key]);
            return;
        }
        unset(self::$fallback[$key]);
    }

    public static function clear(): void
    {
        $ctx = self::ctx();
        if ($ctx === null) {
            self::$fallback = [];
            return;
        }
        foreach ($ctx as $key => $_) {
            unset($ctx[$key]);
        }
    }

    private static function ctx(): ?\ArrayAccess
    {
        if (!extension_loaded('swoole') || !class_exists(\Swoole\Coroutine::class)) {
            return null;
        }
        return \Swoole\Coroutine::getContext();
    }
}
