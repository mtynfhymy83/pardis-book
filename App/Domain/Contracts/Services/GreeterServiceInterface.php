<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Services;

/**
 * Domain contract. Controllers depend on this interface, never on the concrete
 * implementation. The binding lives in Framework/Config/di.php.
 */
interface GreeterServiceInterface
{
    /**
     * @return array{message:string, name:string}
     */
    public function greet(string $name): array;
}
