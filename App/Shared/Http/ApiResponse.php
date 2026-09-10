<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Framework\Coroutine\Context;

final class ApiResponse
{
    public static function success(mixed $data = null, string $message = 'OK', int $status = 200, array $meta = []): array
    {
        return ['data' => $data, 'meta' => self::meta($meta), '__status' => $status];
    }

    public static function error(string|array $message = 'Error', int $status = 400, mixed $details = null, array $fields = [], string $code = 'BAD_REQUEST'): array
    {
        return [
            'error' => [
                'code' => $code,
                'message' => is_string($message) ? $message : 'Error',
                'fields' => $fields === [] ? new \stdClass() : $fields,
                'details' => $details ?? new \stdClass(),
            ],
            'meta' => self::meta(),
            '__status' => $status,
        ];
    }

    public static function created(mixed $data = null, string $message = 'Created'): array { return self::success($data, $message, 201); }
    public static function updated(mixed $data = null, string $message = 'Updated'): array { return self::success($data, $message); }
    public static function deleted(string $message = 'Deleted'): array { return self::success(null, $message, 204); }
    public static function notFound(string $message = 'Not found'): array { return self::error($message, 404, code: 'NOT_FOUND'); }
    public static function unauthorized(string $message = 'Unauthorized'): array { return self::error($message, 401, code: 'UNAUTHENTICATED'); }
    public static function forbidden(string $message = 'Forbidden'): array { return self::error($message, 403, code: 'FORBIDDEN'); }
    public static function validationError(array $errors, string $message = 'Validation failed'): array { return self::error($message, 422, fields: $errors, code: 'VALIDATION_FAILED'); }
    public static function serverError(string $message = 'Internal server error'): array { return self::error($message, 500, code: 'INTERNAL_ERROR'); }

    public static function paginated(array $items, int $total, int $page, int $perPage, string $message = 'OK'): array
    {
        return self::success($items, $message, 200, [
            'page' => $page, 'pageSize' => $perPage, 'total' => $total,
            'totalPages' => $perPage > 0 ? (int) ceil($total / $perPage) : 1,
        ]);
    }

    public static function collection(array $items, string $message = 'OK'): array
    {
        return self::success($items, $message, 200, ['count' => count($items)]);
    }

    private static function meta(array $extra = []): array
    {
        return $extra + [
            'requestId' => Context::get('request_id', 'req_' . bin2hex(random_bytes(12))),
            'serverTime' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }
}
