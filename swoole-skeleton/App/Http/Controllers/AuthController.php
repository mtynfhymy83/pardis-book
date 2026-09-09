<?php
declare(strict_types=1); namespace App\Http\Controllers;
use App\Application\Services\AuthService;
final class AuthController extends Controller { public function __construct(private AuthService $auth){} public function requestOtp(array $data):array{return $this->ok($this->auth->request((string)($data['phone']??'')));} public function verifyOtp(array $data):array{return $this->ok($this->auth->verify((string)($data['challengeId']??''),(string)($data['code']??'')));} public function refresh(array $data):array{return $this->ok($this->auth->refresh((string)($data['refreshToken']??'')));} }
