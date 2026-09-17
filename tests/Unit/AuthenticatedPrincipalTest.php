<?php

declare(strict_types=1);

use App\Application\Security\AuthenticatedPrincipal;
use PHPUnit\Framework\TestCase;

final class AuthenticatedPrincipalTest extends TestCase
{
    public function testMatchesRolesAndPermissions(): void
    {
        $principal = new AuthenticatedPrincipal(1, 'usr_1', 'ses_1', ['catalog_manager'], ['catalog.write']);

        self::assertTrue($principal->hasAny(['catalog_manager']));
        self::assertTrue($principal->hasAny(['catalog.write']));
        self::assertFalse($principal->hasAny(['inventory.adjust']));
    }

    public function testSuperAdminBypassesRequirement(): void
    {
        $principal = new AuthenticatedPrincipal(1, 'usr_1', 'ses_1', ['super_admin'], []);
        self::assertTrue($principal->hasAny(['any.future.permission']));
    }
}
