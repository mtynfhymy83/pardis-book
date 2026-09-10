<?php

declare(strict_types=1);

namespace App\Http\Middlewares;

use App\Http\Concerns\ResponseTrait;
use App\Shared\Exceptions\AccessDeniedException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Role-based access check, invoked by the Router when a route declares an
 * $access value. Decodes the Bearer JWT and verifies the `role` claim is in
 * the allowed set.
 *
 * This is intentionally minimal — extend the claim handling to match your
 * token shape (e.g. multiple roles, scopes, levels).
 */
class CheckAccessMiddleware
{
    use ResponseTrait;

    /**
     * @param array<int,string> $allowedRoles
     */
    public function checkAccess(array $allowedRoles, $request = null): bool
    {
        $token = $this->extractToken($request);
        if (!$token) {
            throw new AccessDeniedException('Authentication token is missing.', 401);
        }

        $secret = $_ENV['JWT_SECRET'] ?? '';
        $algo = $_ENV['JWT_ALGO'] ?? 'HS256';
        if ($secret === '') {
            throw new AccessDeniedException('JWT_SECRET is not configured.', 500);
        }

        try {
            $payload = JWT::decode($token, new Key($secret, $algo));
        } catch (\Throwable $e) {
            throw new AccessDeniedException('Invalid or expired token.', 401);
        }

        $role = $payload->role ?? 'guest';
        if (!in_array($role, $allowedRoles, true)) {
            throw new AccessDeniedException('You do not have permission for this action.', 403);
        }

        return true;
    }

    private function extractToken($request): ?string
    {
        if (!is_object($request)) {
            return null;
        }

        $headers = $request->header ?? [];
        $auth = $headers['authorization'] ?? $headers['Authorization'] ?? null;
        if ($auth && preg_match('/Bearer\s+(.*)$/i', (string) $auth, $m)) {
            return trim($m[1]);
        }
        if (isset($headers['token'])) {
            return trim((string) $headers['token']);
        }

        return isset($request->get['token']) ? trim((string) $request->get['token']) : null;
    }
}
