<?php

declare(strict_types=1);

namespace App\Http\Middlewares;

use App\Framework\Coroutine\Context;
use App\Framework\Core\MiddlewareInterface;
use Swoole\Http\Request;
use Swoole\Http\Response;

final class RequestContextMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Response $response, callable $next): mixed
    {
        $incoming = trim((string) ($request->header['x-request-id'] ?? ''));
        $requestId = preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $incoming)
            ? $incoming
            : 'req_' . bin2hex(random_bytes(12));
        Context::set('request_id', $requestId);
        $response->header('X-Request-Id', $requestId);
        return $next($request, $response);
    }
}
