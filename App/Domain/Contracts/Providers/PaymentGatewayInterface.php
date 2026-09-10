<?php
declare(strict_types=1);
namespace App\Domain\Contracts\Providers;
interface PaymentGatewayInterface { public function create(string $attemptId, int $amount, string $returnUrl): array; public function verify(string $reference, int $amount): array; }
