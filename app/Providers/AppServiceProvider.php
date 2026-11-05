<?php

namespace App\Providers;

use App\Actions\Identity\BrowserAccountSession;
use App\Authorization\RoleManagement;
use App\Authorization\TenantAuthorization;
use App\Authorization\TenantContext;
use App\Authorization\UnavailablePermissionCommand;
use App\Identity\AccountPasswordBrokerManager;
use App\Identity\InstitutionUserProvider;
use App\Identity\LegacyUserProvider;
use App\Models\User;
use App\Policies\InstitutionAccountPolicy;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
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
        // Extension also applies when the framework's deferred broker is loaded.
        $this->app->extend('auth.password', fn ($manager, $app) => new AccountPasswordBrokerManager($app));
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
        Event::listen(Login::class, [BrowserAccountSession::class, 'authenticated']);
        Auth::provider('institution', fn ($app, $config) => new InstitutionUserProvider($app['hash'], $config['model']));
        Auth::provider('legacy', fn ($app, $config) => new LegacyUserProvider($app['hash'], $config['model']));
        Gate::define('institution-account', fn (User $actor, string $organization) => app(InstitutionAccountPolicy::class)->view($actor, $organization));
        Gate::define('tenant-capability', fn ($actor, $capability, $resource) => app(TenantAuthorization::class)->allows($actor, $capability, $resource));
    }
}
