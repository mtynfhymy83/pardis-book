<?php

declare(strict_types=1);

namespace App\Framework\Exceptions\Handler;

use App\Shared\Exceptions\HttpException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Exceptions\AccessDeniedException;
use App\Shared\Exceptions\AuthenticationException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\ForbiddenException;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\ApiResponse;
use Throwable;

/**
 * Maps any thrown exception to a structured ApiResponse array.
 */
class ExceptionHandler
{
    private bool $debug;

    public function __construct()
    {
        $this->debug = ($_ENV['APP_DEBUG'] ?? '0') === '1';
    }

    public function handle(Throwable $e): array
    {
        $this->log($e);

        return match (true) {
            $e instanceof ApiException            => ApiResponse::error($e->getMessage(), $e->getStatusCode(), $e->details, $e->fields, $e->errorCode),
            $e instanceof ValidationException     => ApiResponse::validationError($e->getErrors(), $e->getMessage()),
            $e instanceof AuthenticationException => ApiResponse::unauthorized($e->getMessage()),
            $e instanceof AccessDeniedException,
            $e instanceof ForbiddenException      => ApiResponse::forbidden($e->getMessage()),
            $e instanceof NotFoundException       => ApiResponse::notFound($e->getMessage()),
            $e instanceof BadRequestException     => ApiResponse::error($e->getMessage(), 400),
            $e instanceof HttpException           => ApiResponse::error($e->getMessage(), $e->getStatusCode(), $this->debugData($e), code: 'HTTP_ERROR'),
            default                               => $this->handleGeneric($e),
        };
    }

    private function handleGeneric(Throwable $e): array
    {
        $code = $e->getCode();
        $status = (is_int($code) && $code >= 400 && $code <= 599) ? $code : 500;
        $message = $this->debug ? $e->getMessage() : 'Internal Server Error';
        return ApiResponse::error($message, $status, $this->debugData($e), code: 'INTERNAL_ERROR');
    }

    private function debugData(Throwable $e): ?array
    {
        if (!$this->debug) {
            return null;
        }
        return [
            'exception' => get_class($e),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'trace'     => $e->getTraceAsString(),
        ];
    }

    private function log(Throwable $e): void
    {
        error_log(sprintf(
            '[Exception] %s: %s in %s:%d',
            get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
        ));
    }
}
