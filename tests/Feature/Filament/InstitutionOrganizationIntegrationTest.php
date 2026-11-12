<?php

namespace Tests\Feature\Filament;

use App\Actions\Identity\InstitutionRecovery;
use App\Authorization\CapabilityRegistry;
use App\Authorization\RoleManagement;
use App\Authorization\TenantContext;
use App\Filament\Institution\Pages\OrganizationWorkspace;
use App\Models\Organization;
use App\Models\Role;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\ReviewMaturityFixtures;
use Tests\Support\ReviewMaturityPanelProvider;
use Tests\TestCase;

class InstitutionOrganizationIntegrationTest extends TestCase
{
    use DatabaseMigrations, ReviewMaturityFixtures;

    private array $createdUnitIds = [];

    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->instance('request', Request::create('http://localhost'));
        $app->booting(fn () => $app->register(ReviewMaturityPanelProvider::class));
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function tearDown(): void
    {
        try {
            if ($this->app) {
                foreach ($this->workflowFixtures as $f) {
                    DB::table('organizational_unit_changes')->where('organization_id', $f['organization']->id)->delete();
                    // Exact additional leaf IDs created by this test only. The
                    // self-FK still rejects an unexpected dependent child.
                    DB::table('organizational_units')->where('organization_id', $f['organization']->id)
                        ->whereIn('id', $this->createdUnitIds[$f['organization']->id] ?? [])->delete();
                }
                $this->cleanupReviewMaturity();
            }
        } finally {
            parent::tearDown();
        }
    }

    private function fixture(bool $manage = true): array
    {
        $f = $this->reviewMaturityFixture();
        config(['authorization.capabilities' => array_merge(config('authorization.test_only_fixture_capabilities'), [
            'organization.units.view' => Organization::class, 'organization.units.manage' => Organization::class,
        ])]);
        app(TenantContext::class)->run($f['issuer'], $f['organization']->id, function () use ($f, $manage): void {
            $manager = app(RoleManagement::class);
            $role = $manager->create('unit-ui-'.$f['proof']['fixture_id']);
            $manager->syncPermissions($role, $manage ? ['organization.units.view', 'organization.units.manage'] : ['organization.units.view']);
            $manager->assign($role, $f['author']);
        });
        $f['panel_proof'] = $this->workflowPanelProof($f) + ['organization_units' => [
            'scenario' => 'UNIT_LOCAL_001', 'fixture_id' => $f['proof']['fixture_id'],
            'organization_id' => $f['organization']->id, 'actor_ids' => [$f['author']->id],
        ]];

        return $f;
    }

    public function test_native_page_is_linked_only_with_explicit_fixture_and_real_unit_permission(): void
    {
        $f = $this->fixture();
        ReviewMaturityPanelProvider::withFixture($f['panel_proof'], function () use ($f): void {
            $this->post('/institution/login', ['organization' => $f['organization']->public_id, 'email' => $f['author']->email, 'password' => 'password'])->assertRedirect();
            $this->get('/institution/'.$f['organization']->public_id.'/account')->assertOk()->assertSee('فتح العرض المتكامل');
            $this->get('/institution/mvp-workspace/organization')->assertOk()->assertSee('المؤسسة والوحدات')->assertSee('إنشاء وحدة');
            $this->get('/institution/mvp-workspace/workflows')->assertOk()->assertSee('المؤسسة والوحدات');
        });
        $this->get('/institution/'.$f['organization']->public_id.'/account')->assertOk()->assertDontSee('فتح العرض المتكامل');
        $this->assertSame([], (require config_path('authorization.php'))['capabilities']);
    }

    public function test_independent_reviewer_without_unit_role_is_denied_and_membership_does_not_grant_it(): void
    {
        $f = $this->fixture();
        ReviewMaturityPanelProvider::withFixture($f['panel_proof'], function () use ($f): void {
            $this->post('/institution/login', ['organization' => $f['organization']->public_id, 'email' => $f['reviewer']->email, 'password' => 'password'])->assertRedirect();
            $this->get('/institution/mvp-workspace/workflows')->assertOk();
            $this->get('/institution/mvp-workspace/organization')->assertForbidden();
        });
        $this->assertDatabaseCount('organizational_unit_changes', 0);
    }

