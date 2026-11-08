<?php

namespace Tests\Feature\Filament;

use App\Authorization\RoleManagement;
use App\Authorization\TenantContext;
use App\Filament\Institution\Approaches\ApproachAccess;
use App\Filament\Institution\Resources\ApproachResource\Pages\ApproachBank;
use App\Filament\Institution\Resources\ApproachResource\Pages\ApproachWorkspace;
use App\Models\Approach;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Policies\ApproachPolicy;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

require_once __DIR__.'/ApproachFilamentIntegrationTest.php';

/** Shared actual HTTP transport scenarios, also executed on disposable MySQL. */
trait InstitutionJourneyScenarios
{
    use ApproachPanelFixtures;

    private function prepare46(): void
    {
        app()->setLocale('ar');
        SyntheticApproachPolicy::$grants = [];
        app()->bind(ApproachPolicy::class, SyntheticApproachPolicy::class);
        // Enables real CSRF middleware; PHPunit still uses its isolated database.
        app()->instance('env', 'local');
        config(['app.env' => 'local', 'app.debug' => false]);
    }

    private function signIn46(User $actor, Organization $organization): void
    {
        $this->get('/institution/login')->assertOk();
        $this->post('/institution/login', ['organization' => $organization->public_id, 'email' => $actor->email,
            'password' => 'password', '_token' => session()->token()])->assertRedirect('/institution/'.$organization->public_id.'/account');
        $this->assertAuthenticatedAs($actor, 'institution');
        $this->assertGuest('web');
    }

