<?php

namespace App\Providers;

use App\Authorization\RoleManagement;
use App\Authorization\TenantAuthorization;
use App\Authorization\TenantContext;
use App\Authorization\UnavailablePermissionCommand;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Commands\AssignRoleCommand;
use Spatie\Permission\Commands\CreatePermissionCommand;
use Spatie\Permission\Commands\CreateRoleCommand;
use Spatie\Permission\Commands\UpgradeForTeamsCommand;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(TenantAuthorization::class);
        $this->app->scoped(RoleManagement::class);
        foreach ([
            CreateRoleCommand::class => 'permission:create-role',
            CreatePermissionCommand::class => 'permission:create-permission',
            AssignRoleCommand::class => 'permission:assign-role',
            UpgradeForTeamsCommand::class => 'permission:setup-teams',
        ] as $class => $name) {
            $this->app->bind($class, fn () => new UnavailablePermissionCommand($name));
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('tenant-capability', fn ($actor, $capability, $resource) => app(TenantAuthorization::class)->allows($actor, $capability, $resource));
    }
}
