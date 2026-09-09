<?php
declare(strict_types=1);
use App\Domain\Services\OrderStateMachine; use App\Shared\Exceptions\ApiException; use PHPUnit\Framework\TestCase;
final class OrderStateMachineTest extends TestCase { public function testValidTransition():void{(new OrderStateMachine())->assert('paid','preparing');self::assertTrue(true);} public function testInvalidTransition():void{$this->expectException(ApiException::class);(new OrderStateMachine())->assert('paid','cancelled');} }