    public function test_assigned_approach_uses_the_same_native_panel_without_granting_reviewer_access(): void
    {
        $f = $this->fixture();
        ReviewMaturityPanelProvider::withFixture($f['panel_proof'], function () use ($f): void {
            $this->post('/institution/login', ['organization' => $f['organization']->public_id, 'email' => $f['author']->email, 'password' => 'password'])->assertRedirect();
            $this->get('/institution/mvp-workspace/approaches')->assertOk()->assertSee('منهجية العرض المتكامل');
            $this->get('/institution/mvp-workspace/approaches/'.$f['approach']->public_id)->assertOk()->assertSee('منهجية العرض المتكامل');
            $this->post(route('institution.logout', ['organization' => $f['organization']->public_id], false))->assertRedirect();
            Auth::forgetGuards();
            $this->post('/institution/login', ['organization' => $f['organization']->public_id, 'email' => $f['reviewer']->email, 'password' => 'password'])->assertRedirect();
            $this->get('/institution/mvp-workspace/workflows')->assertOk();
            $this->get('/institution/mvp-workspace/approaches')->assertForbidden();
            $this->get('/institution/mvp-workspace/approaches/'.$f['approach']->public_id)->assertForbidden();
        });
        $this->assertDatabaseCount('approach_content_revisions', 0);
    }

