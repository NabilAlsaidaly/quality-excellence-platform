<?php

use App\Authorization\TenantTeamResolver;
use App\Models\Role;
use Spatie\Permission\Models\Permission;

return [
    'models' => ['permission' => Permission::class, 'role' => Role::class],
    'table_names' => [
        'roles' => 'roles', 'permissions' => 'permissions',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles' => 'model_has_roles', 'role_has_permissions' => 'role_has_permissions',
    ],
    'column_names' => [
        'role_pivot_key' => null, 'permission_pivot_key' => null,
        'model_morph_key' => 'model_id', 'team_foreign_key' => 'organization_id',
    ],
    // Package permission checks cannot establish membership or resource scope.
    'register_permission_check_method' => false,
    'register_octane_reset_listener' => false,
    'events_enabled' => false,
    'teams' => true,
    'team_resolver' => TenantTeamResolver::class,
    'use_passport_client_credentials' => false,
    'display_permission_in_exception' => false,
    'display_role_in_exception' => false,
    'enable_wildcard_permission' => false,
    // Definitions only. Trusted authorization reads current database relations.
    'cache' => [
        'expiration_time' => DateInterval::createFromDateString('24 hours'),
        'key' => 'quality.permission.definitions',
        'store' => 'array',
    ],
];
