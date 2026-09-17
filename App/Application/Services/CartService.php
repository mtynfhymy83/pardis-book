<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Services\PricingEngine;
use App\Infrastructure\Database\DB;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Id;
use PDO;

final class CartService
{
    public function __construct(private PricingEngine $pricing)
    {
    }

    public function create(): array
    {
        $token = Id::make('gct');
        $cartId = Id::make('cart');
        DB::execute('INSERT INTO carts(public_id,guest_token_hash) VALUES(:id,:token)', [':id' => $cartId, ':token' => hash('sha256', $token)]);
        return ['cartId' => $cartId, 'guestCartToken' => $token, 'version' => 1];
    }

    public function get(?int $userId, string $guestToken): array
    {
        return $this->shape($this->find($userId, $guestToken, true));
    }

    public function add(?int $userId, string $guestToken, string $skuId, int $quantity, ?int $version): array
    {
        if ($quantity < 1) throw new ApiException('VALIDATION_FAILED', 'تعداد باید مثبت باشد.', 422, ['quantity' => 'حداقل ۱ است.']);
        DB::transaction(function (PDO $pdo) use ($userId, $guestToken, $skuId, $quantity, $version): void {
            $cart = $this->findForUpdate($pdo, $userId, $guestToken, true);
            $this->assertVersion($cart, $version);
            $sku = $pdo->prepare("SELECT id FROM skus WHERE public_id=:id AND status<>'archived'"); $sku->execute([':id' => $skuId]); $skuIdInternal = $sku->fetchColumn();
            if (!$skuIdInternal) throw new ApiException('SKU_NOT_FOUND', 'SKU یافت نشد.', 404);
            $pdo->prepare('INSERT INTO cart_items(public_id,cart_id,sku_id,quantity) VALUES(:id,:cart,:sku,:quantity) ON CONFLICT(cart_id,sku_id) DO UPDATE SET quantity=cart_items.quantity+EXCLUDED.quantity')
                ->execute([':id' => Id::make('ci'), ':cart' => $cart['id'], ':sku' => $skuIdInternal, ':quantity' => $quantity]);
            $this->touch($pdo, (int) $cart['id']);
        });
        return $this->get($userId, $guestToken);
    }

    public function updateItem(?int $userId, string $guestToken, string $itemId, int $quantity, int $version): array
    {
        if ($quantity < 1) throw new ApiException('VALIDATION_FAILED', 'تعداد باید مثبت باشد.', 422, ['quantity' => 'حداقل ۱ است.']);
        DB::transaction(function (PDO $pdo) use ($userId, $guestToken, $itemId, $quantity, $version): void {
            $cart = $this->findForUpdate($pdo, $userId, $guestToken, true); $this->assertVersion($cart, $version);
            $stmt = $pdo->prepare('UPDATE cart_items SET quantity=:quantity WHERE public_id=:item AND cart_id=:cart'); $stmt->execute([':quantity' => $quantity, ':item' => $itemId, ':cart' => $cart['id']]);
            if ($stmt->rowCount() !== 1) throw new ApiException('CART_ITEM_NOT_FOUND', 'قلم سبد یافت نشد.', 404);
            $this->touch($pdo, (int) $cart['id']);
        });
        return $this->get($userId, $guestToken);
    }

    public function removeItem(?int $userId, string $guestToken, string $itemId, int $version): array
    {
        DB::transaction(function (PDO $pdo) use ($userId, $guestToken, $itemId, $version): void {
            $cart = $this->findForUpdate($pdo, $userId, $guestToken, true); $this->assertVersion($cart, $version);
            $stmt = $pdo->prepare('DELETE FROM cart_items WHERE public_id=:item AND cart_id=:cart'); $stmt->execute([':item' => $itemId, ':cart' => $cart['id']]);
            if ($stmt->rowCount() !== 1) throw new ApiException('CART_ITEM_NOT_FOUND', 'قلم سبد یافت نشد.', 404);
            $this->touch($pdo, (int) $cart['id']);
        });
        return $this->get($userId, $guestToken);
    }

