<?php

declare(strict_types=1);

namespace App\Http\Middlewares;

use App\Framework\Core\MiddlewareInterface;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * Catch-all error middleware. Always sits first in the pipeline so any
 * exception escaping the inner middlewares/router becomes a JSON 4xx/5xx
 * instead of a dropped connection.
 */
class ServerErrorMiddleware implements MiddlewareInterface
{
    public function __construct(private bool $debug = false)
    {
    }

    public function handle(Request $request, Response $response, callable $next): mixed
    {
        try {
            return $next($request, $response);
        } catch (\Throwable $e) {
            $this->sendErrorResponse($response, $e);
            return null;
        }
    }

    private function sendErrorResponse(Response $response, \Throwable $e): void
    {
        if ($response->isWritable() === false) {
            return;
        }

        $code = (int) $e->getCode();
        $status = ($code >= 400 && $code <= 599) ? $code : 500;

        $response->status($status);
        $response->header('Content-Type', 'application/json; charset=utf-8');
        $response->header('Access-Control-Allow-Origin', '*');

        $response->end(json_encode([
            'success' => false,
            'status'  => $status,
            'message' => $this->debug ? $e->getMessage() : ($status === 500 ? 'Internal Server Error' : $e->getMessage()),
            'data'    => $this->debug ? ['trace' => $e->getTraceAsString()] : null,
        ], JSON_UNESCAPED_UNICODE));

        error_log(sprintf('[ServerError] %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
    }
}
