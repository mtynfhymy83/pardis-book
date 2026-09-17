<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Shared\Exceptions\ApiException;

/** Redis fixed-window limiter using one atomic Lua script and no PHP extension. */
final class DistributedRateLimiter
{
    private const SCRIPT = "local n=redis.call('INCR',KEYS[1]); if n==1 then redis.call('EXPIRE',KEYS[1],ARGV[1]); end; return {n,redis.call('TTL',KEYS[1])}";
    /** @var resource|null */ private static $socket = null;

    public static function assert(string $key, int $limit, int $windowSeconds): void
    {
        try {
            $result = self::command(['EVAL', self::SCRIPT, '1', 'pardis:rate:' . hash('sha256', $key), (string) $windowSeconds]);
            if (!is_array($result) || !isset($result[0], $result[1])) throw new \RuntimeException('Unexpected Redis rate-limit response.');
            if ((int) $result[0] > $limit) throw new ApiException('RATE_LIMITED', 'تعداد درخواست بیش از حد مجاز است.', 429, details: ['retryAfterSeconds' => max(1, (int) $result[1])]);
        } catch (ApiException $exception) { throw $exception;
        } catch (\Throwable $exception) {
            if (($_ENV['APP_ENV'] ?? 'development') === 'production' || ($_ENV['RATE_LIMIT_REDIS_REQUIRED'] ?? 'false') === 'true') throw new ApiException('DEPENDENCY_UNAVAILABLE', 'سرویس محدودسازی درخواست در دسترس نیست.', 503);
            FixedWindowRateLimiter::assert($key, $limit, $windowSeconds);
        }
    }

    private static function command(array $parts): mixed
    {
        if (!is_resource(self::$socket)) self::$socket = self::connect();
        $payload = '*' . count($parts) . "\r\n"; foreach ($parts as $part) {$part=(string)$part;$payload.='$'.strlen($part)."\r\n$part\r\n";}
        if (@fwrite(self::$socket, $payload) === false) {self::close();throw new \RuntimeException('Redis write failed.');}
        return self::read();
    }

    /** @return resource */
    private static function connect()
    {
        $host = (string) ($_ENV['REDIS_HOST'] ?? '127.0.0.1'); $port = max(1, (int) ($_ENV['REDIS_PORT'] ?? 6379));
        $socket = @stream_socket_client("tcp://$host:$port", $code, $error, 0.2, STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT);
        if (!is_resource($socket)) throw new \RuntimeException("Redis connection failed: $error ($code)");
        stream_set_timeout($socket, 0, 200000);
        if (!empty($_ENV['REDIS_PASSWORD'])) { self::$socket = $socket; $reply = self::command(['AUTH', (string) $_ENV['REDIS_PASSWORD']]); if ($reply !== 'OK') {self::close();throw new \RuntimeException('Redis authentication failed.');} }
        return $socket;
    }

    private static function read(): mixed
    {
        if (!is_resource(self::$socket) || ($line = fgets(self::$socket)) === false) {self::close();throw new \RuntimeException('Redis read failed.');}
        $type = $line[0] ?? ''; $body = rtrim(substr($line, 1), "\r\n");
        return match ($type) {
            '+' => $body, ':' => (int) $body, '-' => throw new \RuntimeException('Redis error: ' . $body), '$' => self::bulk((int) $body), '*' => self::array((int) $body), default => throw new \RuntimeException('Invalid Redis protocol response.'),
        };
    }
    private static function bulk(int $size): ?string { if ($size < 0) return null; $data = stream_get_contents(self::$socket, $size); fgets(self::$socket); if ($data === false || strlen($data) !== $size) {self::close();throw new \RuntimeException('Incomplete Redis bulk response.');} return $data; }
    private static function array(int $count): array { $out=[]; for($i=0;$i<$count;$i++)$out[]=self::read(); return $out; }
    private static function close(): void { if (is_resource(self::$socket)) fclose(self::$socket); self::$socket = null; }
}