    public function clear(?int $userId, string $guestToken, int $version): array
    {
        DB::transaction(function (PDO $pdo) use ($userId, $guestToken, $version): void { $cart=$this->findForUpdate($pdo,$userId,$guestToken,true);$this->assertVersion($cart,$version);$pdo->prepare('DELETE FROM cart_items WHERE cart_id=:cart')->execute([':cart'=>$cart['id']]);$this->touch($pdo,(int)$cart['id']); });
        return $this->get($userId, $guestToken);
    }

    public function applyCoupon(?int $userId, string $guestToken, string $code, ?int $version): array
    {
        DB::transaction(function (PDO $pdo) use ($userId, $guestToken, $code, $version): void {
            $cart=$this->findForUpdate($pdo,$userId,$guestToken,true);$this->assertVersion($cart,$version);
            $stmt=$pdo->prepare('SELECT id FROM coupons WHERE upper(code)=upper(:code) AND active=true AND (expires_at IS NULL OR expires_at>now())');$stmt->execute([':code'=>trim($code)]);$coupon=$stmt->fetchColumn();
            if(!$coupon) throw new ApiException('COUPON_NOT_FOUND','کد تخفیف معتبر نیست.',404);
            $pdo->prepare('UPDATE carts SET coupon_id=:coupon,version=version+1,updated_at=now() WHERE id=:cart')->execute([':coupon'=>$coupon,':cart'=>$cart['id']]);
        });
        return $this->get($userId,$guestToken);
    }

    public function removeCoupon(?int $userId, string $guestToken, ?int $version): array
    {
        DB::transaction(function(PDO $pdo)use($userId,$guestToken,$version):void{$cart=$this->findForUpdate($pdo,$userId,$guestToken,true);$this->assertVersion($cart,$version);$pdo->prepare('UPDATE carts SET coupon_id=NULL,version=version+1,updated_at=now() WHERE id=:cart')->execute([':cart'=>$cart['id']]);});
        return $this->get($userId,$guestToken);
    }

    public function merge(int $userId, string $guestToken): array
    {
        $result = DB::transaction(fn(PDO $pdo): array => $this->mergeGuestIntoUser($pdo, $userId, $guestToken));
        return ['cartMerge' => $result, 'cart' => $this->get($userId, '')];
    }

    /** Called from the OTP transaction; both carts are locked before rows move. */
    public function mergeGuestIntoUser(PDO $pdo, int $userId, string $guestToken): array
    {
        if ($guestToken === '') return ['status' => 'not_requested'];
        $guest = $this->findForUpdate($pdo, null, $guestToken, false);
        if (!$guest) return ['status' => 'not_found'];
        $user = $this->findForUpdate($pdo, $userId, '', true);
        if ((int)$guest['id'] === (int)$user['id']) return ['status' => 'already_merged'];
        $rows=$pdo->prepare('SELECT sku_id,quantity FROM cart_items WHERE cart_id=:cart FOR UPDATE');$rows->execute([':cart'=>$guest['id']]);$items=$rows->fetchAll();
        foreach($items as $item){$pdo->prepare('INSERT INTO cart_items(public_id,cart_id,sku_id,quantity) VALUES(:id,:cart,:sku,:quantity) ON CONFLICT(cart_id,sku_id) DO UPDATE SET quantity=cart_items.quantity+EXCLUDED.quantity')->execute([':id'=>Id::make('ci'),':cart'=>$user['id'],':sku'=>$item['sku_id'],':quantity'=>$item['quantity']]);}
        $this->touch($pdo,(int)$user['id']);
        $pdo->prepare("UPDATE carts SET status='merged',updated_at=now() WHERE id=:id")->execute([':id'=>$guest['id']]);
        return ['status'=>'merged','sourceCartId'=>$guest['public_id'],'targetCartId'=>$user['public_id'],'mergedLineCount'=>count($items)];
    }