    private function snapshot(TestResponse $response): string
    {
        $component = str(OrganizationWorkspace::class)->replace('\\', '.')->explode('.')
            ->map(fn ($part) => (string) str($part)->kebab())->implode('.');
        $document = new \DOMDocument;
        $old = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
            foreach ($document->getElementsByTagName('*') as $node) {
                if ($node->hasAttribute('wire:snapshot')) {
                    $snapshot = $node->getAttribute('wire:snapshot');
                    if (in_array(json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR)['memo']['name'], [$component, OrganizationWorkspace::class], true)) {
                        return $snapshot;
                    }
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
        $this->fail('Actual native organization Livewire snapshot missing.');
    }

    private function transport(string &$snapshot, string $method, array $params = [], array $updates = [], int $status = 200): TestResponse
    {
        $response = $this->postJson(Livewire::getUpdateUri(), ['components' => [['snapshot' => $snapshot, 'updates' => $updates,
            'calls' => [['path' => '', 'method' => $method, 'params' => $params]]]]], ['X-Livewire' => 'true', 'X-CSRF-TOKEN' => session()->token()]);
        $response->assertStatus($status);
        if ($status === 200) {
            $snapshot = $response->json('components.0.snapshot');
            $this->assertIsString($snapshot);
        }

        return $response;
    }

    public function test_real_signed_native_create_rename_and_move_persist_history(): void
    {
        $f = $this->fixture();
        ReviewMaturityPanelProvider::withFixture($f['panel_proof'], function () use ($f): void {
            $this->post('/institution/login', ['organization' => $f['organization']->public_id, 'email' => $f['author']->email, 'password' => 'password'])->assertRedirect();
            $snapshot = $this->snapshot($this->get('/institution/mvp-workspace/organization')->assertOk());
            $this->transport($snapshot, 'mountAction', ['createUnit']);
            $this->transport($snapshot, 'callMountedAction', updates: ['mountedActions.0.data.name' => 'وحدة من النموذج الأصلي',
                'mountedActions.0.data.parent_public_id' => $f['unit']->public_id]);
            $unit = DB::table('organizational_units')->where('organization_id', $f['organization']->id)->where('name', 'وحدة من النموذج الأصلي')->sole();
            $this->assertSame($f['unit']->id, $unit->parent_id);
            $this->transport($snapshot, 'mountAction', ['renameUnit', [], ['table' => true, 'recordKey' => $unit->public_id]]);
            $this->transport($snapshot, 'callMountedAction', updates: ['mountedActions.0.data.name' => 'وحدة بعد التعديل']);
            $this->assertDatabaseHas('organizational_units', ['id' => $unit->id, 'organization_id' => $f['organization']->id, 'name' => 'وحدة بعد التعديل']);
            $this->transport($snapshot, 'mountAction', ['moveUnit', [], ['table' => true, 'recordKey' => $unit->public_id]]);
            $this->transport($snapshot, 'callMountedAction', updates: ['mountedActions.0.data.parent_public_id' => null]);
            $this->assertDatabaseHas('organizational_units', ['id' => $unit->id, 'organization_id' => $f['organization']->id, 'parent_id' => null]);
            $this->assertSame(['created', 'renamed', 'reparented'], DB::table('organizational_unit_changes')->where('unit_id', $unit->id)->orderBy('id')->pluck('event')->all());
            $this->get('/institution/mvp-workspace/organization')->assertOk()->assertSee('وحدة بعد التعديل');
        });
    }

    public function test_revoked_management_permission_on_a_mounted_native_action_denies_without_write(): void
    {
        $f = $this->fixture();
        ReviewMaturityPanelProvider::withFixture($f['panel_proof'], function () use ($f): void {
            $this->post('/institution/login', ['organization' => $f['organization']->public_id, 'email' => $f['author']->email, 'password' => 'password'])->assertRedirect();
            $snapshot = $this->snapshot($this->get('/institution/mvp-workspace/organization')->assertOk());
            $this->transport($snapshot, 'mountAction', ['createUnit']);
            app(TenantContext::class)->run($f['issuer'], $f['organization']->id, function () use ($f): void {
                $role = Role::where('organization_id', $f['organization']->id)->where('name', 'unit-ui-'.$f['proof']['fixture_id'])->firstOrFail();
                app(RoleManagement::class)->syncPermissions($role, ['organization.units.view']);
            });
            $this->transport($snapshot, 'callMountedAction', updates: ['mountedActions.0.data.name' => 'يجب ألا تحفظ'], status: 403);
            $this->assertDatabaseMissing('organizational_units', ['organization_id' => $f['organization']->id, 'name' => 'يجب ألا تحفظ']);
            $this->assertDatabaseCount('organizational_unit_changes', 0);
        });
    }

    public function test_native_cycle_rejection_is_visible_on_the_actual_parent_field_without_a_write(): void
    {
        $f = $this->fixture();
        ReviewMaturityPanelProvider::withFixture($f['panel_proof'], function () use ($f): void {
            $this->post('/institution/login', ['organization' => $f['organization']->public_id, 'email' => $f['author']->email, 'password' => 'password'])->assertRedirect();
            $snapshot = $this->snapshot($this->get('/institution/mvp-workspace/organization')->assertOk());
            $this->transport($snapshot, 'mountAction', ['createUnit']);
            $this->transport($snapshot, 'callMountedAction', updates: ['mountedActions.0.data.name' => 'وحدة فرعية لاختبار الدورة',
                'mountedActions.0.data.parent_public_id' => $f['unit']->public_id]);
            $child = DB::table('organizational_units')->where('organization_id', $f['organization']->id)->where('name', 'وحدة فرعية لاختبار الدورة')->sole();
            $this->createdUnitIds[$f['organization']->id][] = $child->id;
            $before = DB::table('organizational_unit_changes')->count();
            $this->transport($snapshot, 'mountAction', ['moveUnit', [], ['table' => true, 'recordKey' => $f['unit']->public_id]]);
            $this->transport($snapshot, 'callMountedAction', updates: ['mountedActions.0.data.parent_public_id' => $child->public_id]);
            $this->assertSame(['لا يمكن نقل الوحدة إلى نفسها أو إلى أحد فروعها.'],
                json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR)['memo']['errors']['mountedActions.0.data.parent_public_id'] ?? []);
            $this->assertDatabaseHas('organizational_units', ['id' => $f['unit']->id, 'parent_id' => null]);
            $this->assertDatabaseHas('organizational_units', ['id' => $child->id, 'parent_id' => $f['unit']->id]);
            $this->assertSame($before, DB::table('organizational_unit_changes')->count());
        });
    }

