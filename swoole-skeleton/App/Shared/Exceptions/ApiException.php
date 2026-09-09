<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

final class ApiException extends HttpException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        int $statusCode = 400,
        public readonly array $fields = [],
        public readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
