<?php
declare(strict_types=1);
namespace App\Application\Services;
use App\Domain\Contracts\Providers\ObjectStorageInterface; use App\Infrastructure\Database\DB; use App\Shared\Exceptions\ApiException; use App\Shared\Support\Id;
final class ReportService {
 public function __construct(private ObjectStorageInterface $storage){}
 public function createExport(int $userId,array $data):array{$type=(string)($data['type']??'');if(!in_array($type,['sales-summary','top-products','inventory-risk','customers'],true))throw new ApiException('REPORT_TYPE_NOT_SUPPORTED','نوع گزارش پشتیبانی نمی‌شود.',422);$id=Id::make('rpt');DB::execute("INSERT INTO report_exports(public_id,requested_by,report_type,filters,status)VALUES(:id,:user,:type,CAST(:filters AS jsonb),'queued')",[':id'=>$id,':user'=>$userId,':type'=>$type,':filters'=>json_encode($data['filters']??[])]);return ['jobId'=>$id,'status'=>'queued'];}
 public function export(int $userId,string $jobId):array{$r=DB::fetch('SELECT public_id id,report_type AS "reportType",status,storage_key,error_code AS "errorCode",created_at AS "createdAt",completed_at AS "completedAt" FROM report_exports WHERE public_id=:id AND requested_by=:user',[':id'=>$jobId,':user'=>$userId]);if(!$r)throw new ApiException('REPORT_EXPORT_NOT_FOUND','Job خروجی یافت نشد.',404);if($r['status']==='completed'&&$r['storage_key'])$r['download']=$this->storage->presignGet($r['storage_key']);unset($r['storage_key']);return $r;}
}
