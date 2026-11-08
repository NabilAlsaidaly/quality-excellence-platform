<?php

namespace Tests\Feature\Filament;

use App\Actions\Approaches\CreateApproach;
use App\Authorization\RoleManagement;
use App\Authorization\TenantContext;
use App\Filament\Institution\Approaches\ApproachAccess;
use App\Filament\Institution\InstitutionPanel;
use App\Filament\Institution\Resources\ApproachResource;
use App\Filament\Institution\Resources\ApproachResource\Pages\ApproachBank;
use App\Filament\Institution\Resources\ApproachResource\Pages\ApproachWorkspace;
use App\Models\Approach;
use App\Models\Organization;
use App\Models\OrganizationalUnit;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Policies\ApproachPolicy;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/** Test-only authority, never bound/configured by the shipped application. */
class SyntheticApproachPolicy extends ApproachPolicy
{
    public static array $grants = [];

    protected function hasAssignment(User $actor, string $operation, ?Approach $resource): bool
    {
        return self::$grants[$actor->id][$operation][$resource?->public_id ?? '*'] ?? false;
    }
}

trait ApproachPanelFixtures
{
    private function fixture44(bool $grant = true): array
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['principal_type' => 'institution', 'organization_id' => $organization->id]);
        OrganizationMembership::factory()->create(['organization_id' => $organization->id, 'user_id' => $actor->id]);
        $unit = OrganizationalUnit::factory()->for($organization)->create(['name' => 'وحدة اختبار عربية']);
        $approach = app(TenantContext::class)->run($actor, $organization->id,
            fn () => app(CreateApproach::class)->execute($actor, $this->data44($unit)));
        if ($grant) {
            config(['authorization.capabilities' => ['approaches.access' => Organization::class]]);
            app(TenantContext::class)->run($actor, $organization->id, function () use ($actor): void {
                $roles = app(RoleManagement::class);
                $role = $roles->create('synthetic-issue44');
                $roles->syncPermissions($role, ['approaches.access']);
                $roles->assign($role, $actor);
            });
            foreach (ApproachPolicy::OPERATIONS as $operation) {
                SyntheticApproachPolicy::$grants[$actor->id][$operation][in_array($operation, ['list', 'create'], true) ? '*' : $approach->public_id] = true;
            }
        }

        return [$actor, $organization, $unit, $approach];
    }

    private function data44(OrganizationalUnit $unit, array $overrides = []): array
    {
        return array_replace(['name' => 'منهجية اختبار عربية', 'description' => 'وصف اختباري', 'purpose' => 'غاية اختبارية',
            'scope' => 'نطاق اختباري', 'owning_unit_public_id' => $unit->public_id], $overrides);
    }

    private function login44(User $actor, Organization $organization): void
    {
        $this->actingAs($actor, 'institution');
        $this->withSession(['identity.active_guard' => 'institution', 'institution.organization' => $organization->public_id,
            'institution.password_hash' => $actor->getAuthPassword()]);
    }

    private function deny44(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Unapproved operation must be denied.');
        } catch (AuthorizationException|ModelNotFoundException) {
            $this->assertNull(app(TenantContext::class)->organizationId());
        }
    }
}

