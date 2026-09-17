<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Services\AuthService;
use Swoole\Http\Request;

final class AuthController extends Controller
{
    public function __construct(private AuthService $auth) {}
    public function requestOtp(array $data): array { return $this->ok($this->auth->request((string)($data['phone']??''))); }
    public function verifyOtp(array $data): array { return $this->ok($this->auth->verify((string)($data['challengeId']??''),(string)($data['code']??''),(string)($data['guestCartToken']??''))); }
    public function refresh(array $data): array { return $this->ok($this->auth->refresh((string)($data['refreshToken']??''))); }
    public function logout(Request $request): array { $p=$this->requireAuthPrincipal($request);$this->auth->logout($p->userId,$p->sessionPublicId);return $this->deleted(); }
    public function logoutAll(Request $request): array { $p=$this->requireAuthPrincipal($request);$this->auth->logoutAll($p->userId);return $this->deleted(); }
    public function sessions(Request $request): array { $p=$this->requireAuthPrincipal($request);return $this->ok($this->auth->sessions($p->userId,$p->sessionPublicId)); }
    public function revokeSession(Request $request,string $sessionId): array { $p=$this->requireAuthPrincipal($request);$this->auth->revokeSession($p->userId,$p->sessionPublicId,$sessionId);return $this->deleted(); }
}
