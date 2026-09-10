<?php
declare(strict_types=1);
namespace App\Domain\Services;
use App\Shared\Exceptions\ApiException;
final class PricingEngine {
    public function quote(array $sku,array $tiers,int $quantity,int $available): array {
        $minimum=(int)$sku['minimum_order_quantity']; if($quantity<$minimum) throw new ApiException('CART_MINIMUM_NOT_MET','تعداد کمتر از حداقل سفارش است.',422,['quantity'=>"حداقل {$minimum} است."],['minimumQuantity'=>$minimum]);
        if($quantity>$available) throw new ApiException('INSUFFICIENT_STOCK','موجودی کافی نیست.',409,details:['availableQuantity'=>$available]);
        $tier=null; foreach($tiers as $candidate){ if($quantity >= (int)$candidate['min_quantity'] && ($candidate['max_quantity']===null || $quantity <= (int)$candidate['max_quantity'])){$tier=$candidate;break;} }
        if(!$tier) throw new ApiException('PRICING_NOT_AVAILABLE','قیمت برای این تعداد تعریف نشده است.',409);
        $unit=(int)$tier['unit_price']; $ref=(int)$sku['reference_unit_price'];
        return ['skuId'=>$sku['public_id'],'quantity'=>$quantity,'minimumQuantity'=>$minimum,'selectedTier'=>['min'=>(int)$tier['min_quantity'],'max'=>$tier['max_quantity']===null?null:(int)$tier['max_quantity'],'unitPrice'=>$unit],'unitPrice'=>$unit,'referenceUnitPrice'=>$ref,'lineSubtotal'=>$unit*$quantity,'lineSaving'=>max(0,($ref-$unit)*$quantity),'maximumPurchasableQuantity'=>$available,'canPurchase'=>true,'pricingVersion'=>'pv_'.$sku['pricing_version']];
    }
}
