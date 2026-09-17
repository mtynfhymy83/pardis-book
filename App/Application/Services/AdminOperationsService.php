<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Services\OrderStateMachine;
use App\Infrastructure\Database\DB;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Id;
use PDO;

final class AdminOperationsService
{
    public function __construct(private OrderStateMachine $states, private AuditService $audit) {}

    public function orders(array $filter): array
    {
        $where = []; $params = [];
        if (!empty($filter['status'])) {$where[] = 'o.status=:status'; $params[':status'] = $filter['status'];}
        if (!empty($filter['q'])) {$where[] = '(o.order_number ILIKE :q OR u.phone ILIKE :q)'; $params[':q'] = '%' . trim((string) $filter['q']) . '%';}
        return DB::fetchAll('SELECT o.public_id id,o.order_number AS "orderNumber",o.status,o.payment_status AS "paymentStatus",u.public_id AS "customerId",u.name AS "customerName",o.snapshot->\'summary\'->>\'payable\' AS payable,o.created_at AS "createdAt" FROM orders o JOIN users u ON u.id=o.user_id ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY o.id DESC LIMIT 100', $params);
    }

    public function transition(int $actor, string $orderId, array $data): array
    {
        $next = (string) ($data['status'] ?? ''); $reason = trim((string) ($data['reason'] ?? '')); if ($next === '' || $reason === '') throw new ApiException('VALIDATION_FAILED', 'وضعیت و دلیل الزامی هستند.', 422);
        return DB::transaction(function (PDO $pdo) use ($actor, $orderId, $next, $reason): array {
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE public_id=:order FOR UPDATE'); $stmt->execute([':order' => $orderId]); $order = $stmt->fetch(); if (!$order) throw new ApiException('ORDER_NOT_FOUND', 'سفارش یافت نشد.', 404);
            $this->states->assert($order['status'], $next);
            $pdo->prepare('UPDATE orders SET status=:status WHERE id=:id')->execute([':status' => $next, ':id' => $order['id']]);
            $pdo->prepare('INSERT INTO order_status_history(order_id,previous_status,new_status,reason,actor_id) VALUES(:order,:previous,:next,:reason,:actor)')->execute([':order' => $order['id'], ':previous' => $order['status'], ':next' => $next, ':reason' => $reason, ':actor' => $actor]);
            $this->audit->record($pdo, $actor, 'order.transition', 'order', $orderId, ['status' => $order['status']], ['status' => $next, 'reason' => $reason]); return ['id' => $orderId, 'previousStatus' => $order['status'], 'status' => $next];
        });
    }

    public function note(int $actor, string $orderId, string $body): array
    {
        $body = trim($body); if ($body === '') throw new ApiException('VALIDATION_FAILED', 'متن یادداشت الزامی است.', 422);
        return DB::transaction(function(PDO $pdo) use($actor,$orderId,$body): array {$order=$pdo->prepare('SELECT id FROM orders WHERE public_id=:order');$order->execute([':order'=>$orderId]);$id=$order->fetchColumn();if(!$id)throw new ApiException('ORDER_NOT_FOUND','سفارش یافت نشد.',404);$note=Id::make('onote');$pdo->prepare('INSERT INTO order_notes(public_id,order_id,actor_id,body)VALUES(:id,:order,:actor,:body)')->execute([':id'=>$note,':order'=>$id,':actor'=>$actor,':body'=>$body]);$this->audit->record($pdo,$actor,'order.note','order',$orderId,null,['noteId'=>$note]);return ['id'=>$note];});
    }

