<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Shared\Support\Id;
use PDO;

final class OutboxService
{
    public function publish(PDO $pdo, string $eventType, string $aggregateType, string $aggregateId, array $payload): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO outbox_events(public_id,event_type,aggregate_type,aggregate_id,payload) VALUES(:id,:event,:type,:aggregate,CAST(:payload AS jsonb))'
        );
        $statement->execute([
            ':id' => Id::make('evt'),
            ':event' => $eventType,
            ':type' => $aggregateType,
            ':aggregate' => $aggregateId,
            ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }
}
