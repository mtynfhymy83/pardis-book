<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

class AccessDeniedException extends HttpException
{
    public function __construct(string $message = 'Access denied', int $statusCode = 403, ?\Throwable $previous = null)
    {
        parent::__construct($message, $statusCode, $previous);
    }
}
