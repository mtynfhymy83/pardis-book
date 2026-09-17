<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Infrastructure\Database\DB;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Id;
use PDO;

final class OrderService
{
    public function list(int $userId): array
    {
        return DB::fetchAll(
            'SELECT public_id id,order_number AS "orderNumber",status,payment_status AS "paymentStatus",snapshot->\'summary\'->>\'payable\' AS payable,created_at AS "createdAt" FROM orders WHERE user_id=:user ORDER BY id DESC',
            [':user' => $userId]
        );
    }

    public function detail(int $userId, string $orderId): array
    {
        $order = $this->owned($userId, $orderId);
        $snapshot = self::json($order['snapshot']);
        $lines = DB::fetchAll('SELECT public_id id,snapshot,quantity,unit_price AS "unitPrice",line_subtotal AS "lineSubtotal",tax FROM order_lines WHERE order_id=:order ORDER BY id', [':order' => $order['id']]);
        return $this->shape($order, $snapshot, array_map(static fn(array $line): array => ['id' => $line['id']] + self::json($line['snapshot']) + ['quantity' => (int) $line['quantity'], 'unitPrice' => (int) $line['unitPrice'], 'lineSubtotal' => (int) $line['lineSubtotal'], 'tax' => (int) $line['tax']], $lines));
    }

    public function timeline(int $userId, string $orderId): array
    {
        $order = $this->owned($userId, $orderId);
        return DB::fetchAll('SELECT previous_status AS "previousStatus",new_status AS "newStatus",reason,created_at AS "createdAt" FROM order_status_history WHERE order_id=:order ORDER BY id', [':order' => $order['id']]);
    }

    public function invoice(int $userId, string $orderId): array
    {
        $order = $this->owned($userId, $orderId);
        $invoice = DB::fetch('SELECT public_id id,invoice_number AS "invoiceNumber",storage_key AS "storageKey" FROM invoices WHERE order_id=:order', [':order' => $order['id']]);
        if (!$invoice) throw new ApiException('INVOICE_NOT_AVAILABLE', 'فاکتور هنوز آماده نیست.', 404);
        return $invoice;
    }

    public function shipments(int $userId, string $orderId): array
    {
        $order = $this->owned($userId, $orderId);
        return array_map(static fn(array $shipment): array => ['id' => $shipment['public_id'], 'status' => $shipment['status'], 'version' => (int) $shipment['version']] + self::json($shipment['data']), DB::fetchAll('SELECT public_id,status,version,data FROM shipments WHERE order_id=:order ORDER BY id', [':order' => $order['id']]));
    }

    public function tracking(int $userId, string $shipmentId): array
    {
        $shipment = DB::fetch('SELECT s.public_id,s.status,s.data FROM shipments s JOIN orders o ON o.id=s.order_id WHERE s.public_id=:shipment AND o.user_id=:user', [':shipment' => $shipmentId, ':user' => $userId]);
        if (!$shipment) throw new ApiException('SHIPMENT_NOT_FOUND', 'مرسوله یافت نشد.', 404);
        $data = self::json($shipment['data']);
        return ['shipmentId' => $shipment['public_id'], 'status' => $shipment['status'], 'trackingCode' => $data['trackingCode'] ?? null, 'provider' => $data['provider'] ?? null, 'events' => $data['trackingEvents'] ?? []];
    }

    public function cancel(int $userId, string $orderId, ?string $reason): array
    {
        return DB::transaction(function (PDO $pdo) use ($userId, $orderId, $reason): array {
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE public_id=:order AND user_id=:user FOR UPDATE');
            $stmt->execute([':order' => $orderId, ':user' => $userId]);
            $order = $stmt->fetch();
            if (!$order) throw new ApiException('ORDER_NOT_FOUND', 'سفارش یافت نشد.', 404);
            if (!in_array($order['status'], ['awaiting_payment', 'payment_review'], true)) throw new ApiException('ORDER_CANNOT_BE_CANCELLED', 'لغو در وضعیت فعلی مجاز نیست.', 409);
            $reservations = $pdo->prepare("SELECT * FROM inventory_reservations WHERE order_id=:order AND status='active' FOR UPDATE");
            $reservations->execute([':order' => $order['id']]);
            foreach ($reservations->fetchAll() as $reservation) {
                $pdo->prepare('UPDATE inventory_balances SET reserved=GREATEST(0,reserved-:quantity),version=version+1 WHERE sku_id=:sku AND warehouse_id=:warehouse')->execute([':quantity' => $reservation['quantity'], ':sku' => $reservation['sku_id'], ':warehouse' => $reservation['warehouse_id']]);
            }
            $pdo->prepare("UPDATE inventory_reservations SET status='released' WHERE order_id=:order AND status='active'")->execute([':order' => $order['id']]);
            $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=:id")->execute([':id' => $order['id']]);
            $pdo->prepare('INSERT INTO order_status_history(order_id,previous_status,new_status,reason,actor_id) VALUES(:order,:previous,:next,:reason,:actor)')->execute([':order' => $order['id'], ':previous' => $order['status'], ':next' => 'cancelled', ':reason' => $reason, ':actor' => $userId]);
            $pdo->prepare("INSERT INTO outbox_events(public_id,event_type,aggregate_type,aggregate_id,payload) VALUES(:id,'order.cancelled','order',:aggregate,CAST(:payload AS jsonb))")->execute([':id' => Id::make('evt'), ':aggregate' => (string) $order['id'], ':payload' => json_encode(['reason' => $reason], JSON_UNESCAPED_UNICODE)]);
            return ['id' => $orderId, 'status' => 'cancelled'];
        });
    }

    private function owned(int $userId, string $orderId): array
    {
        $order = DB::fetch('SELECT * FROM orders WHERE public_id=:order AND user_id=:user', [':order' => $orderId, ':user' => $userId]);
        if (!$order) throw new ApiException('ORDER_NOT_FOUND', 'سفارش یافت نشد.', 404);
        return $order;
    }

    private function shape(array $order, array $snapshot, array $lines): array
    {
        return ['id' => $order['public_id'], 'orderNumber' => $order['order_number'], 'status' => $order['status'], 'paymentStatus' => $order['payment_status'], 'createdAt' => $order['created_at'], 'reservationExpiresAt' => $order['reservation_expires_at'], 'lines' => $lines, 'address' => $snapshot['address'] ?? null, 'shipping' => $snapshot['shipping'] ?? null, 'summary' => $snapshot['summary'] ?? []];
    }

    private static function json(mixed $value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
