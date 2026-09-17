<?php

declare(strict_types=1);

namespace App\Http\Middlewares;

use App\Framework\Coroutine\Context;
use App\Framework\Core\MiddlewareInterface;
use App\Shared\Http\RequestId;
use Swoole\Http\Request;
use Swoole\Http\Response;

final class RequestContextMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Response $response, callable $next): mixed
    {
        $requestId = (string) Context::get('request_id', '');
        if ($requestId === '') {
            $requestId = RequestId::resolve($request->header['x-request-id'] ?? null);
        }
        Context::set('request_id', $requestId);
        $response->header('X-Request-Id', $requestId);
        return $next($request, $response);
    }
}
