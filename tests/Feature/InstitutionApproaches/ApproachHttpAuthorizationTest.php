<?php

namespace Tests\Feature\InstitutionApproaches;

use App\Actions\Approaches\CreateApproach;
use App\Actions\Approaches\ListApproaches;
use App\Authorization\TenantContext;
use App\Filament\Institution\InstitutionAuthenticate;
use App\Filament\Institution\InstitutionPanelEnvironment;
use App\Filament\Institution\InstitutionPanelSession;
use App\Http\Controllers\Institution\ApproachContentController;
use App\Http\Controllers\Institution\ApproachController;
use App\Http\Middleware\InstitutionSession;
use App\Models\Approach;
use App\Models\Organization;
use App\Models\OrganizationalUnit;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/** All mounted routes and Policy assignments below exist ONLY in this PHPUnit application. */
class ApproachHttpAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SyntheticApproachPolicy::$grants = [];
        foreach (Route::getRoutes() as $route) {
            $native = [
                'filament.institution.resources.approaches.index' => 'institution/workspace/approaches',
                'filament.institution.resources.approaches.workspace' => 'institution/workspace/approaches/{approach}',
            ];
            if (array_key_exists($route->getName() ?? '', $native)) {
                $this->assertSame($native[$route->getName()], $route->uri());
                foreach ([InstitutionPanelEnvironment::class, InstitutionPanelSession::class, InstitutionAuthenticate::class] as $middleware) {
                    $this->assertContains($middleware, $route->gatherMiddleware());
                }

                continue;
            }
            $this->assertStringNotContainsString('approaches', $route->uri(), 'Operational routes must remain absent.');
        }
        Route::middleware(['web', InstitutionSession::class])->prefix('institution/{organization}/approaches')
            ->name('institution.approaches.')->group(function (): void {
                Route::get('/', [ApproachController::class, 'index'])->name('index');
                Route::get('/create', [ApproachController::class, 'create'])->name('create');
                Route::post('/', [ApproachController::class, 'store'])->name('store');
                Route::get('/{approach}', [ApproachController::class, 'show'])->name('show');
                Route::get('/{approach}/edit', [ApproachController::class, 'edit'])->name('edit');
                Route::patch('/{approach}', [ApproachController::class, 'update'])->name('update');
                Route::get('/{approach}/owner', [ApproachController::class, 'owner'])->name('owner');
                Route::post('/{approach}/owner', [ApproachController::class, 'transfer'])->name('owner.store');
                Route::get('/{approach}/content', [ApproachContentController::class, 'show'])->name('content');
                Route::post('/{approach}/content', [ApproachContentController::class, 'store'])->name('content.store');
            });
        Route::getRoutes()->refreshNameLookups();
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['principal_type' => 'institution', 'organization_id' => $organization->id,
            'email' => 'fixture@example.test', 'password' => 'fixture-account-password']);
        $member = OrganizationMembership::factory()->create(['organization_id' => $organization->id, 'user_id' => $actor->id]);
        $unit = OrganizationalUnit::factory()->for($organization)->create();

        return [$member, $unit];
    }

    private function login(OrganizationMembership $member): void
    {
        $this->post('/institution/login', ['organization' => $member->organization->public_id,
            'email' => $member->user->email, 'password' => 'fixture-account-password'])
            ->assertRedirect('/institution/'.$member->organization->public_id.'/account');
    }

    private function grants(OrganizationMembership $member, array $abilities, array|string $resources = '*'): void
    {
        Gate::policy(Approach::class, SyntheticApproachPolicy::class);
        SyntheticApproachPolicy::$grants[$member->user_id] = ['abilities' => $abilities, 'resources' => $resources];
    }

    private function url(OrganizationMembership $member, string $tail = ''): string
    {
        return '/institution/'.$member->organization->public_id.'/approaches'.$tail;
    }

    private function data(OrganizationalUnit $unit): array
    {
        return ['name' => 'منهجية <script>unsafe</script>', 'description' => 'وصف عربي', 'purpose' => 'غاية', 'scope' => 'نطاق',
            'owning_unit_public_id' => $unit->public_id];
    }

    private function create(OrganizationMembership $member, OrganizationalUnit $unit): Approach
    {
        return app(TenantContext::class)->run($member->user, $member->organization_id,
            fn () => app(CreateApproach::class)->execute($member->user, $this->data($unit)));
    }

    private function token(OrganizationMembership $member, Approach $approach): string
    {
        return app(TenantContext::class)->run($member->user, $member->organization_id,
            fn () => app(ListApproaches::class)->show($member->user, $approach->public_id))['edit_token'];
    }

    public function test_no_policy_denies_valid_member_and_all_read_write_adapters(): void
    {
        [$member, $unit] = $this->fixture();
        $approach = $this->create($member, $unit);
        $this->login($member);
        foreach (['', '/create', '/'.$approach->public_id, '/'.$approach->public_id.'/edit', '/'.$approach->public_id.'/owner', '/'.$approach->public_id.'/content'] as $tail) {
            $this->get($this->url($member, $tail))->assertForbidden()->assertDontSee('وصف عربي');
        }
        $this->post($this->url($member), $this->data($unit))->assertForbidden();
        $this->patch($this->url($member, '/'.$approach->public_id), ['name' => 'forged'])->assertForbidden();
        $this->post($this->url($member, '/'.$approach->public_id.'/owner'), ['owning_unit_public_id' => $unit->public_id])->assertForbidden();
        $this->post($this->url($member, '/'.$approach->public_id.'/content'), ['body' => 'forged'])->assertForbidden();
        $this->assertDatabaseCount('approach_changes', 0);
        $this->assertDatabaseCount('approach_content_revisions', 0);
        $this->assertSame([], config('authorization.capabilities'));
        $this->assertDatabaseCount('model_has_roles', 0);
    }

    public function test_guest_web_and_platform_guard_cannot_supply_institutional_authority(): void
    {
        [$member, $unit] = $this->fixture();
        $this->get($this->url($member))->assertRedirect('/institution/login');
        $this->post($this->url($member), $this->data($unit))->assertRedirect('/institution/login');
        $web = User::factory()->create();
        $this->actingAs($web, 'web')->get($this->url($member))->assertRedirect('/institution/login');
        $platform = User::factory()->make();
        $platform->principal_type = 'platform';
        $platform->save();
        $this->actingAs($platform, 'web')->post($this->url($member), $this->data($unit))->assertRedirect('/institution/login');
        $this->assertNull(app(TenantContext::class)->organizationId());
        $this->assertDatabaseCount('approaches', 0);
    }

    public function test_synthetic_only_complete_http_flow_creates_updates_transfers_and_appends_drafts(): void
    {
        [$member, $unit] = $this->fixture();
        $other = OrganizationalUnit::factory()->for($member->organization)->create();
        $this->grants($member, ['viewAny', 'view', 'create', 'updateMetadata', 'changeOwner', 'editDraft', 'viewDraftHistory', 'viewAuditHistory']);
        $this->login($member);
        $this->get($this->url($member))->assertOk()->assertSee('لا توجد منهجيات');
        $create = $this->get($this->url($member, '/create'))->assertOk()->assertSee('الوحدة المالكة الرئيسية');
        $this->post($this->url($member), $this->data($unit))->assertRedirect();
        $approach = Approach::sole();
        $show = $this->get($this->url($member, '/'.$approach->public_id))->assertOk()->assertSee('unsafe')->assertDontSee('<script>', false);
        $edit = $this->get($this->url($member, '/'.$approach->public_id.'/edit'))->assertOk()->assertSee('edit_token', false);
        $this->patch($this->url($member, '/'.$approach->public_id), ['name' => 'اسم معدّل', 'edit_token' => $this->token($member, $approach)])
            ->assertRedirect($this->url($member, '/'.$approach->public_id));
        $owner = $this->get($this->url($member, '/'.$approach->public_id.'/owner'))->assertOk();
        $this->assertMatchesRegularExpression('/أؤكد\s+اختيار\s+الوحدة\s+ونقل\s+الملكية\s+الرئيسية\s+إليها/u', strip_tags($owner->getContent()));
        $this->post($this->url($member, '/'.$approach->public_id.'/owner'), ['owning_unit_public_id' => $other->public_id,
            'edit_token' => $this->token($member, $approach), 'confirm' => '1'])->assertRedirect($this->url($member, '/'.$approach->public_id));
        $audit = $this->get($this->url($member, '/'.$approach->public_id))->assertOk()->assertSee('نقل الوحدة المالكة')->assertSee('اسم معدّل');
        $this->post($this->url($member, '/'.$approach->public_id.'/content'), ['body' => '<script>draft</script>'])->assertRedirect();
        $content = $this->get($this->url($member, '/'.$approach->public_id.'/content'))->assertOk()
            ->assertSee('&lt;script&gt;draft&lt;/script&gt;', false)->assertDontSee('<script>', false)->assertSee('lang="ar" dir="rtl"', false);
        $this->assertDatabaseCount('approach_changes', 2);
        $this->assertDatabaseCount('approach_content_revisions', 1);
        $this->assertSame($other->id, $approach->fresh()->owning_unit_id);
        $this->assertNull(app(TenantContext::class)->organizationId());
        // Optional private, sanitized visual fixtures; never route/Policy activation.
        if (getenv('ISSUE40_VISUAL_FIXTURES') === '1') {
            $directory = base_path('.local-tools/output/issue40');
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            foreach (compact('create', 'show', 'edit', 'owner', 'audit', 'content') as $name => $response) {
                $html = preg_replace('/name="_token" value="[^"]*"/', 'name="_token" value="synthetic-redacted"', $response->getContent());
                file_put_contents($directory.'/'.$name.'.html', $html);
            }
        }
    }

    public function test_foreign_missing_and_numeric_resources_and_owners_fail_without_scope_leak(): void
    {
        [$a, $unit] = $this->fixture();
        [$b, $foreignUnit] = $this->fixture();
        $local = $this->create($a, $unit);
        $foreign = $this->create($b, $foreignUnit);
        $this->grants($a, ['view', 'updateMetadata', 'changeOwner', 'viewDraftHistory', 'editDraft', 'viewAuditHistory']);
        $this->login($a);
        foreach ([$foreign->public_id, (string) Str::ulid(), (string) $local->id, 'malformed'] as $id) {
            foreach (['', '/edit', '/owner', '/content'] as $suffix) {
                $this->get($this->url($a, '/'.$id.$suffix))->assertNotFound()->assertDontSee($foreignUnit->name);
            }
            $this->patch($this->url($a, '/'.$id), ['name' => 'forged', 'edit_token' => $this->token($a, $local)])->assertNotFound();
            $this->post($this->url($a, '/'.$id.'/content'), ['body' => 'forged'])->assertNotFound();
        }
        foreach ([$foreignUnit->public_id, (string) Str::ulid()] as $owner) {
            $this->post($this->url($a, '/'.$local->public_id.'/owner'), ['owning_unit_public_id' => $owner,
                'edit_token' => $this->token($a, $local), 'confirm' => '1'])->assertNotFound();
        }
        $this->assertDatabaseCount('approach_changes', 0);
        $this->assertDatabaseCount('approach_content_revisions', 0);
    }

    public function test_synthetic_assignment_is_per_resource_per_action_and_revocation_is_immediate(): void
    {
        [$member, $unit] = $this->fixture();
        $first = $this->create($member, $unit);
        $second = $this->create($member, $unit);
        $this->grants($member, ['view', 'updateMetadata'], [$first->public_id]);
        $this->login($member);
        $this->get($this->url($member, '/'.$first->public_id))->assertOk()->assertDontSee('سجل تغييرات البيانات والملكية');
        $this->get($this->url($member, '/'.$second->public_id))->assertForbidden();
        $this->get($this->url($member, '/'.$first->public_id.'/content'))->assertForbidden();
        $this->post($this->url($member, '/'.$first->public_id.'/owner'))->assertForbidden();
        $this->grants($member, [], [$first->public_id]);
        $this->patch($this->url($member, '/'.$first->public_id), ['name' => 'revoked', 'edit_token' => $this->token($member, $first)])->assertForbidden();
        $this->assertDatabaseCount('approach_changes', 0);
        $member->delete();
        $this->get($this->url($member, '/'.$first->public_id))->assertRedirect('/institution/login');
        $this->assertNull(app(TenantContext::class)->organizationId());
    }

    public function test_draft_read_and_write_are_independent_and_general_reader_never_sees_body(): void
    {
        [$member, $unit] = $this->fixture();
        $approach = $this->create($member, $unit);
        $this->grants($member, ['view', 'editDraft']);
        $this->login($member);
        $this->post($this->url($member, '/'.$approach->public_id.'/content'), ['body' => 'PRIVATE DRAFT BODY'])->assertRedirect();
        $this->get($this->url($member, '/'.$approach->public_id))->assertOk()->assertDontSee('PRIVATE DRAFT BODY')->assertDontSee('مسودات المحتوى');
        $this->get($this->url($member, '/'.$approach->public_id.'/content'))->assertForbidden();
        $this->grants($member, ['viewDraftHistory']);
        $this->get($this->url($member, '/'.$approach->public_id.'/content'))->assertOk()->assertSee('PRIVATE DRAFT BODY')->assertDontSee('<form', false);
        $this->post($this->url($member, '/'.$approach->public_id.'/content'), ['body' => 'not authorized'])->assertForbidden();
        $this->assertDatabaseCount('approach_content_revisions', 1);
    }

    public function test_unknown_payloads_invalid_data_and_stale_forms_never_write_or_redirect_to_referer(): void
    {
        [$member, $unit] = $this->fixture();
        $approach = $this->create($member, $unit);
        $this->grants($member, ['create', 'updateMetadata', 'changeOwner', 'editDraft']);
        $this->login($member);
        $base = $this->url($member, '/'.$approach->public_id);
        $token = $this->token($member, $approach);
        foreach (['organization_id', 'actor_id', 'public_id', 'approved_at', 'owning_unit_id'] as $field) {
            $this->patchJson($base, ['name' => 'forged', 'edit_token' => $token, $field => 'forged'])->assertUnprocessable();
        }
        $this->postJson($this->url($member), $this->data($unit) + ['organization_id' => $member->organization_id])->assertUnprocessable();
        $this->postJson($base.'/content', ['body' => 'draft', 'actor_id' => $member->user_id])->assertUnprocessable();
        $this->postJson($base.'/owner', ['owning_unit_public_id' => $unit->public_id, 'edit_token' => $token])->assertUnprocessable();
        $this->withHeader('Referer', 'https://untrusted.example/steal')->patch($base, ['name' => '', 'edit_token' => $token])
            ->assertRedirect($base.'/edit')->assertSessionHasErrors('name');
        $this->withHeader('Referer', 'https://untrusted.example/steal')->post($base.'/content', ['body' => "\u{2003}"])
            ->assertRedirect($base.'/content')->assertSessionHasErrors('body');
        $this->patchJson($base, ['name' => 'winner', 'edit_token' => $token])->assertRedirect($base);
        $this->patchJson($base, ['name' => 'loser', 'edit_token' => $token])->assertConflict();
        $this->assertSame('winner', $approach->fresh()->name);
        $this->assertDatabaseCount('approach_changes', 1);
        $this->assertDatabaseCount('approach_content_revisions', 0);
    }

    public function test_real_csrf_middleware_rejects_every_mutation_without_token(): void
    {
        [$member, $unit] = $this->fixture();
        $approach = $this->create($member, $unit);
        $this->grants($member, ['create', 'updateMetadata', 'changeOwner', 'editDraft']);
        $this->login($member);
        // Laravel intentionally skips CSRF in testing; local re-enables the REAL middleware.
        $this->app->instance('env', 'local');
        $this->post($this->url($member), $this->data($unit))->assertStatus(419);
        $this->patch($this->url($member, '/'.$approach->public_id), ['name' => 'forged'])->assertStatus(419);
        $this->post($this->url($member, '/'.$approach->public_id.'/owner'))->assertStatus(419);
        $this->post($this->url($member, '/'.$approach->public_id.'/content'), ['body' => 'forged'])->assertStatus(419);
        $this->assertDatabaseCount('approach_changes', 0);
        $this->assertDatabaseCount('approach_content_revisions', 0);
    }

    public function test_forged_query_org_session_and_two_guard_switch_fail_closed(): void
    {
        [$a, $unit] = $this->fixture();
        [$b] = $this->fixture();
        $this->grants($a, ['viewAny', 'create']);
        $this->login($a);
        $this->get($this->url($a).'?organization_id='.$b->organization_id)->assertUnprocessable();
        $this->get($this->url($b))->assertRedirect('/institution/login');
        $this->login($a);
        $this->withSession(['institution.organization' => $b->organization->public_id])->get($this->url($a))->assertRedirect('/institution/login');
        $this->login($a);
        $this->actingAs(User::factory()->create(), 'web')->withSession(['identity.active_guard' => 'web'])
            ->get($this->url($a))->assertRedirect('/institution/login');
        $this->assertNull(app(TenantContext::class)->organizationId());
    }

    public function test_validation_redirect_keeps_original_proof_when_another_writer_changes_the_form(): void
    {
        [$member, $unit] = $this->fixture();
        $approach = $this->create($member, $unit);
        $this->grants($member, ['updateMetadata']);
        $this->login($member);
        $base = $this->url($member, '/'.$approach->public_id);
        $original = $this->token($member, $approach);
        $this->patch($base, ['name' => 'winner', 'edit_token' => $original])->assertRedirect($base);
        $this->patch($base, ['name' => '', 'edit_token' => $original])->assertRedirect($base.'/edit');
        $form = $this->get($base.'/edit')->assertOk();
        $form->assertSee('name="edit_token" value="'.$original.'"', false);
        $this->patch($base, ['name' => 'old form correction', 'edit_token' => $original])->assertConflict();
        $this->assertSame('winner', $approach->fresh()->name);
        $this->assertDatabaseCount('approach_changes', 1);
    }
}

