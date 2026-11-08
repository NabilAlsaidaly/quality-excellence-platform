<?php

namespace Tests\Feature\Filament;

use App\Filament\Institution\InstitutionAuthenticate;
use App\Filament\Institution\InstitutionPanel;
use App\Filament\Institution\InstitutionPanelEnvironment;
use App\Filament\Institution\InstitutionPanelSession;
use App\Filament\Institution\Resources\ApproachResource;
use App\Filament\Platform\PlatformPanel;
use App\Identity\PlatformUserProvider;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PanelSeparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_institution_registration_preserves_distinct_guards_and_denied_platform_and_real_users(): void
    {
        $platform = PlatformPanel::make();
        $institution = InstitutionPanel::make();
        $this->assertSame('platform', $platform->getAuthGuard());
        $this->assertSame('institution', $institution->getAuthGuard());
        foreach ([$platform, $institution] as $panel) {
            $this->assertContains(PreventRequestForgery::class, $panel->getMiddleware());
            $this->assertContains(AuthenticateSession::class, $panel->getMiddleware());
            $this->assertContains($panel->getId() === 'institution' ? InstitutionAuthenticate::class : Authenticate::class, $panel->getAuthMiddleware());
            $this->assertFalse($panel->hasLogin());
        }
        $this->assertSame([], $platform->getResources());
        $this->assertSame([ApproachResource::class], $institution->getResources());
        $this->assertSame(['admin', 'institution'], array_keys(Filament::getPanels()));
        $this->assertArrayNotHasKey('platform', config('auth.guards'));
        $this->assertNotContains(ApproachResource::class, Filament::getPanel('admin')->getResources());
        $this->assertSame('resources/css/filament/admin/theme.css', $institution->getViteTheme());
        $this->assertSame(Filament::getPanel('admin')->getColors(), $institution->getColors());
        $legacy = User::factory()->create();
        $organization = Organization::factory()->create();
        $institutionActor = User::factory()->create(['principal_type' => 'institution', 'organization_id' => $organization->id]);
        $platformActor = User::factory()->create(['principal_type' => 'platform']);
        foreach ([$legacy, $institutionActor, $platformActor] as $actor) {
            $this->assertFalse($actor->canAccessPanel($institution));
            $this->assertFalse($actor->canAccessPanel($platform));
        }
        $this->assertTrue($legacy->canAccessPanel(Filament::getPanel('admin')));
        $this->assertFalse($institutionActor->canAccessPanel(Filament::getPanel('admin')));
        $this->assertFalse($platformActor->canAccessPanel(Filament::getPanel('admin')));
        $this->assertSame([], config('authorization.capabilities'));
        foreach (Route::getRoutes() as $route) {
            $this->assertFalse(str_starts_with($route->uri(), 'platform'));
            if (str_starts_with($route->uri(), 'institution/workspace')) {
                $this->assertContains(InstitutionPanelEnvironment::class, $route->gatherMiddleware());
                $this->assertContains(InstitutionPanelSession::class, $route->gatherMiddleware());
            }
        }
    }

    public function test_unregistered_platform_provider_filters_credentials_id_and_remember_restoration(): void
    {
        $platform = User::factory()->create(['principal_type' => 'platform', 'email' => 'synthetic44@example.test']);
        $legacy = User::factory()->create();
        $organization = Organization::factory()->create();
        $institution = User::factory()->create(['principal_type' => 'institution', 'organization_id' => $organization->id, 'email' => $platform->email]);
        $provider = new PlatformUserProvider(app('hash'), User::class);
        $this->assertSame($platform->id, $provider->retrieveByCredentials(['email' => ' SYNTHETIC44@example.test ', 'password' => 'password'])->id);
        $this->assertTrue($provider->validateCredentials($platform, ['password' => 'password']));
        $this->assertFalse($provider->validateCredentials($platform, ['password' => 'wrong']));
        $this->assertNull($provider->retrieveByCredentials(['email' => $legacy->email]));
        $this->assertNull($provider->retrieveByCredentials(['email' => $platform->email, 'organization_id' => $organization->id]));
        foreach ([null, 'web', 'institution'] as $marker) {
            app('session.store')->put('identity.active_guard', $marker);
            $this->assertNull($provider->retrieveById($platform->id));
            $this->assertNull($provider->retrieveByToken($platform->id, $platform->remember_token));
        }
        app('session.store')->put('identity.active_guard', 'platform');
        $this->assertSame($platform->id, $provider->retrieveById($platform->id)->id);
        $this->assertSame($platform->id, $provider->retrieveByToken($platform->id, $platform->remember_token)->id);
        $this->assertNull($provider->retrieveByToken($platform->id, 'forged'));
        $this->assertNull($provider->retrieveById($legacy->id));
        $this->assertNull($provider->retrieveById($institution->id));
        app('session.store')->put('institution.organization', $organization->public_id);
        $this->assertNull($provider->retrieveById($platform->id));
        $this->assertNull(Auth::createUserProvider('users')->retrieveByCredentials(['email' => $platform->email, 'password' => 'password']));
        $this->assertTrue(Hash::check('password', $platform->password));
    }
}
