<?php

namespace Tests\Feature;

use App\Actions\Identity\InstitutionRecovery;
use App\Authorization\TenantContext;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Notifications\InstitutionPasswordReset;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InstitutionEntryTest extends TestCase
{
    use RefreshDatabase;

    private function account(Organization $org, string $password = 'first-account-password'): User
    {
        $account = User::factory()->create(['principal_type' => 'institution', 'organization_id' => $org->id,
            'email' => 'employee@example.test', 'password' => $password, 'email_verified_at' => null]);
        OrganizationMembership::factory()->create(['user_id' => $account->id, 'organization_id' => $org->id]);

        return $account;
    }

    private function login(Organization $org, string $password = 'first-account-password')
    {
        return $this->post('/institution/login', ['organization' => $org->public_id, 'email' => 'EMPLOYEE@example.test', 'password' => $password]);
    }

    public function test_arabic_rtl_entry_does_not_disclose_an_institution_directory(): void
    {
        Organization::factory()->create(['name' => 'Private synthetic organization name']);
        $this->get('/institution/login')->assertOk()->assertSee('lang="ar"', false)->assertSee('dir="rtl"', false)
            ->assertSee('تسجيل الدخول')->assertDontSee('Private synthetic organization name')
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_equal_email_accounts_require_independent_login_logout_and_session_regeneration(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $a = $this->account($org);
        $b = $this->account($other, 'second-account-password');
        $this->get('/institution/login');
        $old = session()->getId();
        $this->withSession(['url.intended' => 'https://example.invalid/foreign'])->login($org)
            ->assertRedirect(route('institution.account', ['organization' => $org->public_id]));
        $this->assertNotSame($old, session()->getId());
        $this->assertAuthenticatedAs($a, 'institution');
        $this->assertGuest('web');
        $this->get('/institution/'.$org->public_id.'/account')->assertOk()->assertSee('الحساب المؤسسي')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertNull(app(TenantContext::class)->organizationId());
        $csrf = session()->token();
        $session = session()->getId();
        $this->post('/institution/'.$org->public_id.'/logout')->assertRedirect('/institution/login');
        $this->assertGuest('institution');
        $this->assertNotSame($session, session()->getId());
        $this->assertNotSame($csrf, session()->token());
        $this->get('/institution/'.$org->public_id.'/account')->assertRedirect('/institution/login');
        $this->login($other, 'first-account-password')->assertSessionHasErrors('email');
        $this->assertGuest('institution');
        $this->login($other, 'second-account-password')->assertRedirect();
        $this->assertAuthenticatedAs($b, 'institution');
        $this->get('/institution/'.$other->public_id.'/account')->assertOk();
    }

    public function test_changed_route_or_forged_session_context_cannot_switch_an_authenticated_principal(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $this->account($org);
        $this->account($other, 'second-account-password');
        $this->login($org);
        $this->withSession(['institution.organization' => $other->public_id])->get('/institution/'.$other->public_id.'/account')
            ->assertRedirect('/institution/login');
        $this->assertGuest('institution');
        $this->assertNull(app(TenantContext::class)->organizationId());
        $this->login($org);
        $this->get('/institution/'.$other->public_id.'/account')->assertRedirect('/institution/login');
    }

    public function test_wrong_unknown_fake_and_platform_credentials_have_the_same_failure_and_throttle(): void
    {
        $org = Organization::factory()->create();
        $this->account($org);
        User::factory()->create(['principal_type' => 'platform', 'email' => 'platform@example.test']);
        $messages = [];
        foreach ([[$org->public_id, 'employee@example.test', 'wrong-password'],
            [$org->public_id, 'unknown@example.test', 'wrong-password'],
            ['fake-locator', 'employee@example.test', 'first-account-password'],
            [$org->public_id, 'platform@example.test', 'password']] as [$locator, $email, $password]) {
            $this->post('/institution/login', ['organization' => $locator, 'email' => $email, 'password' => $password])->assertSessionHasErrors('email');
            $messages[] = session('errors')->first('email');
            $this->assertGuest('institution');
        }
        $this->assertCount(1, array_unique($messages));
        for ($i = 0; $i < 4; $i++) {
            $this->login($org, 'wrong-password')->assertSessionHasErrors('email');
        }
        $this->postJson('/institution/login', ['organization' => $org->public_id, 'email' => 'employee@example.test', 'password' => 'wrong-password'])->assertStatus(429);
    }

    public function test_missing_csrf_and_unapproved_management_routes_never_create_privileges(): void
    {
        // Enable the real framework CSRF middleware rather than its testing bypass.
        $this->app->instance('env', 'local');
        $org = Organization::factory()->create();
        $this->account($org);
        $this->post('/institution/login', ['organization' => $org->public_id, 'email' => 'employee@example.test', 'password' => 'first-account-password'])->assertStatus(419);
        $this->assertGuest('institution');
        foreach (['/institution/users', '/institution/roles', '/institution/documents', '/admin/organizations'] as $path) {
            $this->get($path)->assertNotFound();
        }
        $this->assertDatabaseCount('roles', 0);
        $this->assertSame([], config('authorization.capabilities'));
    }

    public function test_revoked_membership_and_changed_password_invalidate_cached_session_on_next_request(): void
    {
        $org = Organization::factory()->create();
        $account = $this->account($org);
        $this->login($org);
        $account->password = 'changed-elsewhere-password';
        $account->save();
        $this->get('/institution/'.$org->public_id.'/account')->assertRedirect('/institution/login');
        $this->login($org, 'changed-elsewhere-password');
        $account->memberships()->delete();
        $this->get('/institution/'.$org->public_id.'/account')->assertRedirect('/institution/login');
        $this->assertGuest('institution');
        $this->assertNull(app(TenantContext::class)->actorId());
    }

    public function test_remember_cookie_without_matching_scope_binding_is_not_an_entry_or_cross_guard_bypass(): void
    {
        $org = Organization::factory()->create();
        $account = $this->account($org);
        $guard = Auth::guard('institution');
        $this->withCookie($guard->getRecallerName(), $account->id.'|'.$account->remember_token.'|'.$account->password)
            ->get('/institution/'.$org->public_id.'/account')->assertRedirect('/institution/login');
        $this->assertGuest('institution');
        $this->assertFalse($account->canAccessPanel(Filament::getPanel('admin')));
        $this->assertNull(Auth::createUserProvider('users')->retrieveByToken($account->id, $account->remember_token));
    }

    public function test_all_entry_paths_fail_closed_in_production_and_staging_even_with_cached_registration(): void
    {
        $org = Organization::factory()->create();
        $account = $this->account($org);
        foreach (['production', 'staging'] as $environment) {
            $this->app->instance('env', $environment);
            $this->get('/institution/login')->assertNotFound();
            $this->get('/institution/'.$org->public_id.'/account')->assertNotFound();
            $this->assertFalse($account->canAccessPanel(Filament::getPanel('admin')));
        }
    }

    public function test_account_bound_reset_mail_link_changes_only_the_selected_principal_and_blocks_replay(): void
    {
        Notification::fake();
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $a = $this->account($org);
        $b = $this->account($other, 'second-account-password');
        $bBefore = $b->fresh()->getAttributes();
        $platform = User::factory()->create(['principal_type' => 'platform', 'email' => $a->email]);
        $platformBefore = $platform->fresh()->getAttributes();
        $this->post('/institution/recovery', ['organization' => $org->public_id, 'email' => $a->email])->assertRedirect();
        Notification::assertSentTo($a, InstitutionPasswordReset::class);
        Notification::assertNotSentTo($b, InstitutionPasswordReset::class);
        $mail = Notification::sent($a, InstitutionPasswordReset::class)->sole();
        $this->assertSame($a->email, $a->routeNotificationFor('mail', $mail));
        $this->assertSame(['mail'], $mail->via($a));
        $this->assertStringContainsString($a->public_id, $mail->toMail($a)->actionUrl);
        $row = DB::table('institution_password_reset_tokens')->sole();
        $this->assertSame($a->id, (int) $row->account_id);
        $this->assertNotSame($mail->token, $row->token);
        $this->assertFalse(app(InstitutionRecovery::class)->reset($other->public_id, $b->public_id, $mail->token, 'replacement-password'));
        $this->assertFalse(app(InstitutionRecovery::class)->reset($org->public_id, $platform->public_id, $mail->token, 'replacement-password'));
        $url = $mail->url();
        $this->get($url)->assertOk()->assertSee('dir="rtl"', false)->assertHeader('Referrer-Policy', 'no-referrer');
        $this->post($url, ['password' => 'replacement-password', 'password_confirmation' => 'replacement-password'])->assertRedirect('/institution/login');
        $this->assertTrue(Hash::check('replacement-password', $a->fresh()->password));
        $this->assertNotSame($a->remember_token, $a->fresh()->remember_token);
        $this->assertSame($bBefore, $b->fresh()->getAttributes());
        $this->assertSame($platformBefore, $platform->fresh()->getAttributes());
        $this->assertDatabaseCount('institution_password_reset_tokens', 0);
        $this->assertFalse(app(InstitutionRecovery::class)->reset($org->public_id, $a->public_id, $mail->token, 'another-password'));
        $this->login($org, 'replacement-password')->assertRedirect();
    }

    public function test_token_issuance_throttling_and_expiry_are_independent_for_same_email_accounts(): void
    {
        Notification::fake();
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $a = $this->account($org);
        $b = $this->account($other);
        $action = app(InstitutionRecovery::class);
        $action->request($org->public_id, $a->email);
        $action->request($other->public_id, $b->email);
        $action->request($org->public_id, $a->email);
        Notification::assertSentToTimes($a, InstitutionPasswordReset::class, 1);
        Notification::assertSentToTimes($b, InstitutionPasswordReset::class, 1);
        $first = Notification::sent($a, InstitutionPasswordReset::class)->sole();
        $second = Notification::sent($b, InstitutionPasswordReset::class)->sole();
        $issuedUrl = $first->url();
        $this->assertNotSame($first->token, $second->token);
        $this->assertDatabaseCount('institution_password_reset_tokens', 2);
        $this->travel(61)->minutes();
        $this->assertFalse($action->reset($org->public_id, $a->public_id, $first->token, 'replacement-password'));
        $this->get($issuedUrl)->assertForbidden();
        $this->post($first->url().'invalid', ['password' => 'replacement-password', 'password_confirmation' => 'replacement-password'])->assertForbidden();
        $this->travelBack();
    }

    public function test_recovery_unknown_scope_and_account_share_generic_feedback_and_no_real_mail(): void
    {
        Notification::fake();
        $org = Organization::factory()->create();
        $account = $this->account($org);
        $messages = [];
        foreach ([[$org->public_id, $account->email], [$org->public_id, 'unknown@example.test'], ['fake-locator', $account->email]] as [$organization, $email]) {
            $this->post('/institution/recovery', compact('organization', 'email'))->assertRedirect();
            $messages[] = session('status');
        }
        $this->assertCount(1, array_unique($messages));
        Notification::assertCount(1);
        $mail = Notification::sent($account, InstitutionPasswordReset::class)->sole();
        $this->get(str_replace($org->public_id, 'forged', $mail->url()))->assertForbidden();
        $this->get(str_replace($account->public_id, 'forged', $mail->url()))->assertForbidden();
        $this->get(str_replace($mail->token, 'malformed', $mail->url()))->assertForbidden();
        $this->assertFalse(app(InstitutionRecovery::class)->reset($org->public_id, $account->public_id, 'malformed', 'replacement-password'));
    }

    public function test_reset_notification_is_after_commit_and_rollback_never_sends_it(): void
    {
        Notification::fake();
        $org = Organization::factory()->create();
        $account = $this->account($org);
        DB::beginTransaction();
        app(InstitutionRecovery::class)->request($org->public_id, $account->email);
        Notification::assertNothingSent();
        DB::rollBack();
        Notification::assertNothingSent();
        $this->assertDatabaseCount('institution_password_reset_tokens', 0);
    }

    public function test_contact_or_password_change_invalidates_an_outstanding_account_reset_token(): void
    {
        Notification::fake();
        $org = Organization::factory()->create();
        $account = $this->account($org);
        $action = app(InstitutionRecovery::class);
        $action->request($org->public_id, $account->email);
        $mail = Notification::sent($account, InstitutionPasswordReset::class)->sole();
        $account->email = 'changed@example.test';
        $account->save();
        $this->assertFalse($action->reset($org->public_id, $account->public_id, $mail->token, 'replacement-password'));
        $account->email = 'employee@example.test';
        $account->password = 'password-changed-elsewhere';
        $account->save();
        $this->assertFalse($action->reset($org->public_id, $account->public_id, $mail->token, 'replacement-password'));
        $this->assertTrue(Hash::check('password-changed-elsewhere', $account->fresh()->password));
    }

    public function test_untrusted_host_referer_and_intended_url_cannot_redirect_or_change_recovery_mail_origin(): void
    {
        Notification::fake();
        config(['app.url' => 'https://trusted.example.test']);
        $org = Organization::factory()->create();
        $account = $this->account($org);
        $this->withHeaders(['Host' => 'attacker.example.test', 'Referer' => 'https://attacker.example.test/collect'])
            ->post('https://attacker.example.test/institution/recovery', ['organization' => $org->public_id, 'email' => $account->email])->assertStatus(400);
        Notification::assertNothingSent();
        app(InstitutionRecovery::class)->request($org->public_id, $account->email);
        $mail = Notification::sent($account, InstitutionPasswordReset::class)->sole();
        $this->assertStringStartsWith('https://trusted.example.test/institution/', $mail->url());
        $this->assertStringNotContainsString('attacker', $mail->url());
        $response = $this->withHeaders(['Host' => 'trusted.example.test'])->post('https://trusted.example.test/institution/login', ['organization' => $org->public_id, 'email' => $account->email, 'password' => 'invalid']);
        $response->assertRedirect();
        $this->assertSame('trusted.example.test', parse_url($response->headers->get('Location'), PHP_URL_HOST));
        $response = $this->post('https://trusted.example.test/institution/login', []);
        $response->assertRedirect();
        $this->assertSame('trusted.example.test', parse_url($response->headers->get('Location'), PHP_URL_HOST));
    }
}
