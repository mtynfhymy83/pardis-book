<?php

declare(strict_types=1);

namespace App\Application\Security;

use App\Framework\Coroutine\Context;
use App\Infrastructure\Database\DB;
use App\Shared\Exceptions\ApiException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Swoole\Http\Request;

final class JwtAuthenticator
{
    public function authenticate(Request $request): AuthenticatedPrincipal
    {
        $cached = Context::get('auth_principal');
        if ($cached instanceof AuthenticatedPrincipal) {
            return $cached;
        }

        $token = $this->extractToken($request);
        if ($token === null) {
            throw new ApiException('AUTHENTICATION_REQUIRED', 'برای این عملیات باید وارد حساب شوید.', 401);
        }

        $secret = (string) ($_ENV['JWT_SECRET'] ?? '');
        if ($secret === '') {
            throw new ApiException('INTERNAL_ERROR', 'تنظیمات احراز هویت کامل نیست.', 500);
        }

        try {
            $payload = JWT::decode($token, new Key($secret, (string) ($_ENV['JWT_ALGO'] ?? 'HS256')));
        } catch (\Throwable) {
            throw new ApiException('AUTH_TOKEN_EXPIRED', 'توکن دسترسی نامعتبر یا منقضی شده است.', 401);
        }

        $userId = (int) ($payload->sub ?? 0);
        $sessionId = (string) ($payload->sid ?? '');
        if (($payload->iss ?? null) !== 'pardis-api' || $userId < 1 || $sessionId === '') {
            throw new ApiException('AUTH_TOKEN_EXPIRED', 'توکن دسترسی معتبر نیست.', 401);
        }

        $session = DB::fetch(
            <<<'SQL'
                SELECT s.public_id session_id, u.id user_id, u.public_id user_public_id
                FROM sessions s
                JOIN users u ON u.id = s.user_id
                WHERE s.public_id = :session AND u.id = :user
                  AND s.revoked_at IS NULL AND s.expires_at > now()
                  AND u.status = 'active'
                SQL,
            [':session' => $sessionId, ':user' => $userId]
        );
        if (!$session) {
            throw new ApiException('AUTH_SESSION_REVOKED', 'نشست کاربری منقضی یا لغو شده است.', 401);
        }

        $roles = array_column(DB::fetchAll(
            'SELECT r.name FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=:user ORDER BY r.name',
            [':user' => $userId]
        ), 'name');
        if ($roles === []) {
            $roles = ['customer'];
        }
        $permissions = array_column(DB::fetchAll(
            'SELECT DISTINCT p.name FROM user_roles ur JOIN role_permissions rp ON rp.role_id=ur.role_id JOIN permissions p ON p.id=rp.permission_id WHERE ur.user_id=:user ORDER BY p.name',
            [':user' => $userId]
        ), 'name');

        $principal = new AuthenticatedPrincipal(
            $userId,
            (string) $session['user_public_id'],
            (string) $session['session_id'],
            array_values($roles),
            array_values($permissions),
        );
        Context::set('auth_principal', $principal);

        return $principal;
    }

    private function extractToken(Request $request): ?string
    {
        $authorization = (string) ($request->header['authorization'] ?? '');
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) !== 1) {
            return null;
        }

        $token = trim($matches[1]);
        return $token === '' ? null : $token;
    }
}
