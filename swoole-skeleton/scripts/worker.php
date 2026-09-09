<?php
declare(strict_types=1);
use App\Framework\Bootstrap\EnvironmentManager;
$base=dirname(__DIR__);require $base.'/vendor/autoload.php';EnvironmentManager::initialize();
$pdo=new PDO((string)$_ENV['DB_DSN'],(string)$_ENV['DB_USERNAME'],(string)$_ENV['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
while(true){
    $pdo->beginTransaction();
    $expired=$pdo->query("SELECT * FROM inventory_reservations WHERE status='active' AND expires_at<=now() FOR UPDATE SKIP LOCKED LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    foreach($expired as $reservation){$pdo->prepare('UPDATE inventory_balances SET reserved=reserved-:q,version=version+1 WHERE sku_id=:s AND warehouse_id=:w')->execute([':q'=>$reservation['quantity'],':s'=>$reservation['sku_id'],':w'=>$reservation['warehouse_id']]);$pdo->prepare("UPDATE inventory_reservations SET status='released' WHERE id=:id")->execute([':id'=>$reservation['id']]);$pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=:id AND status='awaiting_payment'")->execute([':id'=>$reservation['order_id']]);}
    $row=$pdo->query("SELECT * FROM outbox_events WHERE processed_at IS NULL AND available_at<=now() ORDER BY id FOR UPDATE SKIP LOCKED LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if(!$row){$pdo->commit();sleep(1);continue;}
    try{$pdo->prepare('UPDATE outbox_events SET processed_at=now() WHERE id=:id')->execute([':id'=>$row['id']]);$pdo->commit();}
    catch(Throwable $e){$pdo->prepare("UPDATE outbox_events SET attempts=attempts+1,last_error=:e,available_at=now()+interval '30 seconds' WHERE id=:id")->execute([':e'=>$e->getMessage(),':id'=>$row['id']]);$pdo->commit();}
}
