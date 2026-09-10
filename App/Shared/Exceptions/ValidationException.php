<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use Exception;

class ValidationException extends Exception
{
    public function __construct(
        protected array $errors,
        string $message = 'Validation failed',
        protected int $statusCode = 422
    ) {
        parent::__construct($message);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