    private function find(?int $userId,string $guestToken,bool $createUserCart): array
    {
        if($userId!==null){$row=DB::fetch("SELECT * FROM carts WHERE user_id=:user AND status='active'",[':user'=>$userId]);if(!$row&&$createUserCart){DB::execute('INSERT INTO carts(public_id,user_id) VALUES(:id,:user) ON CONFLICT (user_id) WHERE status=\'active\' DO NOTHING',[':id'=>Id::make('cart'),':user'=>$userId]);$row=DB::fetch("SELECT * FROM carts WHERE user_id=:user AND status='active'",[':user'=>$userId]);}if(!$row)throw new ApiException('CART_NOT_FOUND','سبد یافت نشد.',404);return $row;}
        if($guestToken==='')throw new ApiException('CART_NOT_FOUND','توکن سبد مهمان ارسال نشده است.',404);
        $row=DB::fetch("SELECT * FROM carts WHERE guest_token_hash=:token AND status='active'",[':token'=>hash('sha256',$guestToken)]);if(!$row)throw new ApiException('CART_NOT_FOUND','سبد یافت نشد.',404);return $row;
    }

    private function findForUpdate(PDO $pdo,?int $userId,string $guestToken,bool $createUserCart): ?array
    {
        if($userId!==null){$stmt=$pdo->prepare("SELECT * FROM carts WHERE user_id=:user AND status='active' FOR UPDATE");$stmt->execute([':user'=>$userId]);$cart=$stmt->fetch();if(!$cart&&$createUserCart){$pdo->prepare('INSERT INTO carts(public_id,user_id) VALUES(:id,:user) ON CONFLICT (user_id) WHERE status=\'active\' DO NOTHING')->execute([':id'=>Id::make('cart'),':user'=>$userId]);$stmt->execute([':user'=>$userId]);$cart=$stmt->fetch();}if(!$cart)throw new ApiException('CART_NOT_FOUND','سبد یافت نشد.',404);return $cart;}
        if($guestToken==='')return null;$stmt=$pdo->prepare("SELECT * FROM carts WHERE guest_token_hash=:token AND status='active' FOR UPDATE");$stmt->execute([':token'=>hash('sha256',$guestToken)]);return $stmt->fetch()?:null;
    }

    private function touch(PDO $pdo,int $cartId):void{$pdo->prepare('UPDATE carts SET version=version+1,updated_at=now() WHERE id=:id')->execute([':id'=>$cartId]);}
    private function assertVersion(array $cart,?int $expected):void{if($expected!==null&&(int)$cart['version']!==$expected)throw new ApiException('CART_VERSION_CONFLICT','نسخه سبد تغییر کرده است.',409,details:['expectedVersion'=>$expected,'currentVersion'=>(int)$cart['version']]);}