class ApproachFilamentIntegrationTest extends TestCase
{
    use ApproachPanelFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        SyntheticApproachPolicy::$grants = [];
        app()->bind(ApproachPolicy::class, SyntheticApproachPolicy::class);
        Filament::setCurrentPanel(InstitutionPanel::make());
        Filament::bootCurrentPanel();
        FilamentColor::register(Filament::getCurrentPanel()->getColors());
        // URL generation fixtures only. No candidate provider/operational route registration.
        Route::get('/__issue44/approaches', fn () => abort(403))->name('filament.institution.resources.approaches.index');
        Route::get('/__issue44/approaches/{approach}', fn () => abort(403))->name('filament.institution.resources.approaches.workspace');
    }

    public function test_native_table_and_create_action_delegate_to_existing_domain_action(): void
    {
        [$actor, $organization, $unit, $approach] = $this->fixture44();
        $this->login44($actor, $organization);
        $page = Livewire::test(ApproachBank::class)->assertOk()->assertSee($approach->name)->assertSee('بنك المنهجيات');
        $this->visual44('bank', $page->html());
        $this->assertFalse($page->instance()->getTable()->hasQuery());
        $page->callAction('create', data: $this->data44($unit, ['name' => 'منهجية جديدة']))->assertHasNoActionErrors();
        $this->assertDatabaseHas('approaches', ['name' => 'منهجية جديدة', 'organization_id' => $organization->id, 'created_by' => $actor->id]);
        $this->assertSame(0, ApproachResource::getEloquentQuery()->count());
        $this->assertNull(app(TenantContext::class)->organizationId());
    }

    public function test_native_workspace_edits_owner_and_drafts_preserve_history_and_do_not_publish(): void
    {
        [$actor, $organization, $unit, $approach] = $this->fixture44();
        $other = OrganizationalUnit::factory()->for($organization)->create();
        $this->login44($actor, $organization);
        $page = Livewire::test(ApproachWorkspace::class, ['approach' => $approach->public_id])->assertOk()->assertSee('لا توجد نسخة منشورة معتمدة');
        $page->callAction('edit', data: ['name' => 'اسم معدل'])->assertHasNoActionErrors();
        $page->callAction('transfer', data: ['owning_unit_public_id' => $other->public_id])->assertHasNoActionErrors();
        $page->callAction('draft', data: ['body' => 'مسودة أولى <script>alert(1)</script>'])->assertHasNoActionErrors();
        $page->callAction('draft', data: ['body' => 'مسودة ثانية'])->assertHasNoActionErrors()->assertSee('مسودة ثانية');
        $this->assertDatabaseCount('approach_changes', 2);
        $this->assertDatabaseCount('approach_content_revisions', 2);
        $this->assertSame($other->id, $approach->fresh()->owning_unit_id);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $page->html());
        $page->set('historyType', 'audit')->assertSee('اسم معدل')->assertSee($unit->name);
        foreach (['organization_id', 'actor_id', 'owning_unit_id', 'created_by', $actor->email] as $private) {
            $this->assertStringNotContainsString($private, $page->html());
        }
        $this->assertFalse(app(ApproachPolicy::class)->approve($actor, $approach));
        $this->visual44('workspace', $page->html());
        $page->mountAction('edit');
        $this->visual44('edit', $page->html());
    }

    public function test_default_policy_and_membership_never_grant_backend_operations(): void
    {
        [$actor, $organization, $unit, $approach] = $this->fixture44();
        $this->login44($actor, $organization);
        app()->bind(ApproachPolicy::class, fn () => new ApproachPolicy);
        $access = app(ApproachAccess::class);
        foreach (ApproachPolicy::OPERATIONS as $operation) {
            $this->assertFalse($access->can($operation, in_array($operation, ['list', 'create'], true) ? null : $approach->public_id));
        }
        $this->deny44(fn () => $access->create($this->data44($unit)));
        $this->deny44(fn () => $access->update($approach->public_id, ['name' => 'forbidden'], str_repeat('a', 64)));
        $this->deny44(fn () => $access->changeOwner($approach->public_id, $unit->public_id, str_repeat('a', 64)));
        $this->deny44(fn () => $access->saveDraft($approach->public_id, 'forbidden', 'none'));
        $this->deny44(fn () => $access->history($approach->public_id, 'audit'));
        Livewire::test(ApproachBank::class)->assertForbidden();
        $this->assertDatabaseCount('approaches', 1);
        $this->assertDatabaseCount('approach_changes', 0);
        $this->assertDatabaseCount('approach_content_revisions', 0);
    }

    public function test_cross_tenant_forged_numeric_owner_and_actor_fields_fail_without_changes(): void
    {
        [$actor, $organization, $unit, $approach] = $this->fixture44();
        [, $foreign, $foreignUnit, $foreignApproach] = $this->fixture44();
        $this->login44($actor, $organization);
        $access = app(ApproachAccess::class);
        $this->deny44(fn () => $access->show($foreignApproach->public_id));
        $this->deny44(fn () => $access->show((string) $approach->id));
        $this->deny44(fn () => $access->create($this->data44($foreignUnit)));
        $this->deny44(fn () => $access->changeOwner($approach->public_id, $foreignUnit->public_id, $access->show($approach->public_id)['edit_token']));
        foreach (['organization_id', 'actor_id', 'user_id', 'owning_unit_id', 'public_id', 'status'] as $key) {
            try {
                $access->create($this->data44($unit, [$key => $foreign->id]));
                $this->fail('Forged authority field accepted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('approaches', 2);
            }
        }
        Livewire::test(ApproachWorkspace::class, ['approach' => $foreignApproach->public_id])->assertNotFound();
        $this->assertDatabaseCount('approach_changes', 0);
    }

    public function test_assignment_and_membership_revocation_deny_livewire_and_direct_callbacks(): void
    {
        [$actor, $organization, , $approach] = $this->fixture44();
        $this->login44($actor, $organization);
        $page = Livewire::test(ApproachWorkspace::class, ['approach' => $approach->public_id])->mountAction('edit');
        SyntheticApproachPolicy::$grants[$actor->id]['updateMetadata'] = [];
        $this->deny44(fn () => app(ApproachAccess::class)->update($approach->public_id, ['name' => 'forbidden'], app(ApproachAccess::class)->show($approach->public_id)['edit_token']));
        $page->fillForm(['name' => 'forbidden'])->callMountedAction();
        $this->assertSame('منهجية اختبار عربية', $approach->fresh()->name);
        app(TenantContext::class)->run($actor, $organization->id,
            fn () => app(RoleManagement::class)->revoke($actor->roles()->firstOrFail(), $actor));
        OrganizationMembership::query()->where('user_id', $actor->id)->delete();
        $page->call('$refresh')->assertForbidden();
        $this->assertDatabaseCount('approach_changes', 0);
    }

    public function test_read_metadata_does_not_expose_drafts_or_history_or_grant_editing(): void
    {
        [$actor, $organization, , $approach] = $this->fixture44();
        $this->login44($actor, $organization);
        app(ApproachAccess::class)->saveDraft($approach->public_id, 'SECRET-DRAFT-44', 'none');
        foreach (['editDraft', 'viewDraftHistory', 'viewAuditHistory', 'changeOwner', 'updateMetadata'] as $operation) {
            SyntheticApproachPolicy::$grants[$actor->id][$operation] = [];
        }
        Livewire::test(ApproachWorkspace::class, ['approach' => $approach->public_id])->assertOk()->assertDontSee('SECRET-DRAFT-44')
            ->assertDontSee('حفظ إصدار مسودة')->assertDontSee('تعديل البيانات التعريفية')->assertSee('لا توجد نسخة منشورة معتمدة');
        $this->deny44(fn () => app(ApproachAccess::class)->history($approach->public_id, 'draft'));
    }

    public function test_stale_metadata_owner_and_draft_updates_return_409_without_extra_history(): void
    {
        [$actor, $organization, $unit, $approach] = $this->fixture44();
        $this->login44($actor, $organization);
        $access = app(ApproachAccess::class);
        $token = $access->show($approach->public_id)['edit_token'];
        $access->update($approach->public_id, ['name' => 'first'], $token);
        $draft = $access->draftToken($approach->public_id);
        $access->saveDraft($approach->public_id, 'first body', $draft);
        foreach ([fn () => $access->update($approach->public_id, ['name' => 'lost'], $token),
            fn () => $access->changeOwner($approach->public_id, $unit->public_id, $token),
            fn () => $access->saveDraft($approach->public_id, 'lost', $draft)] as $operation) {
            try {
                $operation();
                $this->fail('Stale write accepted.');
            } catch (ConflictHttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
        }
        $this->assertDatabaseCount('approach_changes', 1);
        $this->assertDatabaseCount('approach_content_revisions', 1);
        $this->assertSame('first', $approach->fresh()->name);
    }

    public function test_legacy_platform_wrong_session_and_stale_password_are_denied(): void
    {
        [$actor, $organization, , $approach] = $this->fixture44();
        $this->login44($actor, $organization);
        foreach (['web', 'platform', null] as $marker) {
            app('session.store')->put('identity.active_guard', $marker);
            $this->assertFalse(app(ApproachAccess::class)->can('view', $approach->public_id));
        }
        $this->login44($actor, $organization);
        app('session.store')->put('institution.password_hash', 'stale');
        $this->assertFalse(app(ApproachAccess::class)->can('list'));
        $legacy = User::factory()->create();
        $this->login44($legacy, $organization);
        $this->assertFalse(app(ApproachAccess::class)->can('list'));
        $platform = User::factory()->create(['principal_type' => 'platform']);
        $this->login44($platform, $organization);
        $this->assertFalse(app(ApproachAccess::class)->can('view', $approach->public_id));
        Auth::guard('institution')->forgetUser();
        $this->assertNull(app(TenantContext::class)->actorId());
    }

    public function test_real_livewire_stale_edit_returns_http_409_and_preserves_winning_audit(): void
    {
        [$actor, $organization, , $approach] = $this->fixture44();
        $this->login44($actor, $organization);
        $page = Livewire::test(ApproachWorkspace::class, ['approach' => $approach->public_id])->mountAction('edit');
        $access = app(ApproachAccess::class);
        $access->update($approach->public_id, ['name' => 'winning edit'], $access->show($approach->public_id)['edit_token']);
        $page->fillForm(['name' => 'losing edit'])->callMountedAction()->assertStatus(409);
        $this->assertSame('winning edit', $approach->fresh()->name);
        $this->assertDatabaseCount('approach_changes', 1);
    }

    public function test_locked_public_resource_cannot_be_switched_in_a_livewire_request(): void
    {
        [$actor, $organization, , $approach] = $this->fixture44();
        [, , , $foreign] = $this->fixture44();
        $this->login44($actor, $organization);
        $page = Livewire::test(ApproachWorkspace::class, ['approach' => $approach->public_id]);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set('approach', $foreign->public_id);
    }

    public function test_role_revocation_and_unknown_history_or_upload_calls_fail_closed(): void
    {
        [$actor, $organization, , $approach] = $this->fixture44();
        $this->login44($actor, $organization);
        $page = Livewire::test(ApproachWorkspace::class, ['approach' => $approach->public_id]);
        $page->call('_startUpload', 'file', [], false)->assertForbidden();
        $this->deny44(fn () => app(ApproachAccess::class)->history($approach->public_id, 'all'));
        app(TenantContext::class)->run($actor, $organization->id,
            fn () => app(RoleManagement::class)->revoke($actor->roles()->firstOrFail(), $actor));
        $this->assertFalse(app(ApproachAccess::class)->can('view', $approach->public_id));
        Livewire::test(ApproachWorkspace::class, ['approach' => $approach->public_id])->assertForbidden();
    }

    public function test_same_owner_unit_or_create_permission_does_not_assign_another_approach(): void
    {
        [$actor, $organization, $unit, $approach] = $this->fixture44();
        $this->login44($actor, $organization);
        $other = app(ApproachAccess::class)->create($this->data44($unit, ['name' => 'مورد غير مسند']));
        foreach (['view', 'updateMetadata', 'changeOwner', 'editDraft', 'viewDraftHistory', 'viewAuditHistory'] as $operation) {
            $this->assertFalse(app(ApproachAccess::class)->can($operation, $other));
            $this->assertTrue(app(ApproachAccess::class)->can($operation, $approach->public_id));
        }
        $this->assertDatabaseCount('approach_changes', 0);
        $this->assertDatabaseCount('approach_content_revisions', 0);
    }

    private function visual44(string $name, string $html): void
    {
        if (getenv('ISSUE44_VISUAL_FIXTURES') !== '1') {
            return;
        }
        $directory = base_path('.local-tools/output/playwright/issue44');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true, flags: JSON_THROW_ON_ERROR);
        $css = file_get_contents(public_path('build/'.$manifest['resources/css/filament/admin/theme.css']['file']));
        $styles = preg_replace('#<link\b[^>]*href="[^"]*/fonts/filament/[^"]*"[^>]*>#', '', Blade::render('@filamentStyles @livewireStyles'));
        $snapshot = preg_replace('/wire:(?:snapshot|effects)="[^"]*"/', '', $html);
        file_put_contents($directory.'/'.$name.'.html', '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="icon" href="data:,"><title>اختبار بنك المنهجيات</title>'.$styles.'<style>'.$css.'</style></head><body class="fi-body"><main class="fi-main fi-width-7xl">'.$snapshot.'</main></body></html>');
    }
}
