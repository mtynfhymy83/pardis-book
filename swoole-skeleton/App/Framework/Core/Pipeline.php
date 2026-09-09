<?php

declare(strict_types=1);

namespace App\Framework\Core;

use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * Middleware pipeline runner.
 *
 * Uses __invoke + an index instead of nested closures, so NO closure or
 * by-reference variable is ever created per request. This avoids memory
 * leaks under Swoole's long-lived workers.
 */
class Pipeline
{
    private array $middlewares = [];
    private int $index = 0;
    private int $count = 0;

    /** @var callable */
    private $destination;

    public function through(array $middlewares): self
    {
        $this->middlewares = $middlewares;
        $this->count = count($middlewares);
        return $this;
    }

    public function then(Request $request, Response $response, callable $destination): mixed
    {
        $this->destination = $destination;
        $this->index = 0;
        return ($this)($request, $response);
    }

    public function __invoke(Request $req, Response $res): mixed
    {
        // Reached the end → run the final destination (the router).
        if ($this->index >= $this->count) {
            $destination = $this->destination;
            return $destination($req, $res);
        }

        $middleware = $this->middlewares[$this->index++];

        // Pass $this as $next so the middleware can advance the pipeline.
        if ($middleware instanceof MiddlewareInterface) {
            return $middleware->handle($req, $res, $this);
        }

        return ($this)($req, $res);
    }
}
