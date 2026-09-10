<?php

declare(strict_types=1);

namespace App\Framework\Core;

use Swoole\Http\Request;
use Swoole\Http\Response;

interface MiddlewareInterface
{
    /**
     * @param callable $next continue the pipeline: $next($request, $response)
     * @return mixed
     */
    public function handle(Request $request, Response $response, callable $next);
}
