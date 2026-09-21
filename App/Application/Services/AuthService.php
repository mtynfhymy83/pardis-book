<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Infrastructure\Database\DB;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Id;
use Firebase\JWT\JWT;
use PDO;

final class AuthService
{
    private const ADMIN_SESSION_TTL = 2592000;

    public function __construct(private CartService $carts)
    {
    }
    public function request(string $phone): array
    {
        $phone = $this->phone($phone);
        $recent = DB::fetch(
            "SELECT count(*) c FROM otp_challenges WHERE phone=:phone AND created_at>now()-interval '10 minutes'",
            [':phone' => $phone]
        );
        if ((int) $recent['c'] >= 3) {
            throw new ApiException('OTP_RATE_LIMITED', 'تعداد درخواست بیش از حد مجاز است.', 429);
        }

        $id = Id::make('otp');
        $code = (string) random_int(100000, 999999);
        DB::execute(
            "INSERT INTO otp_challenges(public_id,phone,code_hash,expires_at) VALUES(:id,:phone,:hash,now()+interval '2 minutes')",
            [':id' => $id, ':phone' => $phone, ':hash' => password_hash($code, PASSWORD_DEFAULT)]
        );

        $result = [
            'challengeId' => $id,
            'expiresInSeconds' => 120,
            'resendAfterSeconds' => 60,
            'maskedPhone' => substr($phone, 0, 4) . '•••' . substr($phone, -4),
        ];
        if (($_ENV['APP_ENV'] ?? 'development') !== 'production') {
            $result['developmentCode'] = $code;
        }
        return $result;
    }

