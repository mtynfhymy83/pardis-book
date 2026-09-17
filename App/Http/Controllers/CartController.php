<?php

declare(strict_types=1);
namespace App\Http\Controllers;

use App\Application\Services\CartService;
use Swoole\Http\Request;

final class CartController extends Controller
{
    public function __construct(private CartService $carts) {}
    public function create(): array { return $this->created($this->carts->create()); }
    public function show(Request $request): array { return $this->ok($this->carts->get($this->getAuthUserId($request),$this->guestToken($request))); }
    public function add(Request $request,array $data): array { return $this->ok($this->carts->add($this->getAuthUserId($request),$this->guestToken($request),(string)($data['skuId']??''),(int)($data['quantity']??0),isset($data['version'])?(int)$data['version']:null)); }
    public function update(Request $request,string $itemId,array $data): array { return $this->ok($this->carts->updateItem($this->getAuthUserId($request),$this->guestToken($request),$itemId,(int)($data['quantity']??0),(int)($data['version']??0))); }
    public function remove(Request $request,string $itemId,array $data): array { return $this->ok($this->carts->removeItem($this->getAuthUserId($request),$this->guestToken($request),$itemId,(int)($data['version']??0))); }
    public function clear(Request $request,array $data): array { return $this->ok($this->carts->clear($this->getAuthUserId($request),$this->guestToken($request),(int)($data['version']??0))); }
    public function validate(Request $request): array { return $this->show($request); }
    public function applyCoupon(Request $request,array $data): array { return $this->ok($this->carts->applyCoupon($this->getAuthUserId($request),$this->guestToken($request),(string)($data['code']??''),isset($data['version'])?(int)$data['version']:null)); }
    public function removeCoupon(Request $request,array $data): array { return $this->ok($this->carts->removeCoupon($this->getAuthUserId($request),$this->guestToken($request),isset($data['version'])?(int)$data['version']:null)); }
    public function merge(Request $request,array $data): array { return $this->ok($this->carts->merge($this->requireAuthUserId($request),(string)($data['guestCartToken']??$this->guestToken($request)))); }
    private function guestToken(Request $request): string { return (string)($request->header['x-guest-cart-token']??''); }
}
