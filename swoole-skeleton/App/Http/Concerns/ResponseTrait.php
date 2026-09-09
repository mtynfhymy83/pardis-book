<?php

declare(strict_types=1);

namespace App\Http\Concerns;

use App\Shared\Http\ApiResponse;

/**
 * Response helpers for controllers and the router.
 */
trait ResponseTrait
{
    public function sendResponse($data = null, $message = '', $error = false, $status = 200): array
    {
        if ($error) {
            return ApiResponse::error($message, $status, $data);
        }
        return ApiResponse::success($data, $message ?: 'OK', $status);
    }

    protected function ok(mixed $data = null, string $message = 'OK'): array
    {
        return ApiResponse::success($data, $message);
    }

    protected function created(mixed $data = null, string $message = 'Created'): array
    {
        return ApiResponse::created($data, $message);
    }

    protected function updated(mixed $data = null, string $message = 'Updated'): array
    {
        return ApiResponse::updated($data, $message);
    }

    protected function deleted(string $message = 'Deleted'): array
    {
        return ApiResponse::deleted($message);
    }

    protected function notFound(string $message = 'Not found'): array
    {
        return ApiResponse::notFound($message);
    }

    protected function badRequest(string $message = 'Bad request'): array
    {
        return ApiResponse::error($message, 400);
    }

    protected function validationError(array $errors, string $message = 'Validation failed'): array
    {
        return ApiResponse::validationError($errors, $message);
    }

    protected function paginated(array $items, int $total, int $page, int $perPage, string $message = ''): array
    {
        return ApiResponse::paginated($items, $total, $page, $perPage, $message ?: 'OK');
    }

    protected function collection(array $items, string $message = 'OK'): array
    {
        return ApiResponse::collection($items, $message);
    }

    protected function getBody($request): array
    {
        if (!$request) {
            return [];
        }
        $content = null;
        if (is_object($request) && method_exists($request, 'rawContent')) {
            $content = $request->rawContent();
        }
        if (empty($content)) {
            return $request->post ?? [];
        }
        $json = json_decode($content, true);
        return is_array($json) ? $json : ($request->post ?? []);
    }
}
