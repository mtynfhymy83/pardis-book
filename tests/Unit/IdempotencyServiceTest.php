<?php

declare(strict_types=1);

use App\Application\Services\IdempotencyService;
use PHPUnit\Framework\TestCase;

final class IdempotencyServiceTest extends TestCase
{
    public function testPayloadHashIsIndependentOfObjectKeyOrder(): void
    {
        $service = new IdempotencyService();
        $first = ['orderId' => 'ord_1', 'options' => ['provider' => 'default', 'returnUrl' => '/done']];
        $second = ['options' => ['returnUrl' => '/done', 'provider' => 'default'], 'orderId' => 'ord_1'];

        self::assertSame($service->hash($first), $service->hash($second));
    }

    public function testListOrderChangesPayloadHash(): void
    {
        $service = new IdempotencyService();
        self::assertNotSame($service->hash(['items' => [1, 2]]), $service->hash(['items' => [2, 1]]));
    }
}
