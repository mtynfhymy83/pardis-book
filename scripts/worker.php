<?php

declare(strict_types=1);

use App\Framework\Bootstrap\EnvironmentManager;
use App\Infrastructure\Providers\S3ObjectStorage;
use App\Shared\Support\Id;

$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
EnvironmentManager::initialize();

$pdo = new PDO(
    (string) $_ENV['DB_DSN'],
    (string) $_ENV['DB_USERNAME'],
    (string) $_ENV['DB_PASSWORD'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$maximumAttempts = max(1, (int) ($_ENV['OUTBOX_MAX_ATTEMPTS'] ?? 10));
$pollMilliseconds = max(100, (int) ($_ENV['WORKER_POLL_MILLISECONDS'] ?? 1000));
$lastCleanupAt = 0;

while (true) {
    releaseExpiredReservations($pdo);
    processReportExport($pdo);
    processOutboxEvent($pdo, $maximumAttempts);

    if (time() - $lastCleanupAt >= 3600) {
        cleanupExpiredData($pdo);
        $lastCleanupAt = time();
    }

    usleep($pollMilliseconds * 1000);
}

function processReportExport(PDO $pdo): void
{
    $job = null;
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->query("SELECT * FROM report_exports WHERE status='queued' ORDER BY id FOR UPDATE SKIP LOCKED LIMIT 1");
        $job = $stmt->fetch();
        if (!$job) {$pdo->commit(); return;}
        $pdo->prepare("UPDATE report_exports SET status='processing' WHERE id=:id")->execute([':id' => $job['id']]);
        $pdo->commit();
        $csv = reportExportCsv($pdo, (string) $job['report_type']);
        $key = 'private/reports/' . $job['requested_by'] . '/' . $job['public_id'] . '.csv';
        (new S3ObjectStorage())->putString($key, $csv, 'text/csv; charset=utf-8');
        $pdo->prepare("UPDATE report_exports SET status='completed',storage_key=:key,completed_at=now() WHERE id=:id AND status='processing'")->execute([':key' => $key, ':id' => $job['id']]);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (is_array($job)) $pdo->prepare("UPDATE report_exports SET status='failed',error_code='EXPORT_FAILED',completed_at=now() WHERE id=:id")->execute([':id' => $job['id']]);
        error_log('[worker.report_export] ' . $exception->getMessage());
    }
}

function reportExportCsv(PDO $pdo, string $type): string
{
    $sql = match ($type) {
        'sales-summary' => "SELECT order_number,status,payment_status,(snapshot->'summary'->>'payable') AS payable,created_at FROM orders WHERE payment_status='paid' ORDER BY id",
        'top-products' => "SELECT s.code,p.title,SUM(ol.quantity) AS quantity,SUM(ol.line_subtotal) AS revenue FROM order_lines ol JOIN orders o ON o.id=ol.order_id JOIN skus s ON s.id=ol.sku_id JOIN products p ON p.id=s.product_id WHERE o.payment_status='paid' GROUP BY s.code,p.title ORDER BY quantity DESC",
        'inventory-risk' => "SELECT s.code,COALESCE(SUM(ib.on_hand-ib.reserved-ib.safety_stock),0) AS available FROM skus s LEFT JOIN inventory_balances ib ON ib.sku_id=s.id GROUP BY s.code HAVING COALESCE(SUM(ib.on_hand-ib.reserved-ib.safety_stock),0)<=0 ORDER BY available",
        'customers' => "SELECT u.public_id,u.name,u.customer_type,COUNT(o.id) AS orders FROM users u LEFT JOIN orders o ON o.user_id=u.id AND o.payment_status='paid' GROUP BY u.id ORDER BY orders DESC",
        default => throw new RuntimeException('Unsupported report type.'),
    };
    $rows = $pdo->query($sql)->fetchAll();
    $out = fopen('php://temp', 'r+'); fwrite($out, "\xEF\xBB\xBF");
    if ($rows !== []) { fputcsv($out, array_keys($rows[0])); foreach ($rows as $row) fputcsv($out, $row); }
    rewind($out); return (string) stream_get_contents($out);
}

function releaseExpiredReservations(PDO $pdo): void
{
    $pdo->beginTransaction();
    try {
        $statement = $pdo->query(
            "SELECT * FROM inventory_reservations WHERE status='active' AND expires_at<=now() FOR UPDATE SKIP LOCKED LIMIT 100"
        );
        foreach ($statement->fetchAll() as $reservation) {
            $pdo->prepare('UPDATE inventory_balances SET reserved=GREATEST(0,reserved-:quantity),version=version+1 WHERE sku_id=:sku AND warehouse_id=:warehouse')
                ->execute([':quantity' => $reservation['quantity'], ':sku' => $reservation['sku_id'], ':warehouse' => $reservation['warehouse_id']]);
            $pdo->prepare("UPDATE inventory_reservations SET status='released' WHERE id=:id AND status='active'")
                ->execute([':id' => $reservation['id']]);
            $changed = $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=:id AND status='awaiting_payment'");
            $changed->execute([':id' => $reservation['order_id']]);
            if ($changed->rowCount() === 1) {
                $pdo->prepare("INSERT INTO order_status_history(order_id,previous_status,new_status,reason) VALUES(:order,'awaiting_payment','cancelled','payment_timeout')")
                    ->execute([':order' => $reservation['order_id']]);
            }

            $payload = json_encode(['reservationId' => $reservation['public_id'], 'reason' => 'payment_timeout'], JSON_THROW_ON_ERROR);
            $pdo->prepare("INSERT INTO outbox_events(public_id,event_type,aggregate_type,aggregate_id,payload) VALUES(:public,'inventory.released','order',:aggregate,CAST(:payload AS jsonb))")
                ->execute([':public' => Id::make('evt'), ':aggregate' => (string) $reservation['order_id'], ':payload' => $payload]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[worker.reservations] ' . $exception->getMessage());
    }
}

function processOutboxEvent(PDO $pdo, int $maximumAttempts): void
{
    $event = null;
    $pdo->beginTransaction();
    try {
        $statement = $pdo->query(
            'SELECT * FROM outbox_events WHERE processed_at IS NULL AND dead_lettered_at IS NULL AND available_at<=now() ORDER BY id FOR UPDATE SKIP LOCKED LIMIT 1'
        );
        $event = $statement->fetch();
        if (!$event) {
            $pdo->commit();
            return;
        }

        $pdo->prepare('UPDATE outbox_events SET locked_at=now() WHERE id=:id')->execute([':id' => $event['id']]);

        // Phase-zero local dispatcher. Provider-specific listeners are attached in later phases.
        error_log(sprintf(
            '[outbox] event=%s id=%s aggregate=%s:%s',
            $event['event_type'],
            $event['public_id'],
            $event['aggregate_type'],
            $event['aggregate_id']
        ));

        $pdo->prepare('UPDATE outbox_events SET processed_at=now(),locked_at=NULL,last_error=NULL WHERE id=:id')
            ->execute([':id' => $event['id']]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if (!is_array($event)) {
            error_log('[worker.outbox] ' . $exception->getMessage());
            return;
        }

        $attempts = ((int) ($event['attempts'] ?? 0)) + 1;
        $delaySeconds = min(3600, 30 * (2 ** min($attempts - 1, 7)));
        try {
            $pdo->beginTransaction();
            $statement = $pdo->prepare(
                'UPDATE outbox_events SET attempts=:attempts,last_error=:error,locked_at=NULL,available_at=now()+(:delay * interval \'1 second\'),dead_lettered_at=CASE WHEN :attempts>=:maximum THEN now() ELSE NULL END WHERE id=:id'
            );
            $statement->execute([
                ':attempts' => $attempts,
                ':error' => substr($exception->getMessage(), 0, 2000),
                ':delay' => $delaySeconds,
                ':maximum' => $maximumAttempts,
                ':id' => $event['id'],
            ]);
            $pdo->commit();
        } catch (Throwable $retryException) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[worker.outbox.retry] ' . $retryException->getMessage());
        }
        error_log('[worker.outbox] ' . $exception->getMessage());
    }
}

function cleanupExpiredData(PDO $pdo): void
{
    try {
        $pdo->exec("DELETE FROM otp_challenges WHERE expires_at < now()-interval '1 day'");
        $pdo->exec("DELETE FROM sessions WHERE expires_at < now()-interval '30 days' OR revoked_at < now()-interval '30 days'");
        $pdo->exec("DELETE FROM idempotency_keys WHERE expires_at < now()");
    } catch (Throwable $exception) {
        error_log('[worker.cleanup] ' . $exception->getMessage());
    }
}
