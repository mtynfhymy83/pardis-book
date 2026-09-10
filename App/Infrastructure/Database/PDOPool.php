<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use PDO;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Timer;

/**
 * Coroutine-safe PDO connection pool for Swoole.
 *
 * One pool is built per worker (in WorkerBootstrapper). Connections are handed
 * out via get() and MUST be returned via put() — use DB::run()/DB::fetch()/etc.
 * which do that for you. Driver-agnostic: the DSN comes from DB_DSN.
 *
 * Features:
 *  - lazy (re)connect for empty/dead slots, so a DB blip doesn't kill a worker
 *  - health check on idle connections before reuse
 *  - optional leak detection: warns when a connection is held too long
 */
class PDOPool
{
    private Channel $pool;
    private string $dsn;
    private string $user;
    private string $pass;
    private array $options;

    private int $maxIdleTime;          // seconds
    private float $popTimeoutSeconds;
    private bool $leakDetectionEnabled;
    private int $leakThresholdMs;
    private int $leakCheckIntervalMs;
    private ?int $leakDetectionTimerId = null;

    /** @var array<int, array{borrowed_at: float, cid: int, reported_at: float}> */
    private array $borrowedConnections = [];

    public function __construct(int $size = 10)
    {
        $this->pool = new Channel($size);

        $this->dsn = $this->env('DB_DSN', '');
        if ($this->dsn === '') {
            throw new \RuntimeException('Database config missing. Set DB_DSN in the environment.');
        }
        $this->user = $this->env('DB_USERNAME', '');
        $this->pass = $this->env('DB_PASSWORD', '');

        $this->maxIdleTime          = max(0, (int) $this->env('DB_MAX_IDLE_TIME', '600'));
        $this->popTimeoutSeconds    = max(0.001, $this->envFloat('DB_POOL_POP_TIMEOUT_SECONDS', 1.0));
        $this->leakDetectionEnabled = $this->envBool('DB_POOL_LEAK_DETECTION_ENABLED', true);
        $this->leakThresholdMs      = max(0, (int) $this->env('DB_POOL_LEAK_THRESHOLD_MS', '15000'));
        $this->leakCheckIntervalMs  = max(1000, (int) $this->env('DB_POOL_LEAK_CHECK_INTERVAL_MS', '5000'));

        $this->options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false, // MUST be false under Swoole
        ];

        // Pre-fill the pool. A failed slot is stored as null and lazy-loaded later,
        // so the worker still boots even if the DB is briefly unreachable.
        for ($i = 0; $i < $size; $i++) {
            $connection = null;
            try {
                $connection = $this->makeConnection();
            } catch (\Throwable $e) {
                error_log('Initial DB connection failed: ' . $e->getMessage() . ' — slot will lazy-load.');
            }
            $this->pool->push(['pdo' => $connection, 'last_used' => time()]);
        }