/** Synthetic fixture authorization, deliberately absent from app/, providers and seeders. */
final class SyntheticApproachPolicy
{
    public static array $grants = [];

    private function allowed(User $actor, string $ability, ?string $resource): bool
    {
        $grant = self::$grants[$actor->id] ?? [];

        return in_array($ability, $grant['abilities'] ?? [], true)
            && (($grant['resources'] ?? null) === '*' || in_array($resource, $grant['resources'] ?? [], true));
    }

    public function viewAny(User $user, ?string $resource = null): bool
    {
        return $this->allowed($user, 'viewAny', $resource);
    }

    public function view(User $user, ?string $resource = null): bool
    {
        return $this->allowed($user, 'view', $resource);
    }

    public function create(User $user, ?string $resource = null): bool
    {
        return $this->allowed($user, 'create', $resource);
    }

    public function updateMetadata(User $user, ?string $resource = null): bool
    {
        return $this->allowed($user, 'updateMetadata', $resource);
    }

    public function changeOwner(User $user, ?string $resource = null): bool
    {
        return $this->allowed($user, 'changeOwner', $resource);
    }

    public function editDraft(User $user, ?string $resource = null): bool
    {
        return $this->allowed($user, 'editDraft', $resource);
    }

    public function viewDraftHistory(User $user, ?string $resource = null): bool
    {
        return $this->allowed($user, 'viewDraftHistory', $resource);
    }

    public function viewAuditHistory(User $user, ?string $resource = null): bool
    {
        return $this->allowed($user, 'viewAuditHistory', $resource);
    }
}
