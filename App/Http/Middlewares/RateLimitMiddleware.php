<?php

declare(strict_types=1);

namespace App\Http\Middlewares;

use App\Framework\Core\MiddlewareInterface;
use App\Shared\Security\DistributedRateLimiter;
use Swoole\Http\Request;
use Swoole\Http\Response;

final class RateLimitMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Response $response, callable $next): mixed
    {
        if (($_ENV['RATE_LIMIT_ENABLED'] ?? 'true') !== 'true') return $next($request, $response);
        $path = explode('?', (string) ($request->server['request_uri'] ?? '/'), 2)[0];
        $method = strtoupper((string) ($request->server['request_method'] ?? 'GET'));
        $ip = (string) ($request->server['remote_addr'] ?? 'unknown');
        if (($_ENV['TRUST_PROXY_HEADERS'] ?? 'false') === 'true' && !empty($request->header['x-forwarded-for'])) $ip = trim(explode(',', (string) $request->header['x-forwarded-for'])[0]);
        $rule = match (true) {
            $path === '/api/v1/auth/otp/request' => ['otp.request', 10, 600],
            $path === '/api/v1/auth/otp/verify' => ['otp.verify', 10, 600],
            $path === '/api/v1/auth/admin/login' => ['admin.login', 10, 600],
            $path === '/api/v1/search/suggestions' => ['search.suggestions', 60, 60],
            $path === '/api/v1/support/tickets' && $method === 'POST' => ['support.ticket', 10, 86400],
            $path === '/api/v1/payments/attempts' && $method === 'POST' => ['payment.attempt', 20, 600],
            str_starts_with($path, '/api/v1/cart/') && in_array($method, ['POST', 'PATCH', 'DELETE'], true) => ['cart.mutation', 120, 60],
            default => null,
        };
        if ($rule !== null) DistributedRateLimiter::assert($rule[0] . ':' . $ip, $rule[1], $rule[2]);
        return $next($request, $response);
    }
}