    private function shape(array $cart): array
    {
        $items=DB::fetchAll(<<<'SQL'
            SELECT ci.public_id id,ci.quantity,s.public_id sku_id,p.title,p.slug,p.subtitle,s.attributes,s.minimum_order_quantity,s.reference_unit_price,s.status,s.fast_dispatch,s.pricing_version,
              COALESCE(stock.available_quantity,0)::int available_quantity,COALESCE(tiers.items,'[]'::jsonb) tiers
            FROM cart_items ci JOIN skus s ON s.id=ci.sku_id JOIN products p ON p.id=s.product_id
            LEFT JOIN LATERAL(SELECT SUM(on_hand-reserved-safety_stock)::int available_quantity FROM inventory_balances WHERE sku_id=s.id) stock ON true
            LEFT JOIN LATERAL(SELECT jsonb_agg(jsonb_build_object('min_quantity',min_quantity,'max_quantity',max_quantity,'unit_price',unit_price) ORDER BY min_quantity) items FROM pricing_tiers WHERE sku_id=s.id AND effective_from<=now() AND (effective_to IS NULL OR effective_to>now())) tiers ON true
            WHERE ci.cart_id=:cart ORDER BY ci.id
            SQL,[':cart'=>$cart['id']]);
        $lines=[];$subtotal=0;$saving=0;$warnings=[];
        foreach($items as $item){try{$quote=$this->pricing->quote(['public_id'=>$item['sku_id'],'minimum_order_quantity'=>$item['minimum_order_quantity'],'reference_unit_price'=>$item['reference_unit_price'],'pricing_version'=>$item['pricing_version']],self::decode($item['tiers']),(int)$item['quantity'],max(0,(int)$item['available_quantity']));$subtotal+=$quote['lineSubtotal'];$saving+=$quote['lineSaving'];$lines[]=['id'=>$item['id'],'skuId'=>$item['sku_id'],'product'=>['title'=>$item['title'],'slug'=>$item['slug']],'variant'=>self::decodeObject($item['attributes']),'quantity'=>(int)$item['quantity']]+$quote+['warnings'=>[]];}catch(ApiException $exception){$lineWarning=$exception->errorCode;$warnings[]=$lineWarning;$lines[]=['id'=>$item['id'],'skuId'=>$item['sku_id'],'product'=>['title'=>$item['title'],'slug'=>$item['slug']],'variant'=>self::decodeObject($item['attributes']),'quantity'=>(int)$item['quantity'],'minimumQuantity'=>(int)$item['minimum_order_quantity'],'availability'=>$item['status'],'warnings'=>[$lineWarning]];}}
        $coupon=$this->coupon((int)$cart['coupon_id'],$subtotal,array_sum(array_column($lines,'quantity')));$couponDiscount=$coupon['discount'];if($coupon['warning'])$warnings[]=$coupon['warning'];
        return ['id'=>$cart['public_id'],'version'=>(int)$cart['version'],'currency'=>'TOMAN','items'=>$lines,'summary'=>['lineCount'=>count($lines),'totalQuantity'=>array_sum(array_column($lines,'quantity')),'referenceSubtotal'=>$subtotal+$saving,'merchandiseSubtotal'=>$subtotal,'wholesaleSaving'=>$saving,'couponDiscount'=>$couponDiscount,'shipping'=>null,'payable'=>max(0,$subtotal-$couponDiscount)],'coupon'=>$coupon['coupon'],'isValidForCheckout'=>count($lines)>0&&$warnings===[],'warnings'=>array_values(array_unique($warnings))];
    }
    private function coupon(?int $couponId,int $subtotal,int $quantity):array{if(!$couponId)return ['discount'=>0,'warning'=>null,'coupon'=>null];$coupon=DB::fetch('SELECT public_id,code,type,value,rules,expires_at,active FROM coupons WHERE id=:id',[':id'=>$couponId]);if(!$coupon||!$coupon['active']||($coupon['expires_at']&&strtotime($coupon['expires_at'])<time()))return ['discount'=>0,'warning'=>'COUPON_EXPIRED','coupon'=>null];$rules=self::decodeObject($coupon['rules']);if(isset($rules['minMerchandiseSubtotal'])&&$subtotal<(int)$rules['minMerchandiseSubtotal'])return ['discount'=>0,'warning'=>'COUPON_NOT_ELIGIBLE','coupon'=>['code'=>$coupon['code']]];if(isset($rules['minQuantity'])&&$quantity<(int)$rules['minQuantity'])return ['discount'=>0,'warning'=>'COUPON_NOT_ELIGIBLE','coupon'=>['code'=>$coupon['code']]];$discount=in_array($coupon['type'],['percent','percentage'],true)?intdiv($subtotal*(int)$coupon['value'],100):min($subtotal,(int)$coupon['value']);if(isset($rules['maxDiscount']))$discount=min($discount,(int)$rules['maxDiscount']);return ['discount'=>$discount,'warning'=>null,'coupon'=>['id'=>$coupon['public_id'],'code'=>$coupon['code'],'discount'=>$discount]];}
    private static function decode(mixed $value):array{$decoded=is_array($value)?$value:json_decode((string)$value,true);return is_array($decoded)?$decoded:[];}
    private static function decodeObject(mixed $value):array{$decoded=is_array($value)?$value:json_decode((string)$value,true);return is_array($decoded)?$decoded:[];}
}