    public function test_view_only_actor_does_not_receive_or_execute_management_action(): void
    {
        $f = $this->fixture(manage: false);
        ReviewMaturityPanelProvider::withFixture($f['panel_proof'], function () use ($f): void {
            $this->post('/institution/login', ['organization' => $f['organization']->public_id, 'email' => $f['author']->email, 'password' => 'password'])->assertRedirect();
            $response = $this->get('/institution/mvp-workspace/organization')->assertOk()->assertDontSee('إنشاء وحدة');
            $snapshot = $this->snapshot($response);
            $this->transport($snapshot, 'mountAction', ['createUnit']);
            $this->transport($snapshot, 'callMountedAction', status: 403);
            $this->assertDatabaseCount('organizational_unit_changes', 0);
        });
    }

    public function test_foreign_and_numeric_native_table_record_keys_cannot_mutate_another_unit(): void
    {
        $f = $this->fixture();
        $foreign = $this->reviewMaturityFixture();
        ReviewMaturityPanelProvider::withFixture($f['panel_proof'], function () use ($f, $foreign): void {
            $this->post('/institution/login', ['organization' => $f['organization']->public_id, 'email' => $f['author']->email, 'password' => 'password'])->assertRedirect();
            foreach ([$foreign['unit']->public_id, (string) $foreign['unit']->id] as $key) {
                $snapshot = $this->snapshot($this->get('/institution/mvp-workspace/organization')->assertOk());
                $this->transport($snapshot, 'mountAction', ['renameUnit', [], ['table' => true, 'recordKey' => $key]]);
                $this->transport($snapshot, 'callMountedAction', updates: ['mountedActions.0.data.name' => 'ممنوع'], status: 403);
                $this->assertSame($foreign['unit']->name, $foreign['unit']->fresh()->name);
            }
        });
        $this->assertDatabaseCount('organizational_unit_changes', 0);
    }

    public function test_actual_password_reset_invalidates_a_mounted_native_unit_action(): void
    {
        $f = $this->fixture();
        ReviewMaturityPanelProvider::withFixture($f['panel_proof'], function () use ($f): void {
            $this->post('/institution/login', ['organization' => $f['organization']->public_id, 'email' => $f['author']->email, 'password' => 'password'])->assertRedirect();
            $snapshot = $this->snapshot($this->get('/institution/mvp-workspace/organization')->assertOk());
            $this->transport($snapshot, 'mountAction', ['createUnit']);
            $token = Password::broker('institution')->createToken($f['author']);
            $this->assertTrue(app(InstitutionRecovery::class)->reset($f['organization']->public_id, $f['author']->public_id, $token, 'Changed-fictional-fixture-only-2026!'));
            $this->transport($snapshot, 'callMountedAction', updates: ['mountedActions.0.data.name' => 'لا تحفظ بعد الاستعادة'], status: 403);
            $this->assertDatabaseMissing('organizational_units', ['organization_id' => $f['organization']->id, 'name' => 'لا تحفظ بعد الاستعادة']);
            $this->assertDatabaseCount('organizational_unit_changes', 0);
        });
    }

    public function test_fixture_schema_mapping_is_available_before_authentication_without_granting_guest_access(): void
    {
        $f = $this->fixture();
        Auth::guard('institution')->logoutCurrentDevice();
        app('session.store')->flush();
        Auth::forgetGuards();
        $observed = false;
        View::composer('institution.entry', function () use (&$observed): void {
            $observed = true;
            $this->assertSame(Organization::class, app(CapabilityRegistry::class)->resourceClass('organization.units.view'));
        });
        ReviewMaturityPanelProvider::withFixture($f['panel_proof'], function (): void {
            $this->get('/institution/login')->assertOk();
            $this->get('/institution/mvp-workspace/organization')->assertRedirect('/institution/login');
        });
        $this->assertTrue($observed);
        $this->assertDatabaseCount('organizational_unit_changes', 0);
    }
}
