<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Application\Services\AccountService; use Swoole\Http\Request;
final class AccountController extends Controller {
 public function __construct(private AccountService $accounts){}
 public function me(Request $r):array{return $this->ok($this->accounts->me($this->requireAuthUserId($r)));}
 public function update(Request $r,array $d):array{return $this->ok($this->accounts->update($this->requireAuthUserId($r),$d));}
 public function business(Request $r):array{return $this->ok($this->accounts->business($this->requireAuthUserId($r)));}
 public function saveBusiness(Request $r,array $d):array{return $this->ok($this->accounts->saveBusiness($this->requireAuthUserId($r),$d));}
 public function deleteAccount(Request $r):array{$this->accounts->delete($this->requireAuthUserId($r));return $this->deleted();}
 public function addresses(Request $r):array{return $this->ok($this->accounts->addresses($this->requireAuthUserId($r)));}
 public function addAddress(Request $r,array $d):array{return $this->created($this->accounts->addAddress($this->requireAuthUserId($r),$d));}
 public function updateAddress(Request $r,string $addressId,array $d):array{return $this->ok($this->accounts->updateAddress($this->requireAuthUserId($r),$addressId,$d));}
 public function removeAddress(Request $r,string $addressId,array $d):array{$this->accounts->removeAddress($this->requireAuthUserId($r),$addressId,(int)($d['version']??0));return $this->deleted();}
 public function defaultAddress(Request $r,string $addressId,array $d):array{return $this->ok($this->accounts->makeDefault($this->requireAuthUserId($r),$addressId,(int)($d['version']??0)));}
}
