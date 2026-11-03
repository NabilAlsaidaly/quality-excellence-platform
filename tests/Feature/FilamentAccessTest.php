<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
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
        $user = User::factory()->create();
        foreach (['production', 'staging'] as $environment) {
            $this->app->instance('env', $environment);
            $this->assertFalse($user->canAccessPanel(Filament::getPanel('admin')));
            $this->actingAs($user)->get('/admin')->assertForbidden();
        }
        $this->get('/admin/ui-preview')->assertNotFound();
        $this->get('/admin/roles')->assertNotFound();
        $this->get('/admin/organizations')->assertNotFound();
    }
}
