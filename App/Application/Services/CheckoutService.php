<?php
declare(strict_types=1);
namespace App\Application\Services;

use App\Domain\Contracts\Providers\PaymentGatewayInterface;
use App\Infrastructure\Database\DB;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Id;
use PDO;

final class CheckoutService
{
    public function __construct(private CartService $carts, private PaymentGatewayInterface $gateway) {}

    public function quote(int $userId, array $data): array
    {
        $cart = $this->carts->get($userId, '');
        if (!$cart['isValidForCheckout']) throw new ApiException('CART_MINIMUM_NOT_MET', 'سبد برای پرداخت معتبر نیست.', 409);
        $address = DB::fetch('SELECT * FROM addresses WHERE public_id=:id AND user_id=:u AND deleted_at IS NULL', [':id'=>(string)($data['addressId']??''), ':u'=>$userId]);
        if (!$address) throw new ApiException('ADDRESS_NOT_SERVICEABLE', 'نشانی معتبر نیست.', 422);
        $shipping = 80000;
        $rate = (int) ($_ENV['DEFAULT_TAX_RATE_BPS'] ?? 0);
        $tax = intdiv((int)$cart['summary']['merchandiseSubtotal'] * $rate, 10000);
        $summary = $cart['summary']; $summary['shipping']=$shipping; $summary['tax']=$tax; $summary['payable']=$summary['merchandiseSubtotal']+$shipping+$tax;
        $id = Id::make('quo'); $expires=gmdate('Y-m-d H:i:sP', time()+600);
        $snapshot=['lines'=>$cart['items'],'address'=>json_decode($address['data'],true),'shipping'=>['id'=>$data['shippingOptionId']??'ship_standard','cost'=>$shipping],'summary'=>$summary,'taxRateBps'=>$rate];
        DB::execute('INSERT INTO checkout_quotes(public_id,user_id,cart_id,cart_version,snapshot,expires_at) SELECT :p,:u,id,:v,CAST(:s AS jsonb),:e FROM carts WHERE public_id=:c', [':p'=>$id,':u'=>$userId,':v'=>$cart['version'],':s'=>json_encode($snapshot),':e'=>$expires,':c'=>$cart['id']]);
        return ['quoteId'=>$id,'cartVersion'=>$cart['version']]+$snapshot+['expiresAt'=>gmdate('c',time()+600)];
    }

    public function order(int $userId, array $data, string $key): array
    {
        if ($key==='') throw new ApiException('IDEMPOTENCY_KEY_REQUIRED','Idempotency-Key الزامی است.',400);
        $hash=hash('sha256',json_encode($data));
        return DB::transaction(function(PDO $pdo) use($userId,$data,$key,$hash) {
            $s=$pdo->prepare('SELECT * FROM idempotency_keys WHERE scope=\'order\' AND owner_key=:o AND idem_key=:k FOR UPDATE'); $s->execute([':o'=>(string)$userId,':k'=>$key]); $idem=$s->fetch();
            if($idem){ if($idem['request_hash']!==$hash) throw new ApiException('IDEMPOTENCY_KEY_REUSED','کلید با payload متفاوت استفاده شده است.',409); return json_decode($idem['response'],true); }
            $s=$pdo->prepare('SELECT * FROM checkout_quotes WHERE public_id=:q AND user_id=:u FOR UPDATE'); $s->execute([':q'=>(string)($data['quoteId']??''),':u'=>$userId]); $q=$s->fetch(); if(!$q||strtotime($q['expires_at'])<time()) throw new ApiException('CHECKOUT_QUOTE_EXPIRED','پیش‌فاکتور منقضی شده است.',409);
            $snap=json_decode($q['snapshot'],true); $orderId=Id::make('ord'); $number='PD-'.gmdate('Ymd').'-'.random_int(10000,99999); $expiry=gmdate('Y-m-d H:i:sP',time()+(int)($_ENV['RESERVATION_TTL_SECONDS']??900));
            $stocks=[]; foreach($snap['lines'] as $line){$s=$pdo->prepare('SELECT s.id,ib.warehouse_id,(ib.on_hand-ib.reserved-ib.safety_stock) available FROM skus s JOIN inventory_balances ib ON ib.sku_id=s.id WHERE s.public_id=:id FOR UPDATE OF ib');$s->execute([':id'=>$line['skuId']]);$stock=$s->fetch();if(!$stock||(int)$stock['available']<(int)$line['quantity'])throw new ApiException('INSUFFICIENT_STOCK','موجودی کافی نیست.',409);$pdo->prepare('UPDATE inventory_balances SET reserved=reserved+:qty,version=version+1 WHERE sku_id=:s AND warehouse_id=:w')->execute([':qty'=>$line['quantity'],':s'=>$stock['id'],':w'=>$stock['warehouse_id']]);$stocks[$line['skuId']]=$stock;}
            $s=$pdo->prepare('INSERT INTO orders(public_id,order_number,user_id,quote_id,status,snapshot,terms_version,reservation_expires_at) VALUES(:p,:n,:u,:q,\'awaiting_payment\',CAST(:s AS jsonb),:t,:e) RETURNING id');$s->execute([':p'=>$orderId,':n'=>$number,':u'=>$userId,':q'=>$q['id'],':s'=>json_encode($snap),':t'=>(string)($data['acceptTermsVersion']??''),':e'=>$expiry]);$internal=(int)$s->fetchColumn();
            foreach($snap['lines'] as $line){$stock=$stocks[$line['skuId']];$pdo->prepare('INSERT INTO order_lines(public_id,order_id,sku_id,snapshot,quantity,unit_price,line_subtotal,tax)VALUES(:p,:o,:s,CAST(:j AS jsonb),:q,:u,:l,0)')->execute([':p'=>Id::make('ol'),':o'=>$internal,':s'=>$stock['id'],':j'=>json_encode($line),':q'=>$line['quantity'],':u'=>$line['unitPrice'],':l'=>$line['lineSubtotal']]);$pdo->prepare('INSERT INTO inventory_reservations(public_id,order_id,sku_id,warehouse_id,quantity,expires_at)VALUES(:p,:o,:s,:w,:q,:e)')->execute([':p'=>Id::make('res'),':o'=>$internal,':s'=>$stock['id'],':w'=>$stock['warehouse_id'],':q'=>$line['quantity'],':e'=>$expiry]);}
            $result=['id'=>$orderId,'orderNumber'=>$number,'status'=>'awaiting_payment','paymentStatus'=>'unpaid','payable'=>$snap['summary']['payable'],'inventoryReservationExpiresAt'=>$expiry];
            $pdo->prepare('INSERT INTO idempotency_keys(scope,owner_key,idem_key,request_hash,response,status_code)VALUES(\'order\',:o,:k,:h,CAST(:r AS jsonb),201)')->execute([':o'=>(string)$userId,':k'=>$key,':h'=>$hash,':r'=>json_encode($result)]); return $result;
        });
    }

