<?php

declare(strict_types=1);

namespace App\Framework\Bootstrap;

use App\Framework\Coroutine\Context;
use App\Framework\Core\Method;
use App\Framework\Core\Pipeline;
use App\Framework\Core\MiddlewareInterface;
use App\Http\ResponseHelper;
use App\Http\UriParser;
use App\Http\Routers\Router;
use App\Http\Middlewares\ServerErrorMiddleware;
use App\Infrastructure\Database\DB;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server as SwooleServer;

/**
 * Swoole HTTP server wrapper.
 *
 * Responsibilities:
 *  - own the Swoole server lifecycle
 *  - compile the middleware chain + destination once (not per request)
 *  - fast-path /health and /ping
 *  - parse the URI, run the pipeline, hand off to the per-worker Router
 */
class Server
{
    private SwooleServer $http;

    /** user-registered middlewares */
    private array $middlewares = [];

    /** middlewares compiled once, ready to run */
    private array $compiledMiddlewares = [];

    /** @var callable(SwooleServer, int, Server): void|null */
    private $workerStartCallback = null;

    private bool $isReady = false;
    private bool $debug = false;
    private ?int $workerId = null;

    /** router instance owned by this worker */
    private ?Router $workerRouter = null;

    /** cached final destination closure */
    private $destinationCallable = null;

    private string $serviceUnavailablePayload = '';
    private string $methodNotAllowedPayload = '';

    public function __construct(string $host, int $port)
    {
        $this->http = new SwooleServer($host, $port);
    }

    public function set(array $options): self
    {
        $this->http->set($options);
        return $this;
    }

    public function addMiddleware(MiddlewareInterface $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    public function onWorkerStart(callable $callback): self
    {
        $this->workerStartCallback = $callback;
        return $this;
    }

    public function isReady(): bool
    {
        return $this->isReady;
    }

    public function setReady(): void
    {
        $this->isReady = true;
    }

    public function debug(bool $enable = true): self
    {
        $this->debug = $enable;
        return $this;
    }

    public function setWorkerRouter(Router $router): void
    {
        $this->workerRouter = $router;
    }

    public function start(): void
    {
        $this->compileStaticPayloads();
        $this->compileMiddlewares();
        $this->compileDestination();

        $workerStart = $this->workerStartCallback;

        $this->http->on('WorkerStart', function (SwooleServer $server, int $workerId) use ($workerStart) {
            $this->workerId = $workerId;
            if ($workerStart !== null) {
                $workerStart($server, $workerId, $this);
            }
        });

        // Graceful shutdown: close this worker's DB pool.
        $this->http->on('WorkerStop', function () {
            DB::close();
        });

        $this->http->on('Request', function (Request $request, Response $response) {
            if (!$this->isReady) {
                $response->status(503);
                $response->header('Content-Type', 'application/json; charset=utf-8');
                $response->end($this->serviceUnavailablePayload);
                return;
            }
            $this->handleRequest($request, $response);
        });

        $this->http->start();
    }

    private function handleRequest(Request $request, Response $response): void
    {
        Context::clear();
        Context::set('request', $request);
        Context::set('response', $response);
        Context::set('swoole.server', $this->http);

        $uri = $request->server['request_uri'] ?? '/';

        if ($uri === '/favicon.ico') {
            $response->status(404);
            $response->end();
            return;
        }

        $pathOnly = explode('?', $uri, 2)[0];

        // Fast health check before the pipeline.
        if ($pathOnly === '/health' || $pathOnly === '/ping') {
            $response->header('Content-Type', 'application/json; charset=utf-8');
            $response->end(json_encode([
                'status'      => 'ok',
                'timestamp'   => time(),
                'php_version' => PHP_VERSION,
            ]));
            return;
        }

        $method = Method::tryFromRequest($request->server['request_method'] ?? 'GET');
        if ($method === null) {
            $response->status(405);
            $response->header('Content-Type', 'application/json; charset=utf-8');
            $response->end($this->methodNotAllowedPayload);
            return;
        }

        (new Pipeline())
            ->through($this->compiledMiddlewares)
            ->then($request, $response, $this->destinationCallable);
    }

    private function compileStaticPayloads(): void
    {
        $this->serviceUnavailablePayload = json_encode([
            'success' => false, 'status' => 503,
            'message' => 'Service temporarily unavailable (worker is warming up).', 'data' => null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        $this->methodNotAllowedPayload = json_encode([
            'success' => false, 'status' => 405, 'message' => 'Method Not Allowed', 'data' => null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function compileMiddlewares(): void
    {
        // ServerErrorMiddleware always runs first as the catch-all.
        $this->compiledMiddlewares = [
            new ServerErrorMiddleware($this->debug),
            ...$this->middlewares,
        ];
    }

    private function compileDestination(): void
    {
        $this->destinationCallable = function (Request $req, Response $res): void {
            $router = $this->getRouterForRequest();

            $uri = $req->server['request_uri'] ?? '/';
            $pathOnly = explode('?', $uri, 2)[0];
            if (str_contains($pathOnly, '%')) {
                $pathOnly = rawurldecode($pathOnly);
            }

            $parsed = new UriParser($pathOnly);
            $method = $req->server['request_method'] ?? 'GET';

            $result = $router->resolve($parsed->getVersion(), $method, $parsed->getPath(), $req);
            $this->sendResolvedResponse($res, $result);
        };
    }

    private function sendResolvedResponse(Response $response, mixed $result): void
    {
        $helper = new ResponseHelper($response);

        if (is_string($result)) {
            $helper->html($result, 200);
            return;
        }

        if (is_array($result) && !empty($result['__file_download']) && !empty($result['path']) && file_exists($result['path'])) {
            $helper->download(
                $result['path'],
                $result['filename'] ?? basename($result['path']),
                $result['content_type'] ?? 'application/octet-stream',
                !empty($result['__delete_after_send'])
            );
            return;
        }

        if (is_array($result) && isset($result['content_type'], $result['data'])) {
            $helper->content(
                (string) $result['data'],
                (string) $result['content_type'],
                (int) ($result['status'] ?? 200),
                is_array($result['headers'] ?? null) ? $result['headers'] : []
            );
            return;
        }

        $headers = [];
        if (is_array($result) && isset($result['headers']) && is_array($result['headers'])) {
            $headers = $result['headers'];
            unset($result['headers']);
        }

        $status = is_array($result) ? (int) ($result['__status'] ?? 200) : 200;
        if (is_array($result)) {
            unset($result['__status']);
        }
        $payload = is_array($result) && (array_key_exists('data', $result) || isset($result['error']))
            ? $result
            : \App\Shared\Http\ApiResponse::success($result, status: $status);
        unset($payload['__status']);

        $helper->json($payload, $status, $headers);
    }

    private function getRouterForRequest(): Router
    {
        if ($this->workerRouter === null) {
            $router = new Router();
            $routesCallback = require __DIR__ . '/../../Http/Routers/routes.php';
            if (is_callable($routesCallback)) {
                $routesCallback($router);
            }
            $this->workerRouter = $router;
        }
        return $this->workerRouter;
    }
}
