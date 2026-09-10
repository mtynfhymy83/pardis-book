<?php
declare(strict_types=1);
namespace App\Http\Controllers;

use App\Infrastructure\Database\DB;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Id;
use Swoole\Http\Request;

final class AccountController extends Controller
{
    public function me(Request $request): array
    {
        $row = DB::fetch('SELECT public_id AS id,phone,name,customer_type AS "customerType" FROM users WHERE id=:id', [':id'=>$this->requireAuthUserId($request)]);
        return $this->ok($row);
    }

    public function update(Request $request, array $data): array
    {
        DB::execute('UPDATE users SET name=:name,customer_type=:type WHERE id=:id', [':name'=>$data['name']??null, ':type'=>$data['customerType']??'individual', ':id'=>$this->requireAuthUserId($request)]);
        return $this->me($request);
    }

    public function addresses(Request $request): array
    {
        $rows = DB::fetchAll('SELECT public_id,version,is_default,data FROM addresses WHERE user_id=:u AND deleted_at IS NULL ORDER BY is_default DESC,id DESC', [':u'=>$this->requireAuthUserId($request)]);
        return $this->ok(array_map(static fn(array $row): array => ['id'=>$row['public_id'],'version'=>(int)$row['version'],'isDefault'=>(bool)$row['is_default']] + json_decode($row['data'], true), $rows));
    }

    public function addAddress(Request $request, array $data): array
    {
        foreach (['recipientName','recipientPhone','provinceId','cityId','postalCode','addressLine'] as $field) if (empty($data[$field])) throw new ApiException('VALIDATION_FAILED','اطلاعات نشانی ناقص است.',422,[$field=>'الزامی است.']);
        $userId=$this->requireAuthUserId($request); $id=Id::make('adr');
        DB::transaction(function(\PDO $pdo) use($userId,$id,$data): void { if(!empty($data['isDefault'])) $pdo->prepare('UPDATE addresses SET is_default=false WHERE user_id=:u')->execute([':u'=>$userId]); $pdo->prepare('INSERT INTO addresses(public_id,user_id,data,is_default)VALUES(:p,:u,:d::jsonb,:i)')->execute([':p'=>$id,':u'=>$userId,':d'=>json_encode($data),':i'=>!empty($data['isDefault'])]); });
        return $this->created(['id'=>$id,'version'=>1]+$data);
    }

    public function orders(Request $request): array
    {
        $rows=DB::fetchAll('SELECT public_id AS id,order_number AS "orderNumber",status,payment_status AS "paymentStatus",snapshot,created_at AS "createdAt" FROM orders WHERE user_id=:u ORDER BY created_at DESC LIMIT 60', [':u'=>$this->requireAuthUserId($request)]);
        foreach($rows as &$row){$snapshot=json_decode($row['snapshot'],true);$row['payable']=$snapshot['summary']['payable']??0;unset($row['snapshot']);}
        return $this->ok($rows);
    }

    public function order(Request $request, string $orderId): array
    {
        $row=DB::fetch('SELECT public_id AS id,order_number AS "orderNumber",status,payment_status AS "paymentStatus",snapshot,created_at AS "createdAt" FROM orders WHERE public_id=:o AND user_id=:u', [':o'=>$orderId,':u'=>$this->requireAuthUserId($request)]);
        if(!$row) throw new ApiException('ORDER_NOT_FOUND','سفارش یافت نشد.',404);
        $row['snapshot']=json_decode($row['snapshot'],true); return $this->ok($row);
    }
}
