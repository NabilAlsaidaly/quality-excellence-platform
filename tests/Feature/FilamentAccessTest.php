<?php

namespace Tests\Feature;

use App\Filament\UiPreview\UiPreviewPage;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class FilamentAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_login_and_anonymous_redirect_remain_compatible(): void
    {
        $this->get('/admin/login')->assertOk();
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->assertSame('admin', Filament::getPanel('admin')->getId());
        $this->assertTrue(Route::has('filament.admin.auth.logout'));
    }

    public function test_testing_shell_does_not_grant_any_tenant_privilege(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/admin')->assertOk();
        $this->assertDatabaseCount('model_has_roles', 0);
        $this->assertDatabaseCount('organization_memberships', 0);
        $this->assertFalse($user->canAccessPanel(Panel::make()->id('unapproved')));
    }

    public function test_production_and_staging_panel_access_fail_closed_for_authenticated_user(): void
    {
        // The route collection reflects boot-time registration, not later env changes.
        $previewRoute = Route::getRoutes()->getByName('filament.admin.pages.ui-preview');
        $previewRegistered = $previewRoute !== null;
        if ($previewRegistered) {
            $this->assertSame('admin/ui-preview', $previewRoute->uri());
            $this->get('/admin/ui-preview')->assertRedirect('/admin/login');
        }
        $user = User::factory()->create();
        foreach (['production', 'staging'] as $environment) {
            $this->app->instance('env', $environment);
            $this->assertFalse($user->canAccessPanel(Filament::getPanel('admin')));
            $this->actingAs($user)->get('/admin')->assertForbidden();
            if ($previewRegistered) {
                $this->assertFalse(UiPreviewPage::canAccess());
                $this->get('/admin/ui-preview')->assertForbidden();
            } else {
                $this->get('/admin/ui-preview')->assertNotFound();
            }
        }
        $this->get('/admin/roles')->assertNotFound();
        $this->get('/admin/organizations')->assertNotFound();
    }

    public function test_fresh_production_and_staging_boots_do_not_register_preview_routes(): void
    {
        // A separate non-PHPUnit process avoids forced testing env and cached routes.
        $directory = sys_get_temp_dir().'/filament-boot-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        try {
            foreach (['production', 'staging'] as $environment) {
                $process = new Process([PHP_BINARY, '-r', <<<'PHP'
                    require $argv[1].'/vendor/autoload.php';
                    $app = require $argv[1].'/bootstrap/app.php';
                    $app->useEnvironmentPath($argv[2]);
                    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                    $routes = Illuminate\Support\Facades\Route::getRoutes();
                    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
                    $response = $kernel->handle(Illuminate\Http\Request::create('/admin/ui-preview'));
                    echo json_encode([
                        'environment' => $app->environment(),
                        'config_environment' => config('app.env'),
                        'configuration_cached' => $app->configurationIsCached(),
                        'routes_cached' => $app->routesAreCached(),
                        'preview_routes' => array_values(array_filter(array_map(
                            fn ($route) => $route->uri(), iterator_to_array($routes)
                        ), fn ($uri) => str_contains($uri, 'ui-preview'))),
                        'preview_status' => $response->getStatusCode(),
                        'login_registered' => $routes->getByName('filament.admin.auth.login') !== null,
                    ], JSON_THROW_ON_ERROR);
                    PHP, base_path(), $directory], base_path(), [
                    'APP_ENV' => $environment,
                    'APP_DEBUG' => 'false',
                    'APP_CONFIG_CACHE' => $directory.'/config.php',
                    'APP_ROUTES_CACHE' => $directory.'/routes.php',
                    'DB_URL' => '', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:',
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
                    'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
                ]);
                $process->mustRun();
                $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame($environment, $result['environment']);
                $this->assertSame($environment, $result['config_environment']);
                $this->assertFalse($result['configuration_cached']);
                $this->assertFalse($result['routes_cached']);
                $this->assertSame([], $result['preview_routes']);
                $this->assertSame(404, $result['preview_status']);
                $this->assertTrue($result['login_registered']);
            }
        } finally {
            rmdir($directory);
        }
    }
}