        $this->startLeakDetectionTimer();
    }

    public function get(): PDO
    {
        $pdoData = $this->pool->pop($this->popTimeoutSeconds);

        if ($pdoData === false) {
            $stats = $this->getStats();
            throw new \RuntimeException(sprintf(
                'Database pool exhausted! Capacity: %d, Available: %d, In use: %d.',
                $stats['capacity'], $stats['available'], $stats['in_use']
            ));
        }

        /** @var PDO|null $conn */
        $conn = $pdoData['pdo'] ?? null;
        $lastUsed = (int) ($pdoData['last_used'] ?? 0);
        $isNew = false;

        if (!$conn instanceof PDO) {
            try {
                $conn = $this->makeConnection();
                $isNew = true;
            } catch (\Throwable $e) {
                $this->pool->push(['pdo' => null, 'last_used' => time()]);
                throw new \RuntimeException('Failed to create lazy DB connection: ' . $e->getMessage());
            }
        }

        if (!$isNew && $this->maxIdleTime > 0 && (time() - $lastUsed) > $this->maxIdleTime) {
            if (!$this->isConnectionAlive($conn)) {
                try {
                    $conn = $this->makeConnection();
                } catch (\Throwable $e) {
                    $this->pool->push(['pdo' => null, 'last_used' => time()]);
                    throw new \RuntimeException('Failed to reconnect to database: ' . $e->getMessage());
                }
            }
        }

        $this->trackBorrowedConnection($conn);

        return $conn;
    }

    public function put(PDO $pdo): void
    {
        if (!$this->releaseBorrowedConnection($pdo)) {
            error_log('DB connection returned but was not marked borrowed; ignoring to avoid duplicates.');
            return;
        }

        try {
            if ($pdo->inTransaction()) {
                error_log('Rolling back uncommitted transaction during pool put().');
                $pdo->rollBack();
            }
            $this->pool->push(['pdo' => $pdo, 'last_used' => time()]);
        } catch (\Throwable $e) {
            error_log('Connection broken during cleanup: ' . $e->getMessage() . ' — discarding.');
            $this->pool->push(['pdo' => null, 'last_used' => time()]);
        }
    }

    public function getStats(): array
    {
        return [
            'available' => $this->pool->length(),
            'capacity'  => $this->pool->capacity,
            'in_use'    => $this->pool->capacity - $this->pool->length(),
            'borrowed'  => count($this->borrowedConnections),
        ];
    }

    public function close(): void
    {
        if ($this->leakDetectionTimerId !== null) {
            Timer::clear($this->leakDetectionTimerId);
            $this->leakDetectionTimerId = null;
        }
        $this->pool->close();
    }

    private function makeConnection(): PDO
    {
        return new PDO($this->dsn, $this->user, $this->pass, $this->options);
    }

    private function isConnectionAlive(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function trackBorrowedConnection(PDO $pdo): void
    {
        $this->borrowedConnections[spl_object_id($pdo)] = [
            'borrowed_at' => microtime(true),
            'cid'         => Coroutine::getCid(),
            'reported_at' => 0.0,
        ];
    }

    private function releaseBorrowedConnection(PDO $pdo): bool
    {
        $id = spl_object_id($pdo);
        if (!isset($this->borrowedConnections[$id])) {
            return false;
        }
        unset($this->borrowedConnections[$id]);
        return true;
    }

    private function startLeakDetectionTimer(): void
    {
        if (!$this->leakDetectionEnabled || $this->leakThresholdMs <= 0) {
            return;
        }
        $timerId = Timer::tick($this->leakCheckIntervalMs, function (): void {
            $this->reportLeaks();
        });
        if ($timerId !== false) {
            $this->leakDetectionTimerId = $timerId;
        }
    }

    private function reportLeaks(): void
    {
        if (empty($this->borrowedConnections) || $this->leakThresholdMs <= 0) {
            return;
        }
        $now = microtime(true);
        $threshold = $this->leakThresholdMs / 1000;

        foreach ($this->borrowedConnections as $id => $meta) {
            $held = $now - $meta['borrowed_at'];
            if ($held < $threshold || ($now - $meta['reported_at']) < $threshold) {
                continue;
            }
            $this->borrowedConnections[$id]['reported_at'] = $now;
            error_log(sprintf(
                'Possible DB connection leak. object_id=%d cid=%d held_ms=%d threshold_ms=%d',
                $id, $meta['cid'], (int) round($held * 1000), $this->leakThresholdMs
            ));
        }
    }

    private function env(string $key, string $default = ''): string
    {
        $v = $_ENV[$key] ?? getenv($key);
        return ($v === false || $v === null) ? $default : (string) $v;
    }

    private function envBool(string $key, bool $default = false): bool
    {
        $value = strtolower(trim($this->env($key, $default ? '1' : '0')));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private function envFloat(string $key, float $default): float
    {
        $value = $this->env($key, (string) $default);
        return is_numeric($value) ? (float) $value : $default;
    }
}