    private function snapshot46(TestResponse $response, string $class): string
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $expected = str($class)->replace('\\', '.')->explode('.')->map(fn ($part) => (string) str($part)->kebab())->implode('.');
        $names = [];
        foreach ($document->getElementsByTagName('*') as $node) {
            if (! $node->hasAttribute('wire:snapshot')) {
                continue;
            }
            $snapshot = $node->getAttribute('wire:snapshot');
            $data = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);
            $names[] = $data['memo']['name'] ?? null;
            if (in_array($data['memo']['name'] ?? '', [$expected, $class], true)) {
                return $snapshot;
            }
        }
        $this->fail('Actual native page snapshot missing: '.$class.'; found '.json_encode($names));
    }

    private function transport46(string &$snapshot, string $method, array $params = [], array $updates = [], int $status = 200): TestResponse
    {
        $response = $this->postJson(Livewire::getUpdateUri(), ['components' => [[
            'snapshot' => $snapshot, 'updates' => $updates,
            'calls' => [['path' => '', 'method' => $method, 'params' => $params]],
        ]]], ['X-Livewire' => 'true', 'X-CSRF-TOKEN' => session()->token()]);
        $response->assertStatus($status);
        if ($status === 200) {
            $snapshot = $response->json('components.0.snapshot');
            $this->assertIsString($snapshot);
        }

        return $response;
    }

    private function grant46(User $actor, string $publicId, array $operations): void
    {
        foreach ($operations as $operation) {
            SyntheticApproachPolicy::$grants[$actor->id][$operation][$publicId] = true;
        }
    }

    public function test_actual_login_dashboard_create_edit_draft_readback_and_logout_journey(): void
    {
        $this->prepare46();
        [$actor, $organization, $unit] = $this->fixture44();
        $this->signIn46($actor, $organization);
        $this->get('/institution/'.$organization->public_id.'/account')->assertOk()->assertSee('فتح مساحة المؤسسة');
        $this->get('/institution/workspace')->assertOk()->assertSee('مساحة المؤسسة')->assertSee('fi-sidebar', false)->assertSee('فتح بنك المنهجيات');
        $index = $this->get('/institution/workspace/approaches')->assertOk()->assertSee('بنك المنهجيات');
        $snapshot = $this->snapshot46($index, ApproachBank::class);
        $this->transport46($snapshot, 'mountAction', ['create']);
        $this->assertSame('اختر خيارًا.', __('filament-forms::components.select.required_description'));
        $data = $this->data44($unit, ['name' => 'منهجية الرحلة الفعلية']);
        $updates = [];
        foreach ($data as $key => $value) {
            $updates['mountedActions.0.data.'.$key] = $value;
        }
        $saved = $this->transport46($snapshot, 'callMountedAction', [], $updates);
        $saved->assertJsonPath('filamentNotifications', true);
        $approach = Approach::query()->where('organization_id', $organization->id)->where('name', $data['name'])->sole();
        $this->assertSame($actor->id, $approach->created_by);
        // Explicit fixture issuer, not an automatic application creator grant.
        $this->grant46($actor, $approach->public_id, ['view', 'updateMetadata', 'editDraft', 'viewDraftHistory', 'viewAuditHistory']);
        $url = '/institution/workspace/approaches/'.$approach->public_id;
        $detail = $this->get($url)->assertOk()->assertSee($data['name']);
        $snapshot = $this->snapshot46($detail, ApproachWorkspace::class);
        $this->transport46($snapshot, 'mountAction', ['edit']);
        $this->transport46($snapshot, 'callMountedAction', [], ['mountedActions.0.data.name' => 'اسم محفوظ فعليًا']);
        $this->assertSame('اسم محفوظ فعليًا', $approach->fresh()->name);
        $this->transport46($snapshot, 'mountAction', ['draft']);
        $this->transport46($snapshot, 'callMountedAction', [], ['mountedActions.0.data.body' => 'محتوى المسودة الأولى']);
        $this->transport46($snapshot, 'mountAction', ['draft']);
        $this->transport46($snapshot, 'callMountedAction', [], ['mountedActions.0.data.body' => 'محتوى المسودة الثانية']);
        $this->get($url)->assertOk()->assertSee('محتوى المسودة الأولى')->assertSee('محتوى المسودة الثانية');
        $this->assertSame([1, 2], $approach->revisions()->orderBy('version_number')->pluck('version_number')->all());
        $this->assertDatabaseHas('approach_changes', ['approach_id' => $approach->id, 'event' => 'metadata_updated', 'actor_id' => $actor->id]);
        $this->post('/institution/workspace/logout', ['_token' => session()->token()])->assertRedirect('/institution/login');
        $this->assertGuest('institution');
        $this->assertGuest('web');
        $this->get($url)->assertRedirect('/institution/login');
        $this->assertNull(app(TenantContext::class)->organizationId());
    }

    public function test_real_livewire_stale_and_revoked_assignment_do_not_write_history(): void
    {
        $this->prepare46();
        [$actor, $organization, , $approach] = $this->fixture44();
        $this->signIn46($actor, $organization);
        $url = '/institution/workspace/approaches/'.$approach->public_id;
        $snapshot = $this->snapshot46($this->get($url)->assertOk(), ApproachWorkspace::class);
        $this->transport46($snapshot, 'mountAction', ['edit']);
        $access = app(ApproachAccess::class);
        $access->update($approach->public_id, ['name' => 'التعديل الفائز'], $access->show($approach->public_id)['edit_token']);
        $this->transport46($snapshot, 'callMountedAction', [], ['mountedActions.0.data.name' => 'تعديل متقادم'], 409)->assertJson(['message' => __('approach_workspace.conflict')]);
        $this->assertDatabaseCount('approach_changes', 1);
        $snapshot = $this->snapshot46($this->get($url)->assertOk(), ApproachWorkspace::class);
        $this->transport46($snapshot, 'mountAction', ['draft']);
        SyntheticApproachPolicy::$grants[$actor->id]['editDraft'] = [];
        $this->transport46($snapshot, 'callMountedAction', [], ['mountedActions.0.data.body' => 'مرفوض'], 403);
        $this->assertDatabaseCount('approach_content_revisions', 0);
    }

    public function test_list_only_foreign_scope_and_native_logout_preserve_session_isolation(): void
    {
        $this->prepare46();
        [$actor, $organization, , $approach] = $this->fixture44();
        [$other, $foreign, , $foreignApproach] = $this->fixture44();
        // Same contact, independent account ID/password/session/organization.
        DB::table('users')->where('id', $other->id)->update(['email' => $actor->email]);
        SyntheticApproachPolicy::$grants[$actor->id] = ['list' => ['*' => true]];
        $this->signIn46($actor, $organization);
        $this->get('/institution/workspace/approaches')->assertOk()->assertDontSee($foreignApproach->public_id)->assertDontSee('إنشاء منهجية');
        $this->get('/institution/workspace/approaches/'.$approach->public_id)->assertForbidden();
        $this->get('/institution/workspace/approaches/'.$foreignApproach->public_id)->assertNotFound();
        foreach (['bad', (string) $approach->id] as $id) {
            $this->get('/institution/workspace/approaches/'.$id)->assertNotFound();
        }
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/platform')->assertNotFound();
        $this->withSession(['institution.organization' => $foreign->public_id])->get('/institution/workspace')->assertForbidden();
        $this->withSession(['institution.organization' => $organization->public_id]);
        SyntheticApproachPolicy::$grants[$actor->id] = [];
        $this->get('/institution/'.$organization->public_id.'/account')->assertOk()->assertDontSee('فتح مساحة المؤسسة');
        $this->post('/institution/workspace/logout', ['_token' => session()->token()])->assertRedirect('/institution/login');
        $this->signIn46($other->fresh(), $foreign);
        $this->assertAuthenticatedAs($other->fresh(), 'institution');
        $this->get('/institution/workspace/approaches')->assertOk()->assertDontSee($approach->public_id);
        $this->assertNotSame($actor->id, $other->id);
        $this->assertFalse(Auth::guard('web')->check());
    }

    public function test_real_csrf_and_password_membership_expiry_deny_panel_and_updates(): void
    {
        $this->prepare46();
        [$actor, $organization, , $approach] = $this->fixture44();
        $this->signIn46($actor, $organization);
        $url = '/institution/workspace/approaches/'.$approach->public_id;
        $snapshot = $this->snapshot46($this->get($url)->assertOk(), ApproachWorkspace::class);
        $this->post('/institution/workspace/logout')->assertStatus(419);
        $this->postJson(Livewire::getUpdateUri(), ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]]], ['X-Livewire' => 'true'])->assertStatus(419);
        $this->assertAuthenticatedAs($actor, 'institution');
        $this->withSession(['identity.active_guard' => 'web'])->get('/institution/workspace')->assertForbidden();
        $this->withSession(['identity.active_guard' => 'institution']);
        DB::table('users')->where('id', $actor->id)->update(['password' => password_hash('changed-synthetic-only', PASSWORD_BCRYPT)]);
        $this->transport46($snapshot, '$refresh', [], [], 403);
        $this->assertDatabaseCount('approach_content_revisions', 0);
        app(TenantContext::class)->run($actor->fresh(), $organization->id,
            fn () => app(RoleManagement::class)->revoke($actor->roles()->firstOrFail(), $actor));
        OrganizationMembership::query()->where('user_id', $actor->id)->delete();
        $this->get('/institution/workspace')->assertForbidden();
        $this->assertNull(app(TenantContext::class)->actorId());
    }
}

class InstitutionPanelJourneyTest extends TestCase
{
    use InstitutionJourneyScenarios, RefreshDatabase;
}