    public function createShipment(int $actor, string $orderId, array $data): array
    {
        return DB::transaction(function(PDO $pdo) use($actor,$orderId,$data): array {$order=$pdo->prepare('SELECT id,status FROM orders WHERE public_id=:order FOR UPDATE');$order->execute([':order'=>$orderId]);$o=$order->fetch();if(!$o)throw new ApiException('ORDER_NOT_FOUND','سفارش یافت نشد.',404);if(!in_array($o['status'],['paid','preparing','partially_shipped'],true))throw new ApiException('INVALID_ORDER_TRANSITION','سفارش برای ارسال آماده نیست.',409);$id=Id::make('shp');$payload=['provider'=>$data['provider']??null,'trackingCode'=>$data['trackingCode']??null,'items'=>$data['items']??[]];$pdo->prepare("INSERT INTO shipments(public_id,order_id,data,status)VALUES(:id,:order,CAST(:data AS jsonb),'created')")->execute([':id'=>$id,':order'=>$o['id'],':data'=>json_encode($payload)]);$this->audit->record($pdo,$actor,'shipment.create','shipment',$id,null,$payload);return ['id'=>$id,'status'=>'created'] ;});
    }

    public function dispatchShipment(int $actor, string $shipmentId): array
    {
        return DB::transaction(function(PDO $pdo) use($actor,$shipmentId): array {$s=$pdo->prepare('SELECT s.*,o.id order_id,o.status order_status FROM shipments s JOIN orders o ON o.id=s.order_id WHERE s.public_id=:id FOR UPDATE OF s,o');$s->execute([':id'=>$shipmentId]);$shipment=$s->fetch();if(!$shipment)throw new ApiException('SHIPMENT_NOT_FOUND','مرسوله یافت نشد.',404);$pdo->prepare("UPDATE shipments SET status='dispatched',version=version+1,updated_at=now() WHERE id=:id")->execute([':id'=>$shipment['id']]);$next=$shipment['order_status']==='partially_shipped'?'shipped':'partially_shipped';$pdo->prepare('UPDATE orders SET status=:status WHERE id=:id')->execute([':status'=>$next,':id'=>$shipment['order_id']]);$pdo->prepare('INSERT INTO order_status_history(order_id,previous_status,new_status,reason,actor_id) VALUES(:order,:previous,:next,:reason,:actor)')->execute([':order'=>$shipment['order_id'],':previous'=>$shipment['order_status'],':next'=>$next,':reason'=>'shipment dispatched',':actor'=>$actor]);$this->audit->record($pdo,$actor,'shipment.dispatch','shipment',$shipmentId,['status'=>$shipment['status']],['status'=>'dispatched']);return ['id'=>$shipmentId,'status'=>'dispatched'];});
    }

