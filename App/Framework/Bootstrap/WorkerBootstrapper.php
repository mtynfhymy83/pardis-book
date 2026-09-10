<?php

declare(strict_types=1);

namespace App\Framework\Bootstrap;

use App\Http\Routers\Router;
use App\Infrastructure\Database\DB;
use App\Infrastructure\Database\PDOPool;
use Swoole\Http\Server as SwooleServer;

/**
 * Per-worker initialization. Each Swoole worker has fully isolated memory, so
 * the router (and any DB pool / cache you add) is built once per worker here.
 */
class WorkerBootstrapper
{
    public function __construct(
        private SwooleServer $swoole,
        private int $workerId,
        private Server $app
    ) {
    }

    public function boot(): void
    {
        try {
            $this->initializeDatabase();
            $this->initializeEvents();
            $this->initializeRouting();
            $this->logWorkerStart();
        } catch (\Throwable $e) {
            error_log("CRITICAL: Worker #{$this->workerId} failed to bootstrap: " . $e->getMessage());
            error_log($e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * Build this worker's PDO connection pool. Skipped (with a log line) when
     * DB_DSN is not set, so the skeleton still boots without a database.
     */
    private function initializeDatabase(): void
    {
        $dsn = $_ENV['DB_DSN'] ?? getenv('DB_DSN') ?: '';
        if ($dsn === '') {
            error_log("Worker #{$this->workerId}: DB_DSN not set — running without a database.");
            return;
        }

        $poolSize = max(1, (int) ($_ENV['DB_POOL_SIZE'] ?? 10));
        DB::init(new PDOPool($poolSize));
        error_log("Worker #{$this->workerId} database pool initialized (size={$poolSize})");
    }

    /**
     * Register event listeners declared in Framework/Config/events.php.
     * Returns early when there are none — the skeleton ships with an empty map.
     */
    private function initializeEvents(): void
    {
        $eventsFile = __DIR__ . '/../Config/events.php';
        if (!file_exists($eventsFile)) {
            return;
        }

        $events = require $eventsFile;
        if (!is_array($events) || $events === []) {
            return;
        }

        // Hook your EventDispatcher here, e.g.:
        // $dispatcher = Container::get(EventDispatcherInterface::class);
        // foreach ($events as $event => $listeners) { ... $dispatcher->listen(...) }
    }

    private function initializeRouting(): void
    {
        $container = Container::getInstance();
        $router = new Router($container);

        $routesFile = __DIR__ . '/../../Http/Routers/routes.php';
        if (!file_exists($routesFile)) {
            throw new \RuntimeException("Routes file not found: {$routesFile}");
        }

        $routesCallback = require $routesFile;
        if (is_callable($routesCallback)) {
            $routesCallback($router);
        }

        $this->app->setWorkerRouter($router);
        $this->app->setReady();

        error_log("Worker #{$this->workerId} routing initialized");
    }

    private function logWorkerStart(): void
    {
        if ($this->workerId !== 0) {
            return;
        }
        $debug = (($_ENV['APP_DEBUG'] ?? '0') === '1') ? 'ON' : 'OFF';
        echo "--------------------------------------------------\n";
        echo "Server started at http://{$this->swoole->host}:{$this->swoole->port}\n";
        echo "  Workers:    " . $this->swoole->setting['worker_num'] . "\n";
        echo "  Debug mode: {$debug}\n";
        echo "--------------------------------------------------\n";
    }
}
