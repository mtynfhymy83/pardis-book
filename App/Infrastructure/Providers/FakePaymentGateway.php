<?php
declare(strict_types=1);
namespace App\Infrastructure\Providers;
use App\Domain\Contracts\Providers\PaymentGatewayInterface;
final class FakePaymentGateway implements PaymentGatewayInterface {
    private function guard(): void { if (($_ENV['APP_ENV'] ?? 'development') === 'production' && ($_ENV['ALLOW_FAKE_PROVIDERS'] ?? 'false') !== 'true') throw new \RuntimeException('Fake provider is disabled in production.'); }
    public function create(string $attemptId, int $amount, string $returnUrl): array { $this->guard(); return ['reference'=>'fake_'.$attemptId,'redirectUrl'=>$returnUrl.'?attemptId='.rawurlencode($attemptId).'&status=success']; }
    public function verify(string $reference, int $amount): array { $this->guard(); return ['success'=>str_starts_with($reference,'fake_'),'reference'=>$reference,'amount'=>$amount]; }
}
