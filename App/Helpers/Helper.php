<?php

declare(strict_types=1);

/**
 * Global helper functions are autoloaded via composer.json "files".
 * Keep this lean — most logic belongs in services, not global functions.
 */

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return $_ENV[$key] ?? getenv($key) ?: $default;
    }
}
