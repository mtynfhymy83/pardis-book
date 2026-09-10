<?php

declare(strict_types=1);

namespace App\Domain\Services;

use App\Domain\Contracts\Services\GreeterServiceInterface;

/**
 * Business logic lives here, free of HTTP/Swoole concerns. In a real feature
 * this service would depend on a RepositoryInterface (also bound in di.php)
 * to reach the database.
 */
class GreeterService implements GreeterServiceInterface
{
    public function greet(string $name): array
    {
        $name = trim($name) !== '' ? trim($name) : 'World';
        return [
            'message' => "Hello, {$name}!",
            'name'    => $name,
        ];
    }
}
