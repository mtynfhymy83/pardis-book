<?php

declare(strict_types=1);

namespace App\Application\Security;

final class AuthenticatedPrincipal
{
    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $userPublicId,
        public readonly string $sessionPublicId,
        public readonly array $roles,
        public readonly array $permissions,
    ) {
    }

    /** @param list<string> $requirements */
    public function hasAny(array $requirements): bool
    {
        if (in_array('super_admin', $this->roles, true)) {
            return true;
        }

        return array_intersect($requirements, [...$this->roles, ...$this->permissions]) !== [];
    }
}
