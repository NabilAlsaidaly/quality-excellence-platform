<?php

namespace Tests\Feature;

use App\Authorization\TenantContext;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InstitutionGuardTransitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Only a test adapter: exercise the same real session guard as Filament.
        Route::middleware('web')->post('/_test/legacy-login', function (Request $request) {
            abort_unless(Auth::guard('web')->attempt($request->only('email', 'password'), true), 422);

            return redirect('/admin');
        });
    }

    private function accounts(): array
    {
        $org = Organization::factory()->create();
        $account = User::factory()->create(['principal_type' => 'institution', 'organization_id' => $org->id,
            'email' => 'employee@example.test', 'password' => 'institution-account-password']);
        OrganizationMembership::factory()->create(['user_id' => $account->id, 'organization_id' => $org->id]);
        $legacy = User::factory()->create(['email' => $account->email, 'password' => 'legacy-account-password']);

        return [$org, $account, $legacy];
    }

    private function legacyLogin(User $legacy): void
    {
        $this->post('/_test/legacy-login', ['email' => $legacy->email, 'password' => 'legacy-account-password', '_token' => session()->token()])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($legacy, 'web');
    }

    private function institutionLogin(Organization $org)
    {
        return $this->post('/institution/login', ['organization' => $org->public_id,
            'email' => 'EMPLOYEE@example.test', 'password' => 'institution-account-password', '_token' => session()->token()]);
    }

    public function test_legacy_browser_transitions_to_institution_without_retaining_admin_authentication(): void
    {
        [$org, $account, $legacy] = $this->accounts();
        $this->legacyLogin($legacy);
        $this->get('/admin')->assertOk();
        $session = session()->getId();
        $csrf = session()->token();
        $remember = $legacy->fresh()->remember_token;
        $this->institutionLogin($org)->assertRedirect('/institution/'.$org->public_id.'/account');
        $this->assertAuthenticatedAs($account, 'institution');
        $this->assertGuest('web');
        $this->assertNotSame($session, session()->getId());
        $this->assertNotSame($csrf, session()->token());
        $this->assertFalse(session()->has(Auth::guard('web')->getName()));
        $this->assertSame($remember, $legacy->fresh()->remember_token);
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/institution/'.$org->public_id.'/account')->assertOk();
        $this->assertNull(app(TenantContext::class)->actorId());
        $this->assertSame([], config('authorization.capabilities'));
    }

    public static function deniedLoginAttempts(): array
    {
        return ['wrong password' => ['password'], 'revoked membership' => ['revoked'], 'forged institution' => ['scope']];
    }

    public function test_real_csrf_token_rotates_on_transition_and_old_token_cannot_log_out_the_new_account(): void
    {
        $this->app->instance('env', 'local');
        [$org, $account, $legacy] = $this->accounts();
        $this->get('/institution/login')->assertOk();
        $this->legacyLogin($legacy);
        $oldToken = session()->token();
        $this->institutionLogin($org)->assertRedirect();
        $this->post('/institution/'.$org->public_id.'/logout', ['_token' => $oldToken])->assertStatus(419);
        $this->assertAuthenticatedAs($account, 'institution');
        $this->assertGuest('web');
        $currentToken = session()->token();
        $this->assertNotSame($oldToken, $currentToken);
        $this->post('/institution/'.$org->public_id.'/logout', ['_token' => $currentToken])->assertRedirect('/institution/login');
        $this->assertGuest('institution');
        $this->assertGuest('web');
        $this->assertNotSame($currentToken, session()->token());
    }

    public function test_throttled_institution_attempt_also_ends_a_prior_legacy_context(): void
    {
        [$org, $account, $legacy] = $this->accounts();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/institution/login', ['organization' => $org->public_id,
                'email' => $account->email, 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        }
        $this->legacyLogin($legacy);
        $this->postJson('/institution/login', ['organization' => $org->public_id,
            'email' => $account->email, 'password' => 'institution-account-password'])->assertStatus(429);
        $this->assertGuest('web');
        $this->assertGuest('institution');
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_old_unmarked_institution_session_fails_closed_without_restoring_legacy_access(): void
    {
        [$org, $account, $legacy] = $this->accounts();
        $this->institutionLogin($org)->assertRedirect();
        $this->withSession(['identity.active_guard' => null, Auth::guard('web')->getName() => $legacy->id]);
        $this->assertNull(session('identity.active_guard'));
        Auth::forgetGuards();
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/institution/'.$org->public_id.'/account')->assertRedirect('/institution/login');
        $this->assertGuest('web');
        $this->assertGuest('institution');
        $this->assertNull(app(TenantContext::class)->actorId());
    }

    #[DataProvider('deniedLoginAttempts')]
    public function test_failed_institution_login_cannot_fall_back_to_the_prior_legacy_context(string $failure): void
    {
        [$org, $account, $legacy] = $this->accounts();
        $this->legacyLogin($legacy);
        if ($failure === 'revoked') {
            $account->memberships()->delete();
        }
        $this->post('/institution/login', ['organization' => $failure === 'scope' ? Organization::factory()->create()->public_id : $org->public_id,
            'email' => $account->email, 'password' => $failure === 'password' ? 'wrong-password' : 'institution-account-password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest('web');
        $this->assertGuest('institution');
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->assertNull(app(TenantContext::class)->organizationId());
    }

    public function test_legacy_session_id_and_valid_recaller_cannot_revive_web_inside_institution_context(): void
    {
        [$org, $account, $legacy] = $this->accounts();
        $this->legacyLogin($legacy);
        $web = Auth::guard('web');
        $recallerName = $web->getRecallerName();
        $recaller = $legacy->id.'|'.$legacy->fresh()->remember_token.'|'.$web->hashPasswordForCookie($legacy->password);
        $webKey = $web->getName();
        $this->institutionLogin($org)->assertRedirect();
        // Simulate a new HTTP request, including a stale guard ID and real cookie.
        Auth::forgetGuards();
        $this->withSession([$webKey => $legacy->id])->withCookie($recallerName, $recaller)
            ->get('/admin')->assertRedirect('/admin/login');
        $this->assertGuest('web');
        $this->get('/institution/'.$org->public_id.'/account')->assertOk();
        $this->assertAuthenticatedAs($account, 'institution');
        $this->assertNull(Auth::createUserProvider('users')->retrieveByToken($legacy->id, $legacy->fresh()->remember_token));
    }

    public function test_logout_clears_both_current_device_guards_and_cookies_without_rotating_other_device_tokens(): void
    {
        [$org, $account, $legacy] = $this->accounts();
        $this->legacyLogin($legacy);
        $legacyRemember = $legacy->fresh()->remember_token;
        $accountRemember = $account->remember_token;
        $web = Auth::guard('web');
        $recaller = $legacy->id.'|'.$legacyRemember.'|'.$web->hashPasswordForCookie($legacy->password);
        $this->institutionLogin($org)->assertRedirect();
        $session = session()->getId();
        $csrf = session()->token();
        // Also clean up an in-memory mixed-guard context from an older binary.
        $web->setUser($legacy);
        $this->withCookie($web->getRecallerName(), $recaller)->post('/institution/'.$org->public_id.'/logout')
            ->assertRedirect('/institution/login')->assertCookieExpired($web->getRecallerName());
        $this->assertGuest('web');
        $this->assertGuest('institution');
        $this->assertNotSame($session, session()->getId());
        $this->assertNotSame($csrf, session()->token());
        $this->assertNull(session('institution.organization'));
        $this->assertNull(session('institution.password_hash'));
        $this->assertSame($legacyRemember, $legacy->fresh()->remember_token);
        $this->assertSame($accountRemember, $account->fresh()->remember_token);
        $this->assertNull(app(TenantContext::class)->actorId());
        // A separate clean browser may still use its own valid legacy recaller.
        Auth::forgetGuards();
        session()->invalidate();
        $this->get('/admin')->assertOk();
        $this->assertAuthenticatedAs($legacy, 'web');
        $this->assertGuest('institution');
    }

    public function test_real_filament_login_replaces_institution_context_instead_of_coexisting_with_it(): void
    {
        [$org, $account, $legacy] = $this->accounts();
        $this->institutionLogin($org)->assertRedirect();
        $session = session()->getId();
        $csrf = session()->token();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(Login::class)->fillForm(['email' => $legacy->email, 'password' => 'legacy-account-password', 'remember' => false])
            ->call('authenticate')->assertHasNoFormErrors()->assertRedirect();
        $this->assertAuthenticatedAs($legacy, 'web');
        $this->assertGuest('institution');
        $this->assertNotSame($session, session()->getId());
        $this->assertNotSame($csrf, session()->token());
        $this->assertNull(session('institution.organization'));
        $this->assertNull(session('institution.password_hash'));
        $this->assertNull(Auth::createUserProvider('institution')->retrieveById($account->id));
        $this->assertNull(Auth::createUserProvider('institution')->retrieveByToken($account->id, $account->remember_token));
        $this->get('/admin')->assertOk();
        $this->get('/institution/'.$org->public_id.'/account')->assertRedirect('/institution/login');
        $this->assertNull(app(TenantContext::class)->organizationId());
    }

    public static function staleInstitutionRequests(): array
    {
        return ['membership revoked' => ['revoked'], 'password changed' => ['password'], 'foreign route' => ['scope']];
    }

    #[DataProvider('staleInstitutionRequests')]
    public function test_stale_or_foreign_institution_context_never_restores_prior_admin_access(string $failure): void
    {
        [$org, $account, $legacy] = $this->accounts();
        $this->legacyLogin($legacy);
        $this->institutionLogin($org)->assertRedirect();
        if ($failure === 'revoked') {
            $account->memberships()->delete();
        } elseif ($failure === 'password') {
            $account->password = 'changed-account-password';
            $account->save();
        }
        $locator = $failure === 'scope' ? Organization::factory()->create()->public_id : $org->public_id;
        $this->get('/institution/'.$locator.'/account')->assertRedirect('/institution/login');
        $this->assertGuest('web');
        $this->assertGuest('institution');
        $this->assertNull(app(TenantContext::class)->actorId());
        $this->get('/admin')->assertRedirect('/admin/login');
    }
}
