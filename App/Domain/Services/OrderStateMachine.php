<?php
declare(strict_types=1);
namespace App\Domain\Services;
use App\Shared\Exceptions\ApiException;
final class OrderStateMachine { private const MAP=['awaiting_payment'=>['payment_review','paid','cancelled'],'payment_review'=>['paid','cancelled'],'paid'=>['preparing'],'preparing'=>['partially_shipped','shipped'],'partially_shipped'=>['shipped'],'shipped'=>['delivered'],'delivered'=>['completed'],'completed'=>[],'cancelled'=>[],'refunded'=>[]]; public function assert(string $from,string $to): void { if(!in_array($to,self::MAP[$from]??[],true)) throw new ApiException('INVALID_ORDER_TRANSITION','تغییر وضعیت سفارش مجاز نیست.',409,details:['from'=>$from,'to'=>$to]); } }
