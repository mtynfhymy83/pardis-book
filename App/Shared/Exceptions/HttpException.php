<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use RuntimeException;

class HttpException extends RuntimeException
{
    public function __construct(string $message = '', int $statusCode = 500, ?\Throwable $previous = null)
    {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        $int = (int) $this->getCode();
        return ($int >= 400 && $int <= 599) ? $int : 500;
    }
}
