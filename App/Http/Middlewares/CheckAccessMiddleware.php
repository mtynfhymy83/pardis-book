<?php

declare(strict_types=1);

namespace App\Http\Middlewares;

use App\Application\Security\JwtAuthenticator;
use App\Shared\Exceptions\ApiException;

/**
 * Database-backed authorization invoked by the Router. The authenticator
 * validates both the signed access token and its persisted session; route
 * requirements may match either a role or a permission.
 */
class CheckAccessMiddleware
{
    public function __construct(private JwtAuthenticator $authenticator)
    {
    }

    /**
     * @param array<int,string> $allowedRoles
     */
    public function checkAccess(array $allowedRoles, $request = null): bool
    {
        if (!$request instanceof \Swoole\Http\Request) {
            throw new ApiException('AUTHENTICATION_REQUIRED', 'برای این عملیات باید وارد حساب شوید.', 401);
        }

        $principal = $this->authenticator->authenticate($request);
        if ($allowedRoles !== [] && !$principal->hasAny($allowedRoles)) {
            throw new ApiException('FORBIDDEN', 'مجوز انجام این عملیات را ندارید.', 403);
        }

        return true;
    }
}
