<?php

declare(strict_types=1);

namespace App\Framework\Core;

/**
 * HTTP method enum for validating the request method.
 */
enum Method: string
{
    case GET = 'GET';
    case POST = 'POST';
    case PUT = 'PUT';
    case PATCH = 'PATCH';
    case DELETE = 'DELETE';
    case OPTIONS = 'OPTIONS';
    case HEAD = 'HEAD';

    public static function tryFromRequest(?string $method): ?self
    {
        if ($method === null || $method === '') {
            return null;
        }
        return self::tryFrom(strtoupper($method));
    }
}
