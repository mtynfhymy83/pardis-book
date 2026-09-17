<?php
declare(strict_types=1); namespace App\Http\Controllers;
use App\Application\Services\CheckoutService; use Swoole\Http\Request;
final class CheckoutController extends Controller {
    public function __construct(private CheckoutService $checkout) {}
    public function quote(Request $r,array $data):array{return $this->ok($this->checkout->quote($this->requireAuthUserId($r),$data));}
    public function order(Request $r,array $data):array{return $this->created($this->checkout->order($this->requireAuthUserId($r),$data,(string)($r->header['idempotency-key']??'')));}
    public function payment(Request $r,array $data):array{return $this->created($this->checkout->payment($this->requireAuthUserId($r),$data,(string)($r->header['idempotency-key']??'')));}
    public function paymentAttempt(Request $r,string $attemptId):array{return $this->ok($this->checkout->paymentAttempt($this->requireAuthUserId($r),$attemptId));}
    public function orderPayment(Request $r,string $orderId):array{return $this->ok($this->checkout->orderPayment($this->requireAuthUserId($r),$orderId));}
    public function verify(Request $r,string $attemptId):array{return $this->ok($this->checkout->verify($this->requireAuthUserId($r),$attemptId));}
    public function callback(Request $r,string $provider):array{return $this->ok($this->checkout->callback($provider,(string)($r->get['attemptId']??'')));}
    public function webhook(Request $r,string $provider,array $data):array{$result=$this->checkout->webhook($provider,$data,$r->rawContent(),(string)($r->header['x-payment-signature']??''));if($result['status']==='accepted'&&($data['status']??'')==='succeeded')$result=$this->checkout->verify((int)$result['userId'],$result['attemptId']);unset($result['userId']);return $this->ok($result);}
}
