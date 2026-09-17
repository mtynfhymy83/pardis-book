<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Shared\Exceptions\ApiException;
use PDO;

final class IdempotencyService
{
    public function hash(array $payload): string
    {
        $normalized = $this->normalize($payload);
        return hash('sha256', (string) json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function replay(PDO $pdo, string $scope, string $ownerKey, string $key, array $payload): ?array
    {
        if (trim($key) === '') {
            throw new ApiException('IDEMPOTENCY_KEY_REQUIRED', 'Idempotency-Key الزامی است.', 400);
        }
        if (strlen($key) > 150) {
            throw new ApiException('VALIDATION_FAILED', 'Idempotency-Key بیش از حد طولانی است.', 422, ['Idempotency-Key' => 'حداکثر طول ۱۵۰ کاراکتر است.']);
        }

        $statement = $pdo->prepare('SELECT request_hash,response,status_code FROM idempotency_keys WHERE scope=:scope AND owner_key=:owner AND idem_key=:key FOR UPDATE');
        $statement->execute([':scope' => $scope, ':owner' => $ownerKey, ':key' => $key]);
        $stored = $statement->fetch();
        if (!$stored) {
            return null;
        }
        if (!hash_equals((string) $stored['request_hash'], $this->hash($payload))) {
            throw new ApiException('IDEMPOTENCY_KEY_REUSED', 'این کلید قبلاً با داده متفاوت استفاده شده است.', 409);
        }

        return [
            'response' => json_decode((string) $stored['response'], true, 512, JSON_THROW_ON_ERROR),
            'statusCode' => (int) $stored['status_code'],
        ];
    }

    public function store(PDO $pdo, string $scope, string $ownerKey, string $key, array $payload, array $response, int $statusCode): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO idempotency_keys(scope,owner_key,idem_key,request_hash,response,status_code) VALUES(:scope,:owner,:key,:hash,CAST(:response AS jsonb),:status)'
        );
        $statement->execute([
            ':scope' => $scope,
            ':owner' => $ownerKey,
            ':key' => $key,
            ':hash' => $this->hash($payload),
            ':response' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ':status' => $statusCode,
        ]);
    }

    private function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }
        return $value;
    }
}
