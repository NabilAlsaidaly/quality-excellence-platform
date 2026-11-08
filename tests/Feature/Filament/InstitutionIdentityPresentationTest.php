<?php

namespace Tests\Feature\Filament;

use App\Actions\Identity\InstitutionRecovery;
use App\Authorization\TenantContext;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Notifications\InstitutionPasswordReset;
use App\Policies\ApproachPolicy;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

require_once __DIR__.'/ApproachFilamentIntegrationTest.php';

class InstitutionIdentityPresentationTest extends TestCase
{
    use ApproachPanelFixtures, RefreshDatabase;

    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    private function field(DOMXPath $xpath, string $id, array $attributes): void
    {
        $node = $xpath->query('//input[@id="'.$id.'"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $node);
        foreach ($attributes as $key => $value) {
            $this->assertSame($value, $node->getAttribute($key), $id.': '.$key);
        }
        $this->assertTrue($node->hasAttribute('required'));
        $this->assertCount(1, $xpath->query('//label[@for="'.$id.'"]'));
    }

    private function csrf(DOMXPath $xpath): void
    {
        foreach ($xpath->query('//form') as $form) {
            $this->assertSame('post', $form->getAttribute('method'));
            $tokens = $xpath->query('.//input[@name="_token"]', $form);
            $this->assertCount(1, $tokens);
            $this->assertSame(session()->token(), $tokens->item(0)->getAttribute('value'));
        }
    }

    private function account(): array
    {
        $organization = Organization::factory()->create();
        $account = User::factory()->create(['principal_type' => 'institution', 'organization_id' => $organization->id,
            'email' => 'synthetic48@example.test', 'password' => 'synthetic-password48']);
        OrganizationMembership::factory()->create(['user_id' => $account->id, 'organization_id' => $organization->id]);

        return [$organization, $account];
    }

    public function test_standalone_native_forms_preserve_exact_entry_contract_without_panel_or_guard_mutation(): void
    {
        $panel = Filament::getCurrentPanel();
        $colors = FilamentColor::getColors();
        $guard = Auth::getDefaultDriver();
        $response = $this->get('/institution/login')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertSee('fi-input-wrp', false)->assertSee('fi-btn', false);
        $xpath = $this->document($response->getContent());
        $this->assertSame('ar', $xpath->query('//html')->item(0)->getAttribute('lang'));
        $this->assertSame('rtl', $xpath->query('//html')->item(0)->getAttribute('dir'));
        $this->assertCount(2, $xpath->query('//form'));
        $this->assertSame('/institution/login', $xpath->query('//form')->item(0)->getAttribute('action'));
        $this->assertSame('/institution/recovery', $xpath->query('//form')->item(1)->getAttribute('action'));
        $this->csrf($xpath);
        $this->field($xpath, 'organization', ['name' => 'organization', 'maxlength' => '128', 'dir' => 'ltr', 'autocomplete' => 'organization']);
        $this->field($xpath, 'email', ['name' => 'email', 'type' => 'email', 'maxlength' => '254', 'dir' => 'ltr', 'autocomplete' => 'username']);
        $this->field($xpath, 'password', ['name' => 'password', 'type' => 'password', 'maxlength' => '', 'autocomplete' => 'current-password', 'value' => '']);
        $this->field($xpath, 'recovery-organization', ['name' => 'organization', 'maxlength' => '128', 'dir' => 'ltr', 'autocomplete' => '', 'value' => '']);
        $this->field($xpath, 'recovery-email', ['name' => 'email', 'type' => 'email', 'maxlength' => '254', 'dir' => 'ltr', 'autocomplete' => 'email', 'value' => '']);
        $this->assertCount(2, $xpath->query('//button[@type="submit"]'));
        $this->assertStringContainsString(Filament::getPanel('institution')->getColors()['primary'][600], $xpath->query('//html')->item(0)->getAttribute('style'));
        $this->assertStringContainsString('/fonts/filament/filament/inter/index.css', str_replace('\\', '/', $response->getContent()));
        $response->assertDontSee('wire:submit', false)->assertDontSee('livewire.js', false);
        foreach ($xpath->query('//link[@href]') as $link) {
            $url = $link->getAttribute('href');
            $this->assertTrue(str_starts_with($url, '/') || parse_url($url, PHP_URL_HOST) === parse_url(config('app.url'), PHP_URL_HOST));
        }
        $this->assertSame($colors, FilamentColor::getColors());
        $this->assertSame($panel, Filament::getCurrentPanel());
        $this->assertSame($guard, Auth::getDefaultDriver());
        $this->assertNull(app(TenantContext::class)->organizationId());
    }

    public function test_old_values_and_shared_feedback_remain_escaped_and_passwords_are_never_reflected(): void
    {
        $unsafe = '"><script>synthetic48()</script>';
        $this->post('/institution/login', ['organization' => $unsafe, 'email' => $unsafe, 'password' => 'do-not-reflect-password48'])
            ->assertRedirect('/institution/login');
        $response = $this->get('/institution/login')->assertOk();
        $xpath = $this->document($response->getContent());
        $this->assertSame($unsafe, $xpath->query('//input[@id="organization"]')->item(0)->getAttribute('value'));
        $this->assertSame($unsafe, $xpath->query('//input[@id="email"]')->item(0)->getAttribute('value'));
        $this->assertCount(1, $xpath->query('//*[@role="alert"]'));
        $this->assertCount(0, $xpath->query('//*[@role="status"]'));
        $this->assertNotSame('', trim($xpath->query('//*[@role="alert"]')->item(0)->textContent));
        $response->assertDontSee('<script>synthetic48()', false)->assertDontSee('رسالة ثانية')->assertDontSee('do-not-reflect-password48');
        $this->assertSame('', $xpath->query('//input[@id="recovery-email"]')->item(0)->getAttribute('value'));
        $this->post('/institution/recovery', ['organization' => 'unknown', 'email' => 'unknown@example.test'])->assertRedirect('/institution/login');
        $status = $this->get('/institution/login')->assertOk();
        $this->assertCount(1, $this->document($status->getContent())->query('//*[@role="status"]'));
        $status->assertSee('إذا كانت البيانات مطابقة لحساب متاح');
    }

    public function test_signed_reset_form_preserves_full_raw_query_and_resets_only_the_selected_account(): void
    {
        Notification::fake();
        [$organization, $account] = $this->account();
        [, $other] = $this->account(); // Same email, independent institution/password state.
        $otherHash = $other->password;
        app(InstitutionRecovery::class)->request($organization->public_id, $account->email);
        $mail = Notification::sent($account, InstitutionPasswordReset::class)->sole();
        $url = URL::temporarySignedRoute('institution.reset', now()->addMinutes(5), ['organization' => $organization->public_id,
            'account' => $account->public_id, 'token' => $mail->token, 'note' => 'عربي & = +'], absolute: false);
        $response = $this->get($url)->assertOk();
        $xpath = $this->document($response->getContent());
        $this->assertSame($url, $xpath->query('//form')->item(0)->getAttribute('action'));
        $this->csrf($xpath);
        $this->field($xpath, 'password', ['name' => 'password', 'type' => 'password', 'minlength' => '12', 'maxlength' => '128', 'autocomplete' => 'new-password', 'value' => '']);
        $this->field($xpath, 'confirmation', ['name' => 'password_confirmation', 'type' => 'password', 'minlength' => '12', 'maxlength' => '128', 'autocomplete' => 'new-password', 'value' => '']);
        $this->post($url, ['password' => 'replacement-password48', 'password_confirmation' => 'replacement-password48'])->assertRedirect('/institution/login');
        $this->assertTrue(Hash::check('replacement-password48', $account->fresh()->password));
        $this->assertSame($otherHash, $other->fresh()->password);
        $this->assertDatabaseCount('institution_password_reset_tokens', 0);
    }

    public function test_invalid_and_expired_signatures_do_not_render_the_reset_form_or_change_credentials(): void
    {
        Notification::fake();
        [$organization, $account] = $this->account();
        app(InstitutionRecovery::class)->request($organization->public_id, $account->email);
        $url = Notification::sent($account, InstitutionPasswordReset::class)->sole()->url();
        $hash = $account->password;
        $this->get($url.'&altered=1')->assertForbidden()->assertDontSee('name="password_confirmation"', false);
        $this->travel(61)->minutes();
        $this->get($url)->assertForbidden()->assertDontSee('name="password_confirmation"', false);
        $this->travelBack();
        $this->assertSame($hash, $account->fresh()->password);
    }

    public function test_account_link_still_requires_the_original_live_authorization_and_logout_is_post_with_csrf(): void
    {
        SyntheticApproachPolicy::$grants = [];
        app()->bind(ApproachPolicy::class, SyntheticApproachPolicy::class);
        [$actor, $organization] = $this->fixture44();
        $this->login44($actor, $organization);
        $url = '/institution/'.$organization->public_id.'/account';
        $response = $this->get($url)->assertOk()->assertSee('فتح مساحة المؤسسة');
        $xpath = $this->document($response->getContent());
        $this->assertSame('/institution/workspace', $xpath->query('//a[contains(@class,"fi-btn")]')->item(0)->getAttribute('href'));
        $this->assertSame('/institution/'.$organization->public_id.'/logout', $xpath->query('//form')->item(0)->getAttribute('action'));
        $this->csrf($xpath);
        SyntheticApproachPolicy::$grants[$actor->id] = [];
        $this->get($url)->assertOk()->assertDontSee('فتح مساحة المؤسسة');
        $this->post('/institution/'.$organization->public_id.'/logout')->assertRedirect('/institution/login');
        $this->assertGuest('institution');
        $this->assertGuest('web');
        $this->get($url)->assertRedirect('/institution/login');
    }
}
