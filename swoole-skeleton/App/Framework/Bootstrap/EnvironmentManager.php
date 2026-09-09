<?php

declare(strict_types=1);

namespace App\Framework\Bootstrap;

use Dotenv\Dotenv;

/**
 * Loads the .env file and exposes typed accessors. Call initialize() BEFORE
 * enabling coroutines.
 */
class EnvironmentManager
{
    public static function initialize(): void
    {
        $projectRoot = dirname(__DIR__, 3);
        if (file_exists($projectRoot . '/.env')) {
            Dotenv::createImmutable($projectRoot)->load();
        }

        // Sync every real env var into $_ENV (no hardcoded allowlist).
        foreach (getenv() as $k => $v) {
            $_ENV[$k] ??= $v;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_ENV[$key] ?? getenv($key) ?: $default;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $value = self::get($key);
        return is_numeric($value) ? (int) $value : $default;
    }

    public static function requireKeys(array $keys): void
    {
        $missing = [];
        foreach ($keys as $key) {
            if (!isset($_ENV[$key]) && getenv($key) === false) {
                $missing[] = $key;
            }
        }
        if (!empty($missing)) {
            throw new \RuntimeException('Missing required environment variables: ' . implode(', ', $missing));
        }
    }
}
