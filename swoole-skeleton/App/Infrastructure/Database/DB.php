<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use PDO;

/**
 * Static facade over the connection pool.
 *
 * Inside Swoole workers a PDOPool is registered via init(). For CLI scripts and
 * migrations (no coroutine / no pool) a single connection can be registered via
 * initSingle() instead — the helper methods work identically in both modes.
 *
 * Always reach the DB through run()/fetch()/fetchAll()/execute()/transaction():
 * they borrow and return a connection for you, so nothing leaks.
 */
class DB
{
    private static ?PDOPool $pool = null;
    private static ?PDO $single = null;

    public static function init(PDOPool $pool): void
    {
        self::$pool = $pool;
    }

    /** Register a single connection for CLI/migration/test contexts (no pool). */
    public static function initSingle(PDO $pdo): void
    {
        self::$single = $pdo;
    }

    public static function getPoolStats(): ?array
    {
        return self::$pool?->getStats();
    }

    public static function get(): PDO
    {
        if (self::$pool !== null) {
            return self::$pool->get();
        }
        if (self::$single !== null) {
            return self::$single;
        }
        throw new \RuntimeException('Database not initialized! Call DB::init() or DB::initSingle().');
    }

    public static function put(PDO $pdo): void
    {
        // Single-connection mode keeps the connection open; nothing to return.
        self::$pool?->put($pdo);
    }

    /** Run a callback with an automatically borrowed + returned connection. */
    public static function run(callable $callback): mixed
    {
        $pdo = self::get();
        try {
            return $callback($pdo);
        } finally {
            self::put($pdo);
        }
    }

    public static function fetchAll(string $query, array $params = []): array
    {
        return self::run(function (PDO $pdo) use ($query, $params) {
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll();
        });
    }

    public static function fetch(string $query, array $params = []): mixed
    {
        return self::run(function (PDO $pdo) use ($query, $params) {
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            return $stmt->fetch();
        });
    }

    /**
     * INSERT / UPDATE / DELETE.
     * @return int|string affected row count, or last insert id when requested
     */
    public static function execute(string $query, array $params = [], bool $returnLastInsertId = false): int|string
    {
        return self::run(function (PDO $pdo) use ($query, $params, $returnLastInsertId) {
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            return $returnLastInsertId ? $pdo->lastInsertId() : $stmt->rowCount();
        });
    }

    /** Wrap a callback in a transaction with safe rollback. */
    public static function transaction(callable $callback): mixed
    {
        return self::run(function (PDO $pdo) use ($callback) {
            $pdo->beginTransaction();
            try {
                $result = $callback($pdo);
                $pdo->commit();
                return $result;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    try {
                        $pdo->rollBack();
                    } catch (\Throwable $rb) {
                        error_log('Rollback failed: ' . $rb->getMessage());
                    }
                }
                throw $e;
            }
        });
    }

    public static function close(): void
    {
        self::$pool?->close();
        self::$pool = null;
        self::$single = null;
    }
}
