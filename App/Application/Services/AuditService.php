<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Framework\Coroutine\Context;
use App\Shared\Support\Id;
use PDO;

final class AuditService
{
    public function record(PDO $pdo, ?int $actorId, string $action, string $subjectType, string $subjectId, ?array $before = null, ?array $after = null): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO audit_logs(public_id,actor_id,action,subject_type,subject_id,before_data,after_data,request_id) VALUES(:id,:actor,:action,:type,:subject,CAST(:before AS jsonb),CAST(:after AS jsonb),:request)'
        );
        $statement->execute([
            ':id' => Id::make('aud'),
            ':actor' => $actorId,
            ':action' => $action,
            ':type' => $subjectType,
            ':subject' => $subjectId,
            ':before' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ':after' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ':request' => (string) Context::get('request_id', ''),
        ]);
    }
}
