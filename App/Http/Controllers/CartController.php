<?php
declare(strict_types=1); namespace App\Http\Controllers;
use App\Application\Services\CartService; use Swoole\Http\Request;
final class CartController extends Controller { public function __construct(private CartService $carts){} public function create():array{return $this->created($this->carts->create());} public function show(Request $r):array{return $this->ok($this->carts->get($this->getAuthUserId($r),(string)($r->header['x-guest-cart-token']??'')));} public function add(Request $r,array $data):array{return $this->ok($this->carts->add($this->getAuthUserId($r),(string)($r->header['x-guest-cart-token']??''),(string)($data['skuId']??''),(int)($data['quantity']??0)));} public function validate(Request $r):array{return $this->show($r);} }
