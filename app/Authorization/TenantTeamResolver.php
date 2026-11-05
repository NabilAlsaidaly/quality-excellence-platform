<?php

namespace App\Authorization;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Spatie\Permission\Contracts\PermissionsTeamResolver;

final class TenantTeamResolver implements PermissionsTeamResolver
{
    public function getPermissionsTeamId(): int|string|null
    {
        // Resolve scoped context at use time, never retain it in the registrar singleton.
        return app(TenantContext::class)->organizationId();
    }

    public function setPermissionsTeamId(int|string|Model|null $id): void
    {
        if ($id === null) {
            app(TenantContext::class)->clear();

            return;
        }
        throw new LogicException('Use the membership-verified TenantContext execution boundary.');
    }
}