    public function adminLogin(string $username, string $password): array
    {
        $username = mb_strtolower(trim($username));
        if (preg_match('/^[a-z0-9._-]{3,100}$/', $username) !== 1 || $password === '') {
            throw new ApiException('INVALID_CREDENTIALS', 'نام کاربری یا رمز عبور صحیح نیست.', 401);
        }

        return DB::transaction(function (PDO $pdo) use ($username, $password): array {
            $statement = $pdo->prepare(<<<'SQL'
                SELECT u.*
                FROM users u
                WHERE lower(u.username)=:username
                  AND u.status='active'
                  AND EXISTS (
                    SELECT 1
                    FROM user_roles ur
                    JOIN roles r ON r.id=ur.role_id
                    WHERE ur.user_id=u.id AND r.name<>'customer'
                  )
                FOR UPDATE
                SQL);
            $statement->execute([':username' => $username]);
            $user = $statement->fetch();

            // Always perform a password verification to reduce account enumeration signals.
            $hash = $user && !empty($user['password_hash'])
                ? (string) $user['password_hash']
                : '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
            if (!$user || !password_verify($password, $hash)) {
                throw new ApiException('INVALID_CREDENTIALS', 'نام کاربری یا رمز عبور صحیح نیست.', 401);
            }

            if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                $pdo->prepare('UPDATE users SET password_hash=:hash WHERE id=:id')
                    ->execute([':hash' => password_hash($password, PASSWORD_DEFAULT), ':id' => $user['id']]);
            }
            $pdo->prepare('UPDATE users SET last_login_at=now() WHERE id=:id')->execute([':id' => $user['id']]);

            $refreshTtl = max(86400, (int) ($_ENV['ADMIN_SESSION_TTL'] ?? self::ADMIN_SESSION_TTL));
            $refreshToken = bin2hex(random_bytes(32));
            $sessionId = Id::make('ses');
            $expiresAt = gmdate('Y-m-d H:i:sP', time() + $refreshTtl);
            $pdo->prepare('INSERT INTO sessions(public_id,user_id,refresh_hash,expires_at) VALUES(:session,:user,:hash,:expires)')
                ->execute([
                    ':session' => $sessionId,
                    ':user' => $user['id'],
                    ':hash' => hash('sha256', $refreshToken),
                    ':expires' => $expiresAt,
                ]);

            return [
                'accessToken' => $this->jwt((int) $user['id'], (string) $user['public_id'], $sessionId),
                'refreshToken' => $refreshToken,
                'expiresIn' => $this->accessTokenTtl(),
                'sessionExpiresAt' => gmdate('Y-m-d\TH:i:s\Z', time() + $refreshTtl),
                'user' => [
                    'id' => $user['public_id'],
                    'username' => $user['username'],
                    'phone' => $user['phone'],
                    'name' => $user['name'],
                ],
            ];
        });
    }

    public function verify(string $challenge, string $code, string $guestCartToken = ''): array
    {
        return DB::transaction(function (PDO $pdo) use ($challenge, $code, $guestCartToken): array {
            $statement = $pdo->prepare('SELECT * FROM otp_challenges WHERE public_id=:id FOR UPDATE');
            $statement->execute([':id' => $challenge]);
            $otp = $statement->fetch();
            if (!$otp || $otp['consumed_at'] || strtotime((string) $otp['expires_at']) < time()) {
                throw new ApiException('OTP_EXPIRED', 'کد منقضی شده است.', 422);
            }
            if ((int) $otp['attempts'] >= 5) {
                throw new ApiException('OTP_MAX_ATTEMPTS_REACHED', 'تعداد تلاش بیش از حد است.', 429);
            }
            if (!password_verify($code, (string) $otp['code_hash'])) {
                $pdo->prepare('UPDATE otp_challenges SET attempts=attempts+1 WHERE id=:id')->execute([':id' => $otp['id']]);
                throw new ApiException('OTP_INVALID', 'کد صحیح نیست.', 422);
            }
            $pdo->prepare('UPDATE otp_challenges SET consumed_at=now() WHERE id=:id')->execute([':id' => $otp['id']]);

            $statement = $pdo->prepare('SELECT * FROM users WHERE phone=:phone');
            $statement->execute([':phone' => $otp['phone']]);
            $user = $statement->fetch();
            $isNewUser = false;
            if (!$user) {
                $isNewUser = true;
                $statement = $pdo->prepare('INSERT INTO users(public_id,phone) VALUES(:id,:phone) RETURNING *');
                $statement->execute([':id' => Id::make('usr'), ':phone' => $otp['phone']]);
                $user = $statement->fetch();
            }

            $pdo->prepare("INSERT INTO user_roles(user_id,role_id) SELECT :user,id FROM roles WHERE name='customer' ON CONFLICT DO NOTHING")
                ->execute([':user' => $user['id']]);

            $refreshToken = bin2hex(random_bytes(32));
            $sessionId = Id::make('ses');
            $refreshTtl = max(3600, (int) ($_ENV['REFRESH_TOKEN_TTL'] ?? 2592000));
            $expiresAt = gmdate('Y-m-d H:i:sP', time() + $refreshTtl);
            $pdo->prepare('INSERT INTO sessions(public_id,user_id,refresh_hash,expires_at) VALUES(:session,:user,:hash,:expires)')
                ->execute([
                    ':session' => $sessionId,
                    ':user' => $user['id'],
                    ':hash' => hash('sha256', $refreshToken),
                    ':expires' => $expiresAt,
                ]);

            $cartMerge = $this->carts->mergeGuestIntoUser($pdo, (int) $user['id'], $guestCartToken);
            return [
                'accessToken' => $this->jwt((int) $user['id'], (string) $user['public_id'], $sessionId),
                'refreshToken' => $refreshToken,
                'expiresIn' => $this->accessTokenTtl(),
                'user' => ['id' => $user['public_id'], 'phone' => $user['phone'], 'name' => $user['name']],
                'isNewUser' => $isNewUser,
                'profileCompleted' => !empty($user['name']),
                'cartMerge' => $cartMerge,
            ];
        });
    }

    public function refresh(string $token): array
    {
        return DB::transaction(function (PDO $pdo) use ($token): array {
            $statement = $pdo->prepare(
                'SELECT s.id,s.public_id session_public_id,s.user_id,s.expires_at,s.revoked_at,u.public_id user_public_id FROM sessions s JOIN users u ON u.id=s.user_id WHERE refresh_hash=:hash FOR UPDATE'
            );
            $statement->execute([':hash' => hash('sha256', $token)]);
            $session = $statement->fetch();
            if (!$session || $session['revoked_at'] || strtotime((string) $session['expires_at']) < time()) {
                throw new ApiException('AUTH_SESSION_REVOKED', 'نشست معتبر نیست.', 401);
            }

            $newRefreshToken = bin2hex(random_bytes(32));
            $pdo->prepare('UPDATE sessions SET refresh_hash=:hash,last_used_at=now() WHERE id=:id')
                ->execute([':hash' => hash('sha256', $newRefreshToken), ':id' => $session['id']]);

            return [
                'accessToken' => $this->jwt((int) $session['user_id'], (string) $session['user_public_id'], (string) $session['session_public_id']),
                'refreshToken' => $newRefreshToken,
                'expiresIn' => $this->accessTokenTtl(),
            ];
        });
    }

    public function logout(int $userId, string $sessionId): void
    {
        DB::execute('UPDATE sessions SET revoked_at=now() WHERE user_id=:user AND public_id=:session AND revoked_at IS NULL', [':user'=>$userId, ':session'=>$sessionId]);
    }

    public function logoutAll(int $userId): void
    {
        DB::execute('UPDATE sessions SET revoked_at=now() WHERE user_id=:user AND revoked_at IS NULL', [':user'=>$userId]);
    }

    public function sessions(int $userId, string $currentSessionId): array
    {
        $sessions = DB::fetchAll("SELECT public_id id,created_at AS \"createdAt\",last_used_at AS \"lastUsedAt\",expires_at AS \"expiresAt\",device_id AS \"deviceId\" FROM sessions WHERE user_id=:user AND revoked_at IS NULL AND expires_at>now() ORDER BY last_used_at DESC", [':user'=>$userId]);
        return array_map(static fn(array $session): array => $session + ['isCurrent' => $session['id'] === $currentSessionId], $sessions);
    }

    public function revokeSession(int $userId, string $currentSessionId, string $sessionId): void
    {
        if ($sessionId === $currentSessionId) throw new ApiException('VALIDATION_FAILED','برای نشست جاری از logout استفاده کنید.',422);
        $changed=DB::execute('UPDATE sessions SET revoked_at=now() WHERE user_id=:user AND public_id=:session AND revoked_at IS NULL', [':user'=>$userId, ':session'=>$sessionId]);
        if((int)$changed!==1) throw new ApiException('AUTH_SESSION_REVOKED','نشست یافت نشد.',404);
    }

    private function jwt(int $userId, string $userPublicId, string $sessionPublicId): string
    {
        $now = time();
        return JWT::encode([
            'iss' => 'pardis-api',
            'sub' => $userId,
            'uid' => $userPublicId,
            'sid' => $sessionPublicId,
            'iat' => $now,
            'exp' => $now + $this->accessTokenTtl(),
        ], (string) $_ENV['JWT_SECRET'], (string) ($_ENV['JWT_ALGO'] ?? 'HS256'));
    }

    private function accessTokenTtl(): int
    {
        return max(60, (int) ($_ENV['JWT_TTL'] ?? 900));
    }

    private function phone(string $value): string
    {
        $value = (string) preg_replace('/\D+/', '', $value);
        if (str_starts_with($value, '09')) {
            $value = '98' . substr($value, 1);
        }
        if (preg_match('/^989\d{9}$/', $value) !== 1) {
            throw new ApiException('VALIDATION_FAILED', 'شماره موبایل معتبر نیست.', 422, ['phone' => 'فرمت شماره صحیح نیست.']);
        }
        return '+' . $value;
    }
}
