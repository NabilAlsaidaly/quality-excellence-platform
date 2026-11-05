<?php

namespace Tests\Support;

use App\Actions\OrganizationalUnits\OrganizationalUnitManagement;
use App\Actions\Organizations\OrganizationManagement;
use App\Actions\Organizations\OrganizationProvisioning;
use App\Authorization\RoleManagement;
use App\Authorization\TenantAuthorization;
use App\Authorization\TenantContext;
use App\Models\Organization;
use App\Models\OrganizationalUnit;
use App\Models\OrganizationMembership;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use TypeError;

trait OrganizationManagementScenarios
{
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create();
    }

    public static function validOrganizationNames(): array
    {
        return [
            'Arabic' => ['مؤسسة الجودة والتميّز'],
            'Latin' => ['Research Institute'],
            'Unicode' => ['研究 مؤسسة 🌱'],
            'Exact whitespace' => ['  مؤسسة البحث  '],
            'SQL text is data' => ["Example'); DROP TABLE users; --"],
        ];
    }

    #[DataProvider('validOrganizationNames')]
    public function test_internal_create_adds_exactly_one_root_and_history_without_default_related_records(string $name): void
    {
        $organization = $this->provisioning()->create($this->actor, $name);
        $this->assertSame($name, $organization->fresh()->name);
        $this->assertIsInt($organization->id);
        $this->assertTrue(Str::isUlid($organization->public_id));
        $this->assertNotSame((string) $organization->id, $organization->public_id);
        $this->assertNotNull($organization->created_at);
        $this->assertNotNull($organization->updated_at);
        $this->assertSame([], $organization->getRelations());
        $this->assertDatabaseCount('organizations', 1);
        $this->assertDatabaseCount('organization_changes', 1);
        $this->assertDatabaseCount('users', 1);
        foreach (['organization_memberships', 'organizational_units', 'roles', 'permissions',
            'model_has_roles', 'model_has_permissions', 'role_has_permissions',
            'authorization_changes', 'organizational_unit_changes', 'jobs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertNull(app(TenantContext::class)->organizationId());
        $this->assertNull(getPermissionsTeamId());
        // Duplicate names deliberately remain valid independent tenant roots.
        $second = $this->provisioning()->create($this->actor, $name);
        $this->assertNotSame($organization->public_id, $second->public_id);
        $this->assertNotSame($organization->id, $second->id);
        $this->assertDatabaseCount('organizations', 2);
        $this->assertDatabaseCount('organization_changes', 2);
    }

    public static function invalidOrganizationNames(): array
    {
        return [
            'empty' => [''], 'ASCII whitespace' => [" \t\n "],
            'Unicode whitespace' => ["\u{00a0}\u{2003}"], 'invalid UTF8' => ["\xff"],
            'overlong ASCII' => [str_repeat('a', 256)], 'overlong Unicode' => [str_repeat('ج', 256)],
        ];
    }

    #[DataProvider('invalidOrganizationNames')]
    public function test_invalid_names_fail_create_and_rename_without_mutation_or_history(string $name): void
    {
        $organization = Organization::factory()->create();
        $before = $organization->fresh()->getAttributes();
        $this->failure(fn () => $this->provisioning()->create($this->actor, $name), ValidationException::class);
        $this->failure(fn () => $this->provisioning()->rename($this->actor, $organization->public_id, $name), ValidationException::class);
        $this->assertSame($before, $organization->fresh()->getAttributes());
        $this->assertDatabaseCount('organizations', 1);
        $this->assertDatabaseCount('organization_changes', 0);
    }

    public function test_arrays_cannot_supply_names_or_unapproved_organization_attributes(): void
    {
        $organization = Organization::factory()->create();
        $this->failure(fn () => $this->provisioning()->create($this->actor, ['name' => 'Forged', 'status' => 'active']), TypeError::class);
        $this->failure(fn () => $this->provisioning()->rename($this->actor, $organization->public_id, ['name' => 'Forged']), TypeError::class);
        $this->assertDatabaseCount('organizations', 1);
        $this->assertDatabaseCount('organization_changes', 0);
        $this->assertArrayNotHasKey('status', $organization->getAttributes());
        $this->assertArrayNotHasKey('organization_id', $organization->getAttributes());
    }

    public function test_guest_unsaved_forged_and_deleted_actors_are_rejected(): void
    {
        $organization = Organization::factory()->create();
        $forged = new User;
        $forged->id = PHP_INT_MAX;
        $forged->exists = true;
        $deleted = User::factory()->create();
        User::query()->whereKey($deleted->id)->delete();
        foreach ([null, User::factory()->make(), $forged, $deleted] as $actor) {
            $this->failure(fn () => $this->provisioning()->create($actor, 'Uncreated'), AuthorizationException::class);
            $this->failure(fn () => $this->provisioning()->rename($actor, $organization->public_id, 'Unrenamed'), AuthorizationException::class);
        }
        $this->assertDatabaseCount('organizations', 1);
        $this->assertDatabaseCount('organization_changes', 0);
    }

    public function test_provisioning_needs_no_membership_and_grants_no_context_or_content_access(): void
    {
        $organization = $this->provisioning()->create($this->actor, 'New tenant');
        $this->assertDatabaseCount('organization_memberships', 0);
        $this->failure(fn () => app(TenantContext::class)->run($this->actor, $organization->public_id, fn () => $this->fail()), AuthorizationException::class);
        $this->failure(fn () => $this->management()->metadata($this->actor, $organization->public_id), AuthorizationException::class);
        $this->assertFalse(app(TenantAuthorization::class)->allows($this->actor, 'unapproved', $organization));
        $this->assertFalse(Gate::forUser($this->actor)->allows('organization.manage'));
        $this->assertDatabaseCount('roles', 0);
        $this->assertDatabaseCount('permissions', 0);
    }

    public function test_provisioning_inside_existing_context_does_not_switch_or_extend_it(): void
    {
        $existing = $this->memberOrganization();
        $this->within($existing, function () use ($existing): void {
            $new = $this->provisioning()->create($this->actor, 'Independent root');
            $this->assertSame($existing->id, app(TenantContext::class)->organizationId());
            $this->assertSame($existing->id, getPermissionsTeamId());
            $this->assertFalse($new->memberships()->exists());
            $this->failure(fn () => $this->management()->metadata($this->actor, $new->public_id), ModelNotFoundException::class);
        });
        $this->assertNull(getPermissionsTeamId());
    }

    public function test_rename_preserves_identity_related_data_and_existing_histories(): void
    {
        $organization = $this->provisioning()->create($this->actor, str_repeat('ج', 255));
        OrganizationMembership::factory()->for($this->actor)->for($organization)->create();
        config(['authorization.capabilities' => ['fixture.organization.inspect' => Organization::class]]);
        $this->within($organization, function (): void {
            app(OrganizationalUnitManagement::class)->create($this->actor, 'Fixture unit');
            $roles = app(RoleManagement::class);
            $role = $roles->create('Synthetic fixture');
            $roles->syncPermissions($role, ['fixture.organization.inspect']);
            $roles->assign($role, $this->actor);
        });
        $unrelated = Organization::factory()->create();
        $unrelatedBefore = $unrelated->fresh()->getAttributes();
        $before = $organization->fresh()->only(['id', 'public_id', 'created_at']);
        $creation = DB::table('organization_changes')->sole();
        $related = [];
        foreach (['organization_memberships', 'organizational_units', 'roles', 'permissions', 'model_has_roles',
            'model_has_permissions', 'role_has_permissions', 'authorization_changes', 'organizational_unit_changes'] as $table) {
            $related[$table] = DB::table($table)->get()->toJson();
        }
        $this->travel(1)->minutes();
        try {
            $renamed = $this->provisioning()->rename($this->actor, $organization->public_id, 'New Name');
        } finally {
            $this->travelBack();
        }
        $this->assertSame('New Name', $renamed->fresh()->name);
        $this->assertEquals($before, $renamed->only(array_keys($before)));
        $this->assertGreaterThan($organization->updated_at, $renamed->updated_at);
        $this->assertSame([], $renamed->getRelations());
        foreach ($related as $table => $data) {
            $this->assertSame($data, DB::table($table)->get()->toJson());
        }
        $this->assertSame($unrelatedBefore, $unrelated->fresh()->getAttributes());
        $this->assertEquals($creation, DB::table('organization_changes')->orderBy('id')->first());
        $change = DB::table('organization_changes')->where('event', 'renamed')->sole();
        $this->assertSame(str_repeat('ج', 255), $change->previous_name);
        $this->assertSame('New Name', $change->new_name);
        $this->assertSame($organization->id, $change->organization_id);
        $this->assertSame($this->actor->id, $change->actor_id);
    }

    public function test_exact_name_no_op_and_meaningful_whitespace_case_changes_are_distinct(): void
    {
        $organization = $this->provisioning()->create($this->actor, '  Acme  ');
        $before = $organization->fresh()->getAttributes();
        $this->travel(1)->minutes();
        try {
            $this->provisioning()->rename($this->actor, $organization->public_id, '  Acme  ');
            $this->assertSame($before, $organization->fresh()->getAttributes());
            $this->assertDatabaseCount('organization_changes', 1);
            $this->provisioning()->rename($this->actor, $organization->public_id, 'Acme');
            $this->provisioning()->rename($this->actor, $organization->public_id, 'acme');
        } finally {
            $this->travelBack();
        }
        $this->assertSame('acme', $organization->fresh()->name);
        $changes = DB::table('organization_changes')->where('event', 'renamed')->orderBy('id')->get();
        $this->assertSame(['  Acme  ', 'Acme'], $changes->pluck('previous_name')->all());
        $this->assertSame(['Acme', 'acme'], $changes->pluck('new_name')->all());
    }

    public function test_unknown_malformed_and_internal_identifier_cannot_rename_a_root(): void
    {
        $organization = Organization::factory()->create();
        foreach ([(string) Str::ulid(), 'forged', (string) $organization->id] as $id) {
            $this->failure(fn () => $this->provisioning()->rename($this->actor, $id, 'Unrenamed'), ModelNotFoundException::class);
        }
        $this->assertSame($organization->name, $organization->fresh()->name);
        $this->assertDatabaseCount('organization_changes', 0);
    }

    public function test_public_ulid_remains_immutable_after_provisioning(): void
    {
        $organization = $this->provisioning()->create($this->actor, 'Stable identity');
        $original = $organization->public_id;
        $organization->public_id = (string) Str::ulid();
        $this->failure(fn () => $organization->save(), LogicException::class);
        $this->assertSame($original, $organization->fresh()->public_id);
        $this->assertDatabaseCount('organization_changes', 1);
    }

    public function test_audit_failure_rolls_back_create_and_rename_without_orphan_history(): void
    {
        $existing = Organization::factory()->create();
        $before = $existing->fresh()->getAttributes();
        $armed = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed): void {
            if ($armed && str_contains($sql, 'insert into') && str_contains($sql, 'organization_changes')) {
                throw new RuntimeException('Synthetic organization audit failure');
            }
        });
        try {
            $this->failure(fn () => $this->provisioning()->create($this->actor, 'Uncreated'), RuntimeException::class);
            $this->failure(fn () => $this->provisioning()->rename($this->actor, $existing->public_id, 'Unrenamed'), RuntimeException::class);
        } finally {
            $armed = false;
        }
        $this->assertSame($before, $existing->fresh()->getAttributes());
        $this->assertDatabaseCount('organizations', 1);
        $this->assertDatabaseCount('organization_changes', 0);
    }

    public function test_outer_rollback_removes_root_and_all_its_change_history(): void
    {
        try {
            DB::transaction(function (): void {
                $organization = $this->provisioning()->create($this->actor, 'Temporary');
                $this->provisioning()->rename($this->actor, $organization->public_id, 'Temporary rename');
                $this->assertDatabaseCount('organization_changes', 2);
                throw new RuntimeException('Fixture outer rollback');
            });
        } catch (RuntimeException $error) {
            $this->assertSame('Fixture outer rollback', $error->getMessage());
        }
        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('organization_changes', 0);
    }

    public function test_success_audit_has_exact_names_identity_and_no_unrelated_private_fields(): void
    {
        $organization = $this->provisioning()->create($this->actor, '  المؤسسة  ');
        $this->provisioning()->rename($this->actor, $organization->public_id, 'الاسم الجديد');
        $changes = DB::table('organization_changes')->orderBy('id')->get();
        $this->assertSame(['created', 'renamed'], $changes->pluck('event')->all());
        $this->assertSame([null, '  المؤسسة  '], $changes->pluck('previous_name')->all());
        $this->assertSame(['  المؤسسة  ', 'الاسم الجديد'], $changes->pluck('new_name')->all());
        foreach ($changes as $change) {
            $this->assertSame($organization->id, $change->organization_id);
            $this->assertSame($this->actor->id, $change->actor_id);
            $this->assertNotNull($change->created_at);
            $this->assertStringNotContainsString($this->actor->email, json_encode($change));
            $this->assertStringNotContainsString($this->actor->password, json_encode($change));
        }
        $this->assertDatabaseCount('authorization_changes', 0);
        $this->assertDatabaseCount('organizational_unit_changes', 0);
    }

    public function test_member_metadata_is_minimal_and_does_not_query_private_relationships(): void
    {
        $organization = $this->memberOrganization();
        OrganizationalUnit::factory()->for($organization)->create();
        $this->within($organization, function () use ($organization): void {
            DB::connection()->enableQueryLog();
            DB::connection()->flushQueryLog();
            try {
                $metadata = $this->management()->metadata($this->actor, strtoupper($organization->public_id));
                $queries = DB::connection()->getQueryLog();
            } finally {
                DB::connection()->disableQueryLog();
                DB::connection()->flushQueryLog();
            }
            $this->assertSame(['public_id' => $organization->public_id, 'name' => $organization->name], $metadata);
            $this->assertLessThanOrEqual(7, count($queries));
            foreach ($queries as $query) {
                foreach (['organizational_units', 'roles', 'permissions', 'organization_changes', 'authorization_changes',
                    'documents', 'password', 'email'] as $private) {
                    $this->assertStringNotContainsString($private, $query['query']);
                }
            }
        });
    }

    public function test_foreign_and_forged_metadata_identifiers_reveal_no_existence_or_name(): void
    {
        $organization = $this->memberOrganization();
        $foreign = Organization::factory()->create();
        $messages = [];
        $this->within($organization, function () use ($organization, $foreign, &$messages): void {
            DB::connection()->enableQueryLog();
            DB::connection()->flushQueryLog();
            try {
                foreach ([$foreign->public_id, (string) Str::ulid(), 'forged', (string) $organization->id] as $id) {
                    try {
                        $this->management()->metadata($this->actor, $id);
                        $this->fail('Foreign identifier must fail.');
                    } catch (ModelNotFoundException $error) {
                        $messages[] = $error->getMessage();
                        $this->addToAssertionCount(1);
                    }
                }
                $queries = DB::connection()->getQueryLog();
            } finally {
                DB::connection()->disableQueryLog();
                DB::connection()->flushQueryLog();
            }
            foreach ($queries as $query) {
                $this->assertNotContains($foreign->public_id, $query['bindings']);
            }
        });
        $this->assertCount(1, array_unique($messages));
        $this->assertStringNotContainsString($foreign->name, $messages[0]);
    }

    public function test_guest_absent_wrong_actor_and_nonmember_metadata_access_deny(): void
    {
        $organization = $this->memberOrganization();
        $outsider = User::factory()->create();
        $this->failure(fn () => $this->management()->metadata($this->actor, $organization->public_id), AuthorizationException::class);
        $this->within($organization, function () use ($organization, $outsider): void {
            $this->failure(fn () => $this->management()->metadata(null, $organization->public_id), AuthorizationException::class);
            $this->failure(fn () => $this->management()->metadata($outsider, $organization->public_id), AuthorizationException::class);
        });
        $this->failure(fn () => app(TenantContext::class)->run($outsider, $organization->public_id,
            fn () => $this->management()->metadata($outsider, $organization->public_id)), AuthorizationException::class);
    }

    public function test_revocation_ignores_stale_user_membership_relationships(): void
    {
        $organization = $this->memberOrganization();
        $stale = $this->actor->fresh()->load('memberships', 'organizations');
        app(TenantContext::class)->run($stale, $organization->public_id, function () use ($organization, $stale): void {
            $this->assertSame($organization->name, $this->management()->metadata($stale, $organization->public_id)['name']);
            OrganizationMembership::query()->where('organization_id', $organization->id)->where('user_id', $stale->id)->delete();
            $this->failure(fn () => $this->management()->metadata($stale, $organization->public_id), AuthorizationException::class);
        });
        $this->failure(fn () => app(TenantContext::class)->run($stale, $organization->public_id, fn () => $this->fail()), AuthorizationException::class);
    }

    public function test_sequential_a_b_a_metadata_contexts_do_not_leak_or_change_tenant_state(): void
    {
        $a = $this->memberOrganization();
        $b = $this->memberOrganization();
        foreach ([$a, $b, $a] as $organization) {
            $this->within($organization, function () use ($organization): void {
                $this->assertSame(['public_id' => $organization->public_id, 'name' => $organization->name],
                    $this->management()->metadata($this->actor, $organization->public_id));
                $this->assertSame($organization->id, getPermissionsTeamId());
            });
            $this->assertNull(getPermissionsTeamId());
        }
        $this->assertDatabaseCount('organization_changes', 0);
    }

    public function test_no_management_route_permission_or_production_panel_access_is_introduced(): void
    {
        $this->assertSame([], config('authorization.capabilities'));
        $this->assertDatabaseCount('roles', 0);
        $this->assertDatabaseCount('permissions', 0);
        $this->assertFalse(Gate::forUser($this->actor)->allows('organization.manage'));
        $this->get('/admin/organizations')->assertNotFound();
        $this->post('/api/organizations')->assertNotFound();
        $this->get('/api/organizations')->assertNotFound();
        $environment = $this->app->environment();
        $this->app->instance('env', 'production');
        try {
            $this->actingAs($this->actor)->get('/admin')->assertForbidden();
        } finally {
            // Restore the actual test environment before guarded MySQL teardown.
            $this->app->instance('env', $environment);
        }
    }

    private function provisioning(): OrganizationProvisioning
    {
        return app(OrganizationProvisioning::class);
    }

    private function management(): OrganizationManagement
    {
        return app(OrganizationManagement::class);
    }

    private function memberOrganization(): Organization
    {
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->for($this->actor)->for($organization)->create();

        return $organization;
    }

    private function within(Organization $organization, Closure $operation): mixed
    {
        return app(TenantContext::class)->run($this->actor, $organization->public_id, $operation);
    }

    private function failure(Closure $operation, string $class): void
    {
        try {
            $operation();
            $this->fail('Expected failure: '.$class);
        } catch (\Throwable $error) {
            $this->assertInstanceOf($class, $error);
        }
    }
}
