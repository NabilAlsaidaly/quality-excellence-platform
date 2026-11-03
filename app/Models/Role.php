<?php

namespace App\Models;

use LogicException;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    protected static function booted(): void
    {
        static::saving(function (self $role): void {
            if ($role->organization_id === null || $role->guard_name !== 'web'
                || ($role->exists && $role->isDirty('organization_id'))) {
                throw new LogicException('Roles require immutable organization scope and the web guard.');
            }
        });
    }
}
