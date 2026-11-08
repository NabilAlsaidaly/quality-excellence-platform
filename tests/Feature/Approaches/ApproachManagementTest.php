<?php

namespace Tests\Feature\Approaches;

use App\Actions\Approaches\ChangeApproachOwner;
use App\Actions\Approaches\CreateApproach;
use App\Actions\Approaches\ListApproaches;
use App\Actions\Approaches\SaveApproachContentRevision;
use App\Actions\Approaches\UpdateApproach;
use App\Authorization\TenantContext;
use App\Filament\Institution\InstitutionAuthenticate;
use App\Filament\Institution\InstitutionPanelEnvironment;
use App\Filament\Institution\InstitutionPanelSession;
use App\Models\Approach;
use App\Models\ApproachChange;
use App\Models\ApproachContentRevision;
use App\Models\OrganizationalUnit;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Filament\Panel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/** Positive operations below use a trusted synthetic INTERNAL boundary, not user permissions. */
class ApproachManagementTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $member = OrganizationMembership::factory()->create();
        $unit = OrganizationalUnit::factory()->for($member->organization)->create();

        return [$member, $unit];
    }

    private function data(OrganizationalUnit $unit, array $overrides = []): array
    {
        return array_replace(['name' => 'منهجية اختبار', 'description' => 'وصف المنهجية',
            'purpose' => 'الغاية', 'scope' => 'نطاق العمل', 'owning_unit_public_id' => $unit->public_id], $overrides);
    }

    private function trusted(OrganizationMembership $member, callable $operation): mixed
    {
        return app(TenantContext::class)->run($member->user, $member->organization_id, $operation(...));
    }

    public function test_core_fields_owner_and_optional_fields_persist_without_grants(): void
    {
        [$member, $unit] = $this->fixture();
        $data = $this->data($unit, ['name' => '  اسم عربي  ', 'objectives' => 'أهداف لاحقة']);
        $approach = $this->trusted($member, fn () => app(CreateApproach::class)->execute($member->user, $data));
        $this->assertSame($member->organization_id, $approach->organization_id);
        $this->assertSame($unit->id, $approach->owning_unit_id);
        $this->assertSame($member->user_id, $approach->created_by);
        $this->assertTrue(Str::isUlid($approach->public_id));
        $this->assertSame('public_id', $approach->getRouteKeyName());
        $read = $this->trusted($member, fn () => app(ListApproaches::class)->show($member->user, $approach->public_id));
        foreach (['name', 'description', 'purpose', 'scope', 'objectives'] as $field) {
            $this->assertSame($data[$field], $read[$field]);
        }
        $this->assertNull($read['review_frequency']);
        $this->assertSame(['public_id' => $unit->public_id, 'name' => $unit->name], $read['owning_unit']);
        foreach (['id', 'organization_id', 'owning_unit_id', 'created_by', 'body', 'status', 'score', 'approved'] as $key) {
            $this->assertArrayNotHasKey($key, $read);
        }
        $this->assertDatabaseCount('model_has_roles', 0);
        $this->assertSame([], config('authorization.capabilities'));
    }

    public static function invalidCore(): array
    {
        return [
            'empty name' => ['name', ''], 'unicode whitespace' => ['name', "\u{2003}"],
            'name bound' => ['name', str_repeat('م', 256)], 'missing description' => ['description', null],
            'blank purpose' => ['purpose', '   '], 'array scope' => ['scope', []],
            'text bound' => ['scope', str_repeat('م', 65536)], 'missing owner' => ['owning_unit_public_id', null],
        ];
    }

    #[DataProvider('invalidCore')]
    public function test_direct_action_rejects_invalid_core_fields_atomically(string $field, mixed $value): void
    {
        [$member, $unit] = $this->fixture();
        try {
            $this->trusted($member, fn () => app(CreateApproach::class)->execute($member->user, $this->data($unit, [$field => $value])));
            $this->fail('Invalid direct Action input must fail.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey($field, $error->errors());
            $this->assertDatabaseCount('approaches', 0);
        }
    }

    public static function forbiddenFields(): array
    {
        return array_map(fn ($key) => [$key], ['organization_id', 'owning_unit_id', 'created_by', 'public_id', 'status', 'approved_at']);
    }

    #[DataProvider('forbiddenFields')]
    public function test_payload_cannot_supply_authority_identity_or_approval(string $field): void
    {
        [$member, $unit] = $this->fixture();
        $this->expectException(ValidationException::class);
        $this->trusted($member, fn () => app(CreateApproach::class)->execute($member->user, $this->data($unit, [$field => 1])));
    }

    public function test_foreign_missing_and_malformed_owners_have_the_same_denial(): void
    {
        [$member, $unit] = $this->fixture();
        [, $foreign] = $this->fixture();
        $messages = [];
        foreach ([$foreign->public_id, (string) Str::ulid(), (string) $unit->id, '../owner'] as $id) {
            try {
                $this->trusted($member, fn () => app(CreateApproach::class)->execute($member->user, $this->data($unit, ['owning_unit_public_id' => $id])));
                $this->fail('Invalid owner must fail.');
            } catch (ModelNotFoundException $error) {
                $messages[] = $error->getMessage();
            }
        }
        $this->assertCount(1, array_unique($messages));
        $this->assertDatabaseCount('approaches', 0);
    }

    public function test_duplicate_names_are_allowed_in_same_and_different_organizations(): void
    {
        [$a, $unitA] = $this->fixture();
        [$b, $unitB] = $this->fixture();
        foreach ([[$a, $unitA], [$a, $unitA], [$b, $unitB]] as [$member, $unit]) {
            $this->trusted($member, fn () => app(CreateApproach::class)->execute($member->user, $this->data($unit)));
        }
        $this->assertDatabaseCount('approaches', 3);
    }

    public function test_scoped_listing_search_pagination_and_owner_loading_never_leak_foreign_data(): void
    {
        [$a, $unitA] = $this->fixture();
        [$b, $unitB] = $this->fixture();
        $this->trusted($b, fn () => app(CreateApproach::class)->execute($b->user, $this->data($unitB, ['name' => 'foreign secret'])));
        foreach (['literal%_!', 'second', 'third'] as $name) {
            $this->trusted($a, fn () => app(CreateApproach::class)->execute($a->user, $this->data($unitA, ['name' => $name])));
        }
        $this->trusted($a, function () use ($a, $unitB): void {
            $service = app(ListApproaches::class);
            $page = $service->execute($a->user, perPage: 2);
            $this->assertSame(3, $page->total());
            $this->assertCount(2, $page->items());
            $this->assertCount(1, $service->execute($a->user, page: 2, perPage: 2)->items());
            $this->assertSame(0, $service->execute($a->user, search: 'foreign')->total());
            $this->assertSame(1, $service->execute($a->user, search: '%_!')->total());
            $asc = $service->execute($a->user, sort: 'name', direction: 'asc');
            $this->assertSame(['literal%_!', 'second', 'third'], array_column($asc->items(), 'name'));
            $this->assertStringNotContainsString($unitB->public_id, json_encode($page->items()));
            DB::enableQueryLog();
            $service->execute($a->user);
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $ownerQueries = array_filter($queries, fn ($q) => str_contains($q['query'], 'from "organizational_units"'));
            $this->assertCount(1, $ownerQueries);
            $this->assertLessThan(12, count($queries));
        });
    }

    public function test_invalid_search_sort_and_pagination_are_rejected(): void
    {
        [$member] = $this->fixture();
        foreach ([['sort' => 'organization_id'], ['direction' => 'drop table'], ['page' => 0], ['perPage' => 101]] as $args) {
            try {
                $this->trusted($member, fn () => app(ListApproaches::class)->execute($member->user, ...$args));
                $this->fail('Invalid pagination must fail.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('search', $error->errors());
            }
        }
    }

    public function test_foreign_missing_numeric_and_malformed_approach_ids_are_indistinguishable(): void
    {
        [$a] = $this->fixture();
        [$b, $unitB] = $this->fixture();
        $foreign = $this->trusted($b, fn () => app(CreateApproach::class)->execute($b->user, $this->data($unitB)));
        $messages = [];
        foreach ([$foreign->public_id, (string) Str::ulid(), (string) $foreign->id, '../approach'] as $id) {
            foreach ([fn () => app(ListApproaches::class)->show($a->user, $id), fn () => app(ListApproaches::class)->draftHistory($a->user, $id), fn () => app(SaveApproachContentRevision::class)->execute($a->user, $id, 'نص')] as $operation) {
                try {
                    $this->trusted($a, $operation);
                    $this->fail('Invalid resource must fail.');
                } catch (ModelNotFoundException $error) {
                    $messages[] = $error->getMessage();
                }
            }
        }
        $this->assertCount(1, array_unique($messages));
        $this->assertDatabaseCount('approach_content_revisions', 0);
    }

    public function test_absent_context_wrong_actor_and_revoked_live_membership_deny_internal_operations(): void
    {
        [$a, $unit] = $this->fixture();
        [$b] = $this->fixture();
        foreach ([fn () => app(CreateApproach::class)->execute($a->user, $this->data($unit)),
            fn () => $this->trusted($a, fn () => app(CreateApproach::class)->execute($b->user, $this->data($unit))),
            fn () => $this->trusted($a, function () use ($a, $unit) {
                $a->delete();

                return app(CreateApproach::class)->execute($a->user, $this->data($unit));
            })] as $operation) {
            try {
                $operation();
                $this->fail('Invalid trusted context must deny.');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('approaches', 0);
            }
        }
        $this->assertNull(app(TenantContext::class)->organizationId());
    }

    public function test_platform_principal_cannot_enter_an_institutional_context(): void
    {
        [$member] = $this->fixture();
        $platform = User::factory()->make(['email' => 'platform@example.test']);
        // Explicit synthetic principal; no actual account provisioning or grants.
        $platform->principal_type = 'platform';
        $platform->save();
        $this->assertFalse($platform->canAccessPanel(Panel::make()->id('admin')));
        $this->expectException(AuthorizationException::class);
        app(TenantContext::class)->run($platform, $member->organization_id, fn () => app(ListApproaches::class)->execute($platform));
    }

    public function test_content_is_flexible_append_only_and_identical_save_is_a_noop(): void
    {
        [$member, $unit] = $this->fixture();
        $approach = $this->trusted($member, fn () => app(CreateApproach::class)->execute($member->user, $this->data($unit)));
        $this->trusted($member, function () use ($member, $approach): void {
            $service = app(SaveApproachContentRevision::class);
            $first = $service->execute($member->user, $approach->public_id, "  نص مرن\nبلا قالب  ");
            $original = $first->fresh()->getAttributes();
            $same = $service->execute($member->user, $approach->public_id, $first->body);
            $second = $service->execute($member->user, $approach->public_id, '<script>plain text</script>');
            $this->assertSame($first->id, $same->id);
            $this->assertSame(1, $first->version_number);
            $this->assertSame(2, $second->version_number);
            $this->assertSame($original, $first->fresh()->getAttributes());
            $this->assertSame($member->user_id, $second->actor_id);
            $this->assertNotNull($first->created_at);
            $this->assertDatabaseCount('approach_content_revisions', 2);
            $this->assertSame($approach->public_id, $approach->fresh()->public_id);
            $this->assertSame([1, 2], $approach->revisions()->orderBy('version_number')->pluck('version_number')->all());
            $history = app(ListApproaches::class)->draftHistory($member->user, $approach->public_id, perPage: 1);
            $this->assertSame(2, $history->total());
            $this->assertSame($second->body, $history->items()[0]['body']);
            $this->assertSame($first->body, app(ListApproaches::class)->draftHistory($member->user, $approach->public_id, page: 2, perPage: 1)->items()[0]['body']);
            foreach (['id', 'actor_id', 'organization_id', 'approach_id'] as $key) {
                $this->assertArrayNotHasKey($key, $history->items()[0]);
            }
            foreach (['status', 'approved_at', 'published_at', 'score'] as $key) {
                $this->assertNull($approach->fresh()->getAttribute($key));
                $this->assertNull($second->getAttribute($key));
            }
        });
    }

    public function test_forged_model_attributes_do_not_change_persisted_scope_or_revision_identity(): void
    {
        [$member, $unit] = $this->fixture();
        [$foreign, $foreignUnit] = $this->fixture();
        $approach = $this->trusted($member, fn () => app(CreateApproach::class)->execute($member->user, $this->data($unit)));
        $approach->organization_id = $foreign->organization_id;
        $approach->owning_unit_id = $foreignUnit->id;
        $revision = $this->trusted($member, fn () => app(SaveApproachContentRevision::class)->execute($member->user, $approach->public_id, 'المحتوى'));
        $this->assertSame($member->organization_id, $revision->organization_id);
        $this->assertSame($unit->id, $approach->fresh()->owning_unit_id);
    }

    public function test_historical_revision_and_identity_models_reject_updates_and_deletion(): void
    {
        [$member, $unit] = $this->fixture();
        $approach = $this->trusted($member, fn () => app(CreateApproach::class)->execute($member->user, $this->data($unit)));
        $revision = $this->trusted($member, fn () => app(SaveApproachContentRevision::class)->execute($member->user, $approach->public_id, 'قديم'));
        foreach ([fn () => $revision->forceFill(['body' => 'overwrite'])->save(), fn () => $revision->delete(),
            fn () => $approach->forceFill(['public_id' => (string) Str::ulid()])->save(), fn () => $approach->delete()] as $operation) {
            try {
                $operation();
                $this->fail('History mutation must fail.');
            } catch (LogicException) {
                $this->assertSame('قديم', $revision->fresh()->body);
            }
        }
    }

    public function test_mass_assignment_cannot_bind_tenant_owner_or_revision_author(): void
    {
        foreach ([new Approach, new ApproachContentRevision, new ApproachChange] as $model) {
            try {
                $model->fill(['organization_id' => 1, 'actor_id' => 1, 'owning_unit_id' => 1]);
                $this->assertSame([], $model->getAttributes());
            } catch (MassAssignmentException) {
                $this->assertSame([], $model->getAttributes());
            }
        }
    }

    public function test_metadata_and_owner_changes_without_edit_proof_deny_and_preserve_history(): void
    {
        [$member, $unit] = $this->fixture();
        $approach = $this->trusted($member, fn () => app(CreateApproach::class)->execute($member->user, $this->data($unit)));
        foreach ([fn () => app(UpdateApproach::class)->execute($member->user, $approach->public_id, ['name' => 'changed']),
            fn () => app(ChangeApproachOwner::class)->execute($member->user, $approach->public_id, $unit->public_id)] as $operation) {
            try {
                $this->trusted($member, $operation);
                $this->fail('Changes without an edit proof must deny.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('edit_token', $error->errors());
                $this->assertSame('منهجية اختبار', $approach->fresh()->name);
                $this->assertSame($unit->id, $approach->fresh()->owning_unit_id);
                $this->assertDatabaseCount('approach_content_revisions', 0);
            }
        }
    }

    public function test_post_insert_revision_failure_rolls_back_and_reuses_only_the_uncommitted_number(): void
    {
        [$member, $unit] = $this->fixture();
        $approach = $this->trusted($member, fn () => app(CreateApproach::class)->execute($member->user, $this->data($unit)));
        $armed = true;
        Event::listen('eloquent.created: '.ApproachContentRevision::class, function () use (&$armed): void {
            if ($armed) {
                throw new RuntimeException('Synthetic failure after INSERT.');
            }
        });
        try {
            $this->trusted($member, fn () => app(SaveApproachContentRevision::class)->execute($member->user, $approach->public_id, 'نص'));
            $this->fail('Inserted revision must roll back on failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic failure after INSERT.', $error->getMessage());
            $this->assertDatabaseCount('approach_content_revisions', 0);
        } finally {
            $armed = false;
        }
        $revision = $this->trusted($member, fn () => app(SaveApproachContentRevision::class)->execute($member->user, $approach->public_id, 'نص'));
        $this->assertSame(1, $revision->version_number);
    }

    public function test_outer_rollback_removes_both_identity_and_revision(): void
    {
        [$member, $unit] = $this->fixture();
        DB::beginTransaction();
        try {
            $approach = $this->trusted($member, fn () => app(CreateApproach::class)->execute($member->user, $this->data($unit)));
            $this->trusted($member, fn () => app(SaveApproachContentRevision::class)->execute($member->user, $approach->public_id, 'نص'));
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseCount('approaches', 0);
        $this->assertDatabaseCount('approach_content_revisions', 0);
    }

    public function test_invalid_content_does_not_create_a_revision(): void
    {
        [$member, $unit] = $this->fixture();
        $approach = $this->trusted($member, fn () => app(CreateApproach::class)->execute($member->user, $this->data($unit)));
        foreach (['', "\u{2003}", "\xFF", str_repeat('a', 1000001)] as $body) {
            try {
                $this->trusted($member, fn () => app(SaveApproachContentRevision::class)->execute($member->user, $approach->public_id, $body));
                $this->fail('Invalid content must fail.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('approach_content_revisions', 0);
            }
        }
    }

    public function test_no_http_surface_or_capability_is_enabled_by_membership_or_role_name(): void
    {
        [$member] = $this->fixture();
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
            $this->assertStringNotContainsString('approaches', $route->uri());
        }
        $this->trusted($member, function () use ($member): void {
            $role = Role::create(['organization_id' => $member->organization_id, 'name' => 'Synthetic support fixture', 'guard_name' => 'web']);
            $member->user->assignRole($role);
            $this->assertTrue($member->user->hasRole($role));
        });
        foreach (['/institution/approaches', '/institution/'.$member->organization->public_id.'/approaches', '/admin/approaches'] as $uri) {
            $this->actingAs($member->user)->get($uri)->assertNotFound();
            $this->post($uri, ['organization_id' => $member->organization_id])->assertNotFound();
        }
        $this->assertSame([], config('authorization.capabilities'));
        $this->assertFalse(Gate::forUser($member->user)->allows('approach-create', $member->organization));
        $this->assertDatabaseCount('approaches', 0);
    }

    public function test_institutional_principal_uses_its_own_trusted_binding_without_new_privileges(): void
    {
        [$member, $unit] = $this->fixture();
        $account = User::factory()->create(['principal_type' => 'institution', 'organization_id' => $member->organization_id]);
        $membership = OrganizationMembership::factory()->create(['user_id' => $account->id, 'organization_id' => $member->organization_id]);
        $created = $this->trusted($membership, fn () => app(CreateApproach::class)->execute($account, $this->data($unit)));
        $this->assertSame($account->id, $created->created_by);
        $this->trusted($membership, function () use ($account, $created): void {
            app(SaveApproachContentRevision::class)->execute($account, $created->public_id, 'نص مؤسسي');
            $history = app(ListApproaches::class)->draftHistory($account, $created->public_id);
            $this->assertSame($account->public_id, $history->items()[0]['author_public_id']);
        });
        $this->assertFalse($account->canAccessPanel(Panel::make()->id('admin')));
        $this->assertDatabaseCount('model_has_roles', 0);
    }

    public function test_unregistered_arabic_templates_consume_real_read_contract_and_escape_content(): void
    {
        [$member, $unit] = $this->fixture();
        $this->trusted($member, function () use ($member, $unit): void {
            $service = app(ListApproaches::class);
            $empty = view('institution.approaches.index', ['approaches' => $service->execute($member->user)])->render();
            $this->assertStringContainsString('لا توجد منهجيات', $empty);
            $approach = app(CreateApproach::class)->execute($member->user, $this->data($unit, ['name' => '<script>alert(1)</script>']));
            $revision = app(SaveApproachContentRevision::class)->execute($member->user, $approach->public_id, '<script>draft</script>');
            $views = [
                view('institution.approaches.index', ['approaches' => $service->execute($member->user), 'error' => '<script>error</script>'])->render(),
                view('institution.approaches.show', ['approach' => $service->show($member->user, $approach->public_id)])->render(),
                view('institution.approaches.content', ['revisions' => $service->draftHistory($member->user, $approach->public_id)])->render(),
            ];
            foreach ($views as $html) {
                $this->assertStringContainsString('lang="ar" dir="rtl"', $html);
                $this->assertStringContainsString('غير مفعّل', $html);
                $this->assertStringNotContainsString('<script>', $html);
                $this->assertStringNotContainsString('<form', $html);
            }
            $this->assertStringContainsString('&lt;script&gt;draft&lt;/script&gt;', $views[2]);
            $this->assertStringContainsString('لم يُحفظ محتوى', view('institution.approaches.content', ['revisions' => []])->render());
        });
    }

    public function test_populated_reverse_migration_preserves_both_tables_even_without_content(): void
    {
        [$member, $unit] = $this->fixture();
        $approach = $this->trusted($member, fn () => app(CreateApproach::class)->execute($member->user, $this->data($unit)));
        foreach (glob(database_path('migrations/2026_11_07_*.php')) as $path) {
            try {
                (require $path)->down();
                $this->fail('Even identity-only populated rollback must refuse.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('preserved', $error->getMessage());
                $this->assertTrue(Schema::hasTable('approach_content_revisions'));
                $this->assertSame($approach->public_id, $approach->fresh()->public_id);
            }
        }
    }

    public function test_sqlite_index_failure_rolls_back_the_new_table_and_preserves_preexisting_objects(): void
    {
        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $approaches = require database_path('migrations/2026_11_07_000001_create_approaches_table.php');
        $revisions = require database_path('migrations/2026_11_07_000002_create_approach_content_revisions_table.php');
        $revisions->down();
        $approaches->down();
        // This test owns a deliberately colliding index in the disposable schema.
        DB::statement('CREATE INDEX approaches_organization_created_idx ON organizations (name)');
        try {
            try {
                $approaches->up();
                $this->fail('Index failure must roll back the preceding CREATE TABLE.');
            } catch (QueryException $error) {
                $this->assertStringContainsString('already exists', $error->getMessage());
                $this->assertFalse(Schema::hasTable('approaches'));
                $this->assertNotNull(collect(Schema::getIndexes('organizations'))->firstWhere('name', 'approaches_organization_created_idx'));
            }
        } finally {
            DB::statement('DROP INDEX approaches_organization_created_idx');
            $approaches->up();
            $revisions->up();
        }
        $this->assertTrue(Schema::hasTable('approach_content_revisions'));
    }

    public function test_audited_metadata_and_owner_change_preserve_content_identity_and_independent_history(): void
    {
        [$member, $unit] = $this->fixture();
        $other = OrganizationalUnit::factory()->for($member->organization)->create();
        $approach = $this->trusted($member, fn () => app(CreateApproach::class)->execute($member->user, $this->data($unit)));
        $this->trusted($member, function () use ($member, $unit, $other, $approach): void {
            $reads = app(ListApproaches::class);
            $revision = app(SaveApproachContentRevision::class)->execute($member->user, $approach->public_id, 'unchanged draft');
            $original = $revision->fresh()->getAttributes();
            $before = $reads->show($member->user, $approach->public_id);
            $changed = app(UpdateApproach::class)->execute($member->user, $approach->public_id,
                ['name' => '<script>new</script>', 'scope' => 'نطاق جديد', 'objectives' => 'هدف', 'review_frequency' => null], $before['edit_token']);
            $this->assertSame('<script>new</script>', $changed['name']);
            $first = ApproachChange::sole();
            $this->assertSame('metadata_updated', $first->event);
            $this->assertSame('منهجية اختبار', $first->before_state['name']);
            $this->assertSame($changed['name'], $first->after_state['name']);
            $this->assertSame($member->user_id, $first->actor_id);
            $this->assertSame($member->organization_id, $first->organization_id);
            $this->assertNotNull($first->created_at);
            $this->assertTrue(Str::isUlid($first->public_id));
            $transferred = app(ChangeApproachOwner::class)->execute($member->user, $approach->public_id, $other->public_id, $changed['edit_token']);
            $second = ApproachChange::latest('id')->firstOrFail();
            $this->assertSame('owner_changed', $second->event);
            $this->assertSame(['public_id' => $unit->public_id, 'name' => $unit->name], $second->before_state);
            $this->assertSame(['public_id' => $other->public_id, 'name' => $other->name], $second->after_state);
            $this->assertSame($unit->id, $second->previous_owner_id);
            $this->assertSame($other->id, $second->new_owner_id);
            $this->assertSame($other->public_id, $transferred['owning_unit']['public_id']);
            $this->assertSame($original, $revision->fresh()->getAttributes());
            $this->assertSame($approach->public_id, $approach->fresh()->public_id);
            $this->assertSame($approach->created_by, $approach->fresh()->created_by);
            $this->assertSame($approach->created_at->toIso8601String(), $transferred['created_at']);
            $this->assertDatabaseCount('approach_content_revisions', 1);
            $this->assertDatabaseCount('model_has_roles', 0);
            $history = $reads->changeHistory($member->user, $approach->public_id, perPage: 1);
            $this->assertSame(2, $history->total());
            $this->assertSame($second->public_id, $history->items()[0]['public_id']);
            foreach (['id', 'organization_id', 'actor_id', 'approach_id', 'previous_owner_id', 'new_owner_id'] as $key) {
                $this->assertArrayNotHasKey($key, $history->items()[0]);
            }
            $this->assertSame($first->public_id, $reads->changeHistory($member->user, $approach->public_id, page: 2, perPage: 1)->items()[0]['public_id']);
            foreach ([$first, $second] as $event) {
                foreach ([fn () => $event->forceFill(['event' => 'approved'])->save(), fn () => $event->delete()] as $operation) {
                    try {
                        $operation();
                        $this->fail('Ordinary audit mutation must fail.');
                    } catch (LogicException) {
                        $this->assertDatabaseCount('approach_changes', 2);
                    }
                }
            }
            foreach ([fn () => $approach->forceFill(['name' => 'bypass'])->save(), fn () => $approach->delete()] as $operation) {
                try {
                    $operation();
                    $this->fail('Ordinary core mutation still must fail.');
                } catch (LogicException) {
                    $this->assertSame('<script>new</script>', $approach->fresh()->name);
                }
            }
        });
    }

    public function test_noop_preserves_proof_time_and_history_and_aba_still_rejects_old_form(): void
    {
        [$member, $unit] = $this->fixture();
        $approach = $this->trusted($member, fn () => app(CreateApproach::class)->execute($member->user, $this->data($unit)));
        $this->trusted($member, function () use ($member, $unit, $approach): void {
            $before = app(ListApproaches::class)->show($member->user, $approach->public_id);
            $attributes = $approach->fresh()->getAttributes();
            $same = app(UpdateApproach::class)->execute($member->user, $approach->public_id, ['name' => $before['name']], $before['edit_token']);
            $sameOwner = app(ChangeApproachOwner::class)->execute($member->user, $approach->public_id, $unit->public_id, $before['edit_token']);
            $this->assertSame($before, $same);
            $this->assertSame($before, $sameOwner);
            $this->assertSame($attributes, $approach->fresh()->getAttributes());
            $this->assertDatabaseCount('approach_changes', 0);
            $changed = app(UpdateApproach::class)->execute($member->user, $approach->public_id, ['name' => 'temporary'], $before['edit_token']);
            $restored = app(UpdateApproach::class)->execute($member->user, $approach->public_id, ['name' => $before['name']], $changed['edit_token']);
            $this->assertNotSame($before['edit_token'], $restored['edit_token']);
            foreach ([fn () => app(UpdateApproach::class)->execute($member->user, $approach->public_id, ['name' => 'stale'], $before['edit_token']),
                fn () => app(ChangeApproachOwner::class)->execute($member->user, $approach->public_id, $unit->public_id, $before['edit_token'])] as $operation) {
                try {
                    $operation();
                    $this->fail('A stale form must not overwrite even when values return to their initial state.');
                } catch (ConflictHttpException $error) {
                    $this->assertSame(409, $error->getStatusCode());
                    $this->assertDatabaseCount('approach_changes', 2);
                    $this->assertSame($before['name'], $approach->fresh()->name);
                }
            }
        });
    }

    public static function auditFaults(): array
    {
        return [['creating', false], ['created', false], ['creating', true], ['created', true]];
    }

    #[DataProvider('auditFaults')]
    public function test_mutation_and_event_roll_back_at_each_audit_insert_failure(string $phase, bool $owner): void
    {
        [$member, $unit] = $this->fixture();
        $other = OrganizationalUnit::factory()->for($member->organization)->create();
        $approach = $this->trusted($member, fn () => app(CreateApproach::class)->execute($member->user, $this->data($unit)));
        $before = $approach->fresh()->getAttributes();
        $token = $this->trusted($member, fn () => app(ListApproaches::class)->show($member->user, $approach->public_id))['edit_token'];
        $armed = true;
        Event::listen('eloquent.'.$phase.': '.ApproachChange::class, function () use (&$armed): void {
            if ($armed) {
                throw new RuntimeException('Synthetic audit failure.');
            }
        });
        try {
            $this->trusted($member, fn () => $owner
                ? app(ChangeApproachOwner::class)->execute($member->user, $approach->public_id, $other->public_id, $token)
                : app(UpdateApproach::class)->execute($member->user, $approach->public_id, ['name' => 'rollback'], $token));
            $this->fail('Audit failure must roll back the preceding qualified UPDATE.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic audit failure.', $error->getMessage());
            $this->assertSame($before, $approach->fresh()->getAttributes());
            $this->assertDatabaseCount('approach_changes', 0);
            $this->assertDatabaseCount('approach_content_revisions', 0);
        } finally {
            $armed = false;
        }
    }

    public function test_patch_rejects_unknown_authority_and_invalid_fields_and_foreign_owner_atomically(): void
    {
        [$member, $unit] = $this->fixture();
        [, $foreign] = $this->fixture();
        $approach = $this->trusted($member, fn () => app(CreateApproach::class)->execute($member->user, $this->data($unit)));
        $this->trusted($member, function () use ($member, $approach, $unit, $foreign): void {
            $token = app(ListApproaches::class)->show($member->user, $approach->public_id)['edit_token'];
            foreach (['organization_id', 'actor_id', 'owning_unit_id', 'owning_unit_public_id', 'public_id', 'created_by', 'approved_at', 'status', 'body', 'permissions'] as $field) {
                try {
                    app(UpdateApproach::class)->execute($member->user, $approach->public_id, [$field => 'forged'], $token);
                    $this->fail('Unknown metadata keys must fail.');
                } catch (ValidationException) {
                    $this->assertDatabaseCount('approach_changes', 0);
                }
            }
            foreach ([['name' => ''], ['name' => str_repeat('a', 256)], ['scope' => []], ['purpose' => "\u{2003}"], ['description' => "\xFF"], ['objectives' => []]] as $patch) {
                try {
                    app(UpdateApproach::class)->execute($member->user, $approach->public_id, $patch, $token);
                    $this->fail('Invalid metadata must fail.');
                } catch (ValidationException) {
                    $this->assertDatabaseCount('approach_changes', 0);
                }
            }
            $messages = [];
            foreach ([$foreign->public_id, (string) Str::ulid(), (string) $unit->id, '../unit'] as $id) {
                try {
                    app(ChangeApproachOwner::class)->execute($member->user, $approach->public_id, $id, $token);
                    $this->fail('Foreign/missing owner must fail.');
                } catch (ModelNotFoundException $error) {
                    $messages[] = $error->getMessage();
                }
            }
            $this->assertCount(1, array_unique($messages));
            $this->assertSame($unit->id, $approach->fresh()->owning_unit_id);
            $this->assertDatabaseCount('approach_changes', 0);
            $options = app(ListApproaches::class)->ownerOptions($member->user);
            $this->assertSame([['public_id' => $unit->public_id, 'name' => $unit->name]], $options->items());
        });
    }

    public function test_audit_history_and_both_writes_revalidate_context_and_live_membership(): void
    {
        [$a, $unit] = $this->fixture();
        [$b, $foreignUnit] = $this->fixture();
        $approach = $this->trusted($a, fn () => app(CreateApproach::class)->execute($a->user, $this->data($unit)));
        $foreign = $this->trusted($b, fn () => app(CreateApproach::class)->execute($b->user, $this->data($foreignUnit)));
        $token = $this->trusted($a, fn () => app(ListApproaches::class)->show($a->user, $approach->public_id))['edit_token'];
        $operations = fn ($actor, $id) => [
            fn () => app(UpdateApproach::class)->execute($actor, $id, ['name' => 'forged'], $token),
            fn () => app(ChangeApproachOwner::class)->execute($actor, $id, $unit->public_id, $token),
            fn () => app(ListApproaches::class)->changeHistory($actor, $id),
        ];
        foreach ($operations($a->user, $approach->public_id) as $operation) {
            try {
                $operation();
                $this->fail('No ambient context must deny.');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('approach_changes', 0);
            }
        }
        $this->trusted($a, function () use ($a, $b, $approach, $foreign, $operations): void {
            foreach ($operations($b->user, $approach->public_id) as $operation) {
                try {
                    $operation();
                    $this->fail('Mismatched actor must deny.');
                } catch (AuthorizationException) {
                    $this->assertDatabaseCount('approach_changes', 0);
                }
            }
            foreach ([$foreign->public_id, (string) Str::ulid(), (string) $approach->id, '../approach'] as $id) {
                foreach ($operations($a->user, $id) as $operation) {
                    try {
                        $operation();
                        $this->fail('Foreign and missing resources must be indistinguishable.');
                    } catch (ModelNotFoundException $error) {
                        $this->assertSame(Approach::class, $error->getModel());
                    }
                }
            }
            $a->delete();
            foreach ($operations($a->user, $approach->public_id) as $operation) {
                try {
                    $operation();
                    $this->fail('Revoked membership must deny.');
                } catch (AuthorizationException) {
                    $this->assertDatabaseCount('approach_changes', 0);
                }
            }
        });
    }

    public function test_all_six_metadata_fields_and_nulls_are_audited_exactly_and_history_is_bounded(): void
    {
        [$member, $unit] = $this->fixture();
        $other = OrganizationalUnit::factory()->for($member->organization)->create(['name' => 'original owner name']);
        $approach = $this->trusted($member, fn () => app(CreateApproach::class)->execute($member->user, $this->data($unit)));
        $this->trusted($member, function () use ($member, $other, $approach): void {
            $reads = app(ListApproaches::class);
            $state = $reads->show($member->user, $approach->public_id);
            $patch = ['name' => ' exact name ', 'description' => "line\n  two", 'purpose' => 'غاية جديدة', 'scope' => 'نطاق موصوف',
                'objectives' => 'هدف', 'review_frequency' => 'وصف دورية بلا سياسة مفروضة'];
            $updated = app(UpdateApproach::class)->execute($member->user, $approach->public_id, $patch, $state['edit_token']);
            $first = ApproachChange::sole();
            $this->assertSame($patch, $first->after_state);
            foreach (UpdateApproach::FIELDS as $field) {
                $this->assertSame($state[$field], $first->before_state[$field]);
            }
            $original = $first->fresh()->getAttributes();
            $updated = app(UpdateApproach::class)->execute($member->user, $approach->public_id, ['objectives' => null, 'review_frequency' => null], $updated['edit_token']);
            $this->assertNull($updated['objectives']);
            $this->assertNull($updated['review_frequency']);
            $updated = app(ChangeApproachOwner::class)->execute($member->user, $approach->public_id, $other->public_id, $updated['edit_token']);
            // Test-only raw rename proves the event uses a historical label, not a live join.
            DB::table('organizational_units')->where('id', $other->id)->update(['name' => 'renamed later']);
            $this->assertSame('original owner name', $reads->changeHistory($member->user, $approach->public_id)->items()[0]['after_state']['name']);
            $this->assertSame($original, $first->fresh()->getAttributes());
            foreach ([['page' => 0], ['page' => 1000001], ['perPage' => 21]] as $args) {
                try {
                    $reads->changeHistory($member->user, $approach->public_id, ...$args);
                    $this->fail('Audit history must stay bounded.');
                } catch (ValidationException) {
                    $this->assertDatabaseCount('approach_changes', 3);
                }
            }
        });
    }

    public function test_sqlite_new_audit_index_failure_is_atomic_and_populated_rollback_preserves_all_history(): void
    {
        $migration = require database_path('migrations/2026_11_08_000001_create_approach_changes_table.php');
        $migration->down();
        DB::statement('CREATE INDEX approach_changes_history_idx ON organizations (name)');
        try {
            try {
                $migration->up();
                $this->fail('SQLite audit index collision must roll back its preceding CREATE.');
            } catch (QueryException) {
                $this->assertFalse(Schema::hasTable('approach_changes'));
                $this->assertNotNull(collect(Schema::getIndexes('organizations'))->firstWhere('name', 'approach_changes_history_idx'));
            }
        } finally {
            DB::statement('DROP INDEX approach_changes_history_idx');
            $migration->up();
        }
        [$member, $unit] = $this->fixture();
        $approach = $this->trusted($member, fn () => app(CreateApproach::class)->execute($member->user, $this->data($unit)));
        try {
            $migration->down();
            $this->fail('Identity-only bank must block audit rollback before first DROP.');
        } catch (RuntimeException) {
            $this->assertTrue(Schema::hasTable('approach_changes'));
            $this->assertTrue(Schema::hasTable('approach_content_revisions'));
            $this->assertSame($approach->public_id, $approach->fresh()->public_id);
        }
    }
}