    public function customers(string $q = ''): array { return DB::fetchAll('SELECT public_id id,name,customer_type AS "customerType",created_at AS "createdAt" FROM users WHERE (:q=\'\' OR name ILIKE :like OR phone ILIKE :like) ORDER BY id DESC LIMIT 100', [':q'=>$q,':like'=>'%'.$q.'%']); }
    public function payments(): array { return DB::fetchAll('SELECT pa.public_id id,pa.status,pa.amount,pa.provider,pa.created_at AS "createdAt",o.public_id AS "orderId" FROM payment_attempts pa JOIN orders o ON o.id=pa.order_id ORDER BY pa.id DESC LIMIT 100'); }
    public function salesSummary(): array { return DB::fetch('SELECT count(*)::int AS "paidOrders",COALESCE(sum((snapshot->\'summary\'->>\'payable\')::bigint),0)::bigint AS revenue FROM orders WHERE payment_status=\'paid\''); }
    public function inventoryRisk(): array { return DB::fetchAll('SELECT s.public_id AS "skuId",s.code,COALESCE(sum(ib.on_hand-ib.reserved-ib.safety_stock),0)::int AS available FROM skus s LEFT JOIN inventory_balances ib ON ib.sku_id=s.id GROUP BY s.id HAVING COALESCE(sum(ib.on_hand-ib.reserved-ib.safety_stock),0)<=0 ORDER BY available LIMIT 100'); }
    public function tickets(): array { return DB::fetchAll('SELECT t.public_id id,t.title,t.status,t.category_id AS "categoryId",u.public_id AS "customerId",t.created_at AS "createdAt" FROM support_tickets t JOIN users u ON u.id=t.user_id ORDER BY t.id DESC LIMIT 100'); }
    public function ticket(string $ticketId): array { $t=DB::fetch('SELECT t.*,u.public_id AS "customerId" FROM support_tickets t JOIN users u ON u.id=t.user_id WHERE t.public_id=:ticket',[':ticket'=>$ticketId]);if(!$t)throw new ApiException('SUPPORT_TICKET_NOT_FOUND','تیکت یافت نشد.',404);$t['messages']=DB::fetchAll('SELECT public_id id,body,is_staff AS "isStaff",created_at AS "createdAt" FROM support_messages WHERE ticket_id=:ticket ORDER BY id',[':ticket'=>$t['id']]);return $t; }
    public function ticketMessage(int $actor,string $ticketId,string $body):array{return DB::transaction(function(PDO $pdo)use($actor,$ticketId,$body):array{$body=trim($body);if($body==='')throw new ApiException('VALIDATION_FAILED','متن پیام الزامی است.',422);$s=$pdo->prepare('SELECT * FROM support_tickets WHERE public_id=:ticket FOR UPDATE');$s->execute([':ticket'=>$ticketId]);$t=$s->fetch();if(!$t)throw new ApiException('SUPPORT_TICKET_NOT_FOUND','تیکت یافت نشد.',404);$id=Id::make('msg');$pdo->prepare('INSERT INTO support_messages(public_id,ticket_id,sender_id,body,is_staff)VALUES(:id,:ticket,:actor,:body,true)')->execute([':id'=>$id,':ticket'=>$t['id'],':actor'=>$actor,':body'=>$body]);$pdo->prepare("UPDATE support_tickets SET status='answered',updated_at=now() WHERE id=:id")->execute([':id'=>$t['id']]);$this->audit->record($pdo,$actor,'support.reply','ticket',$ticketId,null,['messageId'=>$id]);return ['id'=>$id,'status'=>'answered'];});}
    public function assignTicket(int $actor,string $ticketId,string $assignee):array{return $this->ticketUpdate($actor,$ticketId,['assigned_to'=>$assignee,'status'=>'in_review'],'support.assign');}
    public function ticketStatus(int $actor,string $ticketId,string $status):array{if(!in_array($status,['new','in_review','waiting_for_customer','answered','closed'],true))throw new ApiException('VALIDATION_FAILED','وضعیت تیکت معتبر نیست.',422);return $this->ticketUpdate($actor,$ticketId,['status'=>$status],'support.status');}
    private function ticketUpdate(int $actor,string $ticketId,array $changes,string $action):array{return DB::transaction(function(PDO $pdo)use($actor,$ticketId,$changes,$action):array{$s=$pdo->prepare('SELECT * FROM support_tickets WHERE public_id=:ticket FOR UPDATE');$s->execute([':ticket'=>$ticketId]);$t=$s->fetch();if(!$t)throw new ApiException('SUPPORT_TICKET_NOT_FOUND','تیکت یافت نشد.',404);$assignee=$t['assigned_to'];if(isset($changes['assigned_to'])){$u=$pdo->prepare('SELECT id FROM users WHERE public_id=:id');$u->execute([':id'=>$changes['assigned_to']]);$assignee=$u->fetchColumn();if(!$assignee)throw new ApiException('USER_NOT_FOUND','کاربر مسئول یافت نشد.',404);}$status=$changes['status']??$t['status'];$pdo->prepare('UPDATE support_tickets SET assigned_to=:assignee,status=:status,updated_at=now() WHERE id=:id')->execute([':assignee'=>$assignee,':status'=>$status,':id'=>$t['id']]);$this->audit->record($pdo,$actor,$action,'ticket',$ticketId,['status'=>$t['status']],['status'=>$status,'assignee'=>$changes['assigned_to']??null]);return ['id'=>$ticketId,'status'=>$status];});}
}
