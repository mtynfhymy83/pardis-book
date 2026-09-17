<?php

declare(strict_types=1);

namespace App\Http\Middlewares;

use App\Framework\Exceptions\Handler\ExceptionHandler;
use App\Framework\Core\MiddlewareInterface;
use App\Http\ResponseHelper;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * Catch-all error middleware. Always sits first in the pipeline so any
 * exception escaping the inner middlewares/router becomes a JSON 4xx/5xx
 * instead of a dropped connection.
 */
class ServerErrorMiddleware implements MiddlewareInterface
{
    private ExceptionHandler $exceptionHandler;

    public function __construct(bool $debug = false)
    {
        $this->exceptionHandler = new ExceptionHandler($debug);
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

        $payload = $this->exceptionHandler->handle($e);
        $status = (int) ($payload['__status'] ?? 500);
        unset($payload['__status']);
        (new ResponseHelper($response))->json($payload, $status);
    }
}
