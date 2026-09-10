<?php

declare(strict_types=1);

// Disable proxy env before autoload to avoid Swoole curl handler issues
putenv('HTTP_PROXY=');
putenv('HTTPS_PROXY=');
putenv('NO_PROXY=*');

require_once __DIR__ . '/vendor/autoload.php';

use App\Framework\Bootstrap\EnvironmentManager;
use App\Framework\Bootstrap\Server;
use App\Framework\Bootstrap\WorkerBootstrapper;
use App\Http\Middlewares\CorsMiddleware;
use App\Http\Middlewares\RequestContextMiddleware;
use Swoole\Http\Server as SwooleServer;

EnvironmentManager::initialize();

// Hook flags (curl hook can be unstable on some envs; keep it toggleable)
$hookFlags = SWOOLE_HOOK_ALL;
if (!EnvironmentManager::getBool('SWOOLE_ENABLE_CURL_HOOK', false)) {
    if (defined('SWOOLE_HOOK_CURL')) {
        $hookFlags &= ~SWOOLE_HOOK_CURL;
    }
    if (defined('SWOOLE_HOOK_NATIVE_CURL')) {
        $hookFlags &= ~SWOOLE_HOOK_NATIVE_CURL;
    }
}

\Swoole\Runtime::enableCoroutine($hookFlags);

$server = new Server(
    EnvironmentManager::get('APP_HOST', '0.0.0.0'),
    EnvironmentManager::getInt('APP_PORT', 9501)
);

$server->set([
    'worker_num'       => EnvironmentManager::getInt('SWOOLE_WORKER_NUM', 4),
    'enable_coroutine' => true,
    'hook_flags'       => $hookFlags,
    'log_file'         => __DIR__ . '/storage/logs/swoole.log',
    'max_request'      => 10000,
    'reload_async'     => true,
    'http_compression' => EnvironmentManager::getBool('SWOOLE_HTTP_COMPRESSION', true),
]);

$server
    ->debug(EnvironmentManager::getBool('APP_DEBUG', false))
    ->addMiddleware(new RequestContextMiddleware())
    ->addMiddleware(new CorsMiddleware())
    // Register more middlewares here, e.g. new AuthMiddleware()
    ->onWorkerStart(function (SwooleServer $swoole, int $workerId, Server $app) {
        (new WorkerBootstrapper($swoole, $workerId, $app))->boot();
    })
    ->start();
