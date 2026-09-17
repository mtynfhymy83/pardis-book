<?php

declare(strict_types=1);

namespace App\Http\Middlewares;

use App\Framework\Coroutine\Context;
use App\Framework\Core\MiddlewareInterface;
use App\Shared\Logging\StructuredLogger;
use Swoole\Http\Request;
use Swoole\Http\Response;

final class RequestObservabilityMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Response $response, callable $next): mixed
    {
        $startedAt = hrtime(true);
        try {
            return $next($request, $response);
        } finally {
            StructuredLogger::info('http.request.completed', [
                'method' => (string) ($request->server['request_method'] ?? 'GET'),
                'path' => explode('?', (string) ($request->server['request_uri'] ?? '/'), 2)[0],
                'status' => (int) Context::get('response_status', 200),
                'durationMs' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
            ]);
        }
    }
}
