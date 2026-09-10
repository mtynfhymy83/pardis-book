<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

class ForbiddenException extends HttpException
{
    public function __construct(string $message = 'Forbidden', ?\Throwable $previous = null)
    {
        parent::__construct($message, 403, $previous);
    }
}