    public function payment(int $userId, array $data, string $key): array
    {
        if($key==='') throw new ApiException('IDEMPOTENCY_KEY_REQUIRED','Idempotency-Key الزامی است.',400);
        $order=DB::fetch('SELECT * FROM orders WHERE public_id=:o AND user_id=:u',[':o'=>(string)($data['orderId']??''),':u'=>$userId]); if(!$order) throw new ApiException('ORDER_NOT_FOUND','سفارش یافت نشد.',404); if($order['payment_status']==='paid') throw new ApiException('ORDER_ALREADY_PAID','سفارش پرداخت شده است.',409);
        $snap=json_decode($order['snapshot'],true); $id=Id::make('pay'); $created=$this->gateway->create($id,(int)$snap['summary']['payable'],(string)($data['returnUrl']??'')); DB::execute('INSERT INTO payment_attempts(public_id,order_id,provider,status,amount,provider_reference)VALUES(:p,:o,\'fake\',\'redirected\',:a,:r)',[':p'=>$id,':o'=>$order['id'],':a'=>$snap['summary']['payable'],':r'=>$created['reference']]); return ['attemptId'=>$id,'status'=>'redirected','redirectUrl'=>$created['redirectUrl'],'expiresAt'=>gmdate('c',time()+900)];
    }

    public function verify(int $userId, string $attemptId): array
    {
        return DB::transaction(function(PDO $pdo) use($userId,$attemptId) {
            $s=$pdo->prepare('SELECT pa.*,o.user_id,o.status order_status FROM payment_attempts pa JOIN orders o ON o.id=pa.order_id WHERE pa.public_id=:p AND o.user_id=:u FOR UPDATE OF pa,o'); $s->execute([':p'=>$attemptId,':u'=>$userId]); $attempt=$s->fetch();
            if(!$attempt) throw new ApiException('PAYMENT_ATTEMPT_NOT_FOUND','تلاش پرداخت یافت نشد.',404);
            if ($attempt['status']==='succeeded') return ['attemptId'=>$attemptId,'status'=>'succeeded'];
            $verified=$this->gateway->verify((string)$attempt['provider_reference'],(int)$attempt['amount']); if(!$verified['success']) throw new ApiException('PAYMENT_VERIFICATION_FAILED','تأیید پرداخت ناموفق بود.',409);
            $pdo->prepare("UPDATE payment_attempts SET status='succeeded' WHERE id=:id")->execute([':id'=>$attempt['id']]);
            $pdo->prepare("UPDATE orders SET status='paid',payment_status='paid' WHERE id=:id")->execute([':id'=>$attempt['order_id']]);
            $reservations=$pdo->prepare("SELECT * FROM inventory_reservations WHERE order_id=:o AND status='active' FOR UPDATE");$reservations->execute([':o'=>$attempt['order_id']]);foreach($reservations->fetchAll() as $reservation){$pdo->prepare('UPDATE inventory_balances SET on_hand=on_hand-:q,reserved=reserved-:q,version=version+1 WHERE sku_id=:s AND warehouse_id=:w')->execute([':q'=>$reservation['quantity'],':s'=>$reservation['sku_id'],':w'=>$reservation['warehouse_id']]);}$pdo->prepare("UPDATE inventory_reservations SET status='committed' WHERE order_id=:o AND status='active'")->execute([':o'=>$attempt['order_id']]);
            $pdo->prepare("INSERT INTO outbox_events(public_id,event_type,aggregate_type,aggregate_id,payload)VALUES(:p,'order.payment_succeeded','order',:a,'{}')")->execute([':p'=>Id::make('evt'),':a'=>(string)$attempt['order_id']]); return ['attemptId'=>$attemptId,'status'=>'succeeded'];
        });
    }
}
