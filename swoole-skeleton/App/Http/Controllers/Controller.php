<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Concerns\ResponseTrait;
use App\Shared\Exceptions\AuthenticationException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Swoole\Http\Request;

/**
 * Base controller. Controllers stay thin: read input, call a service, return
 * data. Business logic lives in Domain/Application services.
 */
class Controller
{
    use ResponseTrait;

    /**
     * Decode the JSON (or form) body of a Swoole request.
     */
    protected function getRequestBody(Request $request): array
    {
        $ctype = strtolower($request->header['content-type'] ?? '');
        if (str_contains($ctype, 'application/json')) {
            $raw = $request->rawContent();
            if ($raw === '' || $raw === null) {
                return [];
            }
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return $request->post ?? [];
    }

    protected function getAuthUserId(Request $request): ?int
    {
        $token = $this->extractToken($request);
        if (!$token) {
            return null;
        }

        $secret = $_ENV['JWT_SECRET'] ?? '';
        $algo = $_ENV['JWT_ALGO'] ?? 'HS256';
        if ($secret === '') {
            return null;
        }

        try {
            $payload = JWT::decode($token, new Key($secret, $algo));
        } catch (\Throwable) {
            return null;
        }

        $id = (int) ($payload->id ?? $payload->sub ?? $payload->user_id ?? 0);
        return $id > 0 ? $id : null;
    }

    protected function requireAuthUserId(Request $request): int
    {
        $userId = $this->getAuthUserId($request);
        if (!$userId) {
            throw new AuthenticationException();
        }
        return $userId;
    }

    protected function extractToken(Request $request): ?string
    {
        $headers = $request->header ?? [];
        foreach ($headers as $key => $value) {
            $name = strtolower((string) $key);
            if ($name === 'authorization' && preg_match('/Bearer\s+(.*)$/i', (string) $value, $m)) {
                return trim($m[1]);
            }
            if ($name === 'token') {
                return trim((string) $value);
            }
        }
        return isset($request->get['token']) ? trim((string) $request->get['token']) : null;
    }
}
