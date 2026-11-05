<?php

namespace Tests\Support;

use App\Authorization\RoleManagement;
use App\Authorization\TenantAuthorization;
use App\Authorization\TenantContext;
use App\Models\Organization;
use App\Models\OrganizationalUnit;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Spatie\Permission\Exceptions\RoleAlreadyExists;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

trait TenantAuthorizationScenarios
{
    private User $actor;

    private Organization $a;

    private Organization $b;

    protected function setUp(): void
    {
        parent::setUp();
        config(['authorization.capabilities' => [
            'fixture.unit.read' => OrganizationalUnit::class,
            'fixture.organization.inspect' => Organization::class,
        ]]);
        $this->actor = User::factory()->create();
        $this->a = Organization::factory()->create();
        $this->b = Organization::factory()->create();
        OrganizationMembership::factory()->for($this->actor)->for($this->a)->create();
    }

    public function test_installation_has_no_roles_permissions_or_default_privileges(): void
    {
        $this->assertDatabaseCount('roles', 0);
        $this->assertDatabaseCount('permissions', 0);
        $this->assertDatabaseCount('model_has_roles', 0);
        $this->assertDatabaseCount('authorization_changes', 0);
        $this->assertSame([], (require config_path('authorization.php'))['capabilities']);
        $this->assertFalse(Gate::forUser($this->actor)->allows('fixture.unit.read'));
    }

    public function test_absent_context_and_guest_are_denied(): void
    {
        $unit = OrganizationalUnit::factory()->for($this->a)->create();
        $this->assertNull(app(TenantContext::class)->organizationId());
        $this->assertFalse(app(TenantAuthorization::class)->allows(null, 'fixture.unit.read', $unit));
        $this->assertFalse(app(TenantAuthorization::class)->allows($this->actor, 'fixture.unit.read', $unit));
        $this->denied(fn () => app(TenantContext::class)->run(null, $this->a->id, fn () => $this->fail()));
        $this->assertNull(getPermissionsTeamId());
    }

    public function test_existing_nonmember_and_forged_identifiers_cannot_establish_context(): void
    {
        foreach ([$this->b->id, $this->b->public_id, PHP_INT_MAX, (string) Str::ulid(), (string) $this->a->id] as $identifier) {
            $this->denied(fn () => app(TenantContext::class)->run($this->actor, $identifier, fn () => $this->fail()));
            $this->assertNull(getPermissionsTeamId());
        }
    }

    public function test_member_context_is_verified_but_membership_does_not_grant_capability(): void
    {
        $unit = OrganizationalUnit::factory()->for($this->a)->create();
        $this->inA(function () use ($unit): void {
            $this->assertSame($this->a->id, getPermissionsTeamId());
            $this->assertTrue(app(TenantContext::class)->verifiedFor($this->actor));
            $this->assertFalse(app(TenantAuthorization::class)->allows($this->actor, 'fixture.unit.read', $unit));
            $this->denied(fn () => app(TenantAuthorization::class)->authorize($this->actor, 'fixture.unit.read', $unit));
        });
        $this->assertNull(getPermissionsTeamId());
    }

    public function test_explicit_role_grant_works_only_for_correct_actor_capability_and_resource(): void
    {
        $unit = OrganizationalUnit::factory()->for($this->a)->create();
        $foreign = OrganizationalUnit::factory()->for($this->b)->create();
        $otherActor = User::factory()->create();
        $this->inA(function () use ($unit, $foreign, $otherActor): void {
            $this->grant();
            $auth = app(TenantAuthorization::class);
            $this->assertTrue($auth->allows($this->actor, 'fixture.unit.read', $unit));
            $this->assertTrue(Gate::forUser($this->actor)->allows('tenant-capability', ['fixture.unit.read', $unit]));
            $this->assertFalse($auth->allows($otherActor, 'fixture.unit.read', $unit));
            $this->assertFalse($auth->allows($this->actor, 'fixture.unit.read', $foreign));
            $foreign->organization_id = $this->a->id;
            $this->assertFalse($auth->allows($this->actor, 'fixture.unit.read', $foreign));
            $this->assertFalse($auth->allows($this->actor, 'fixture.unit.read', $this->a));
            $this->assertFalse($auth->allows($this->actor, 'unregistered', $unit));
            $this->assertFalse(Gate::forUser($this->actor)->allows('fixture.unit.read'));
        });
    }

    public function test_context_switches_a_b_a_do_not_reuse_loaded_roles(): void
    {
        OrganizationMembership::factory()->for($this->actor)->for($this->b)->create();
        $unitA = OrganizationalUnit::factory()->for($this->a)->create();
        $unitB = OrganizationalUnit::factory()->for($this->b)->create();
        $this->inA(function (): void {
            $this->grant();
        });
        foreach ([$this->a, $this->b, $this->a] as $tenant) {
            app(TenantContext::class)->run($this->actor, $tenant->public_id, function () use ($tenant, $unitA, $unitB): void {
                $this->assertFalse($this->actor->relationLoaded('roles'));
                $this->actor->load('roles.permissions');
                $this->assertSame($tenant->id === $this->a->id ? 1 : 0, $this->actor->roles->count());
                $this->assertSame($tenant->id === $this->a->id,
                    app(TenantAuthorization::class)->allows($this->actor, 'fixture.unit.read', $unitA));
                $this->assertFalse(app(TenantAuthorization::class)->allows($this->actor, 'fixture.unit.read', $unitB));
            });
            $this->assertFalse($this->actor->relationLoaded('roles'));
            $this->assertNull(getPermissionsTeamId());
        }
    }

    public function test_nested_context_and_exceptions_restore_outer_scope_then_clear_it(): void
    {
        OrganizationMembership::factory()->for($this->actor)->for($this->b)->create();
        $this->inA(function (): void {
            try {
                app(TenantContext::class)->run($this->actor, $this->b->id, function (): void {
                    $this->assertSame($this->b->id, getPermissionsTeamId());
                    throw new RuntimeException('fixture failure');
                });
            } catch (RuntimeException $error) {
                $this->assertSame('fixture failure', $error->getMessage());
            }
            $this->assertSame($this->a->id, getPermissionsTeamId());
            $this->denied(fn () => app(TenantContext::class)->run(null, $this->b->id, fn () => $this->fail()));
            $this->assertSame($this->a->id, getPermissionsTeamId());
        });
        $this->assertNull(getPermissionsTeamId());
    }

    public function test_direct_team_setter_is_unavailable(): void
    {
        $this->expectException(LogicException::class);
        setPermissionsTeamId($this->b->id);
    }

    public function test_package_lifecycle_null_reset_only_removes_context(): void
    {
        $this->inA(function (): void {
            $this->assertTrue(app(TenantContext::class)->verifiedFor($this->actor));
            setPermissionsTeamId(null);
            $this->assertNull(getPermissionsTeamId());
            $this->assertFalse(app(TenantContext::class)->verifiedFor($this->actor));
        });
        $this->assertNull(getPermissionsTeamId());
    }

    public function test_scoped_lifecycle_and_consecutive_explicit_job_identities_start_empty(): void
    {
        $first = app(TenantContext::class);
        $this->inA(fn () => $this->assertSame($this->a->id, getPermissionsTeamId()));
        // Same long-lived registrar, newly resolved scoped context for next operation.
        $registrar = app(PermissionRegistrar::class);
        $this->app->forgetScopedInstances();
        $this->assertNotSame($first, app(TenantContext::class));
        $this->assertSame($registrar, app(PermissionRegistrar::class));
        $this->assertNull($registrar->getPermissionsTeamId());
        $outsider = User::factory()->create();
        $this->denied(fn () => app(TenantContext::class)->run($outsider, $this->a->id, fn () => $this->fail()));
        $this->assertNull(getPermissionsTeamId());
    }

    public function test_scoped_role_crud_same_names_and_permission_catalogue(): void
    {
        OrganizationMembership::factory()->for($this->actor)->for($this->b)->create();
        $roleA = $this->inA(fn () => app(RoleManagement::class)->create('Synthetic role'));
        $roleB = app(TenantContext::class)->run($this->actor, $this->b->id,
            fn () => app(RoleManagement::class)->create('Synthetic role'));
        $this->inA(function () use ($roleA, $roleB): void {
            $manager = app(RoleManagement::class);
            $this->assertSame([$roleA->id], $manager->list()->modelKeys());
            $manager->rename($roleA, 'Renamed fixture');
            $this->assertSame('Synthetic role', $roleB->fresh()->name);
            $this->assertSame('Renamed fixture', $roleA->fresh()->name);
            $manager->syncPermissions($roleA, ['fixture.unit.read']);
            $this->assertSame(['fixture.unit.read'], $roleA->fresh()->permissions->pluck('name')->all());
            $manager->syncPermissions($roleA, []);
            $this->assertCount(0, $roleA->fresh()->permissions);
            $manager->delete($roleA);
            $this->assertNull($roleA->fresh());
            $this->assertNotNull($roleB->fresh());
        });
    }

    public function test_cross_organization_role_edit_permission_change_assign_revoke_delete_are_rejected(): void
    {
        OrganizationMembership::factory()->for($this->actor)->for($this->b)->create();
        $foreign = app(TenantContext::class)->run($this->actor, $this->b->id,
            fn () => app(RoleManagement::class)->create('Foreign fixture'));
        $this->inA(function () use ($foreign): void {
            $manager = app(RoleManagement::class);
            foreach ([
                fn () => $manager->rename($foreign, 'Forged'),
                fn () => $manager->syncPermissions($foreign, ['fixture.unit.read']),
                fn () => $manager->assign($foreign, $this->actor),
                fn () => $manager->revoke($foreign, $this->actor),
                fn () => $manager->delete($foreign),
            ] as $operation) {
                $this->denied($operation);
            }
            $this->assertSame('Foreign fixture', $foreign->fresh()->name);
        });
    }

    public function test_assign_and_revoke_revalidate_target_membership_and_clear_loaded_relations(): void
    {
        $outsider = User::factory()->create();
        $unit = OrganizationalUnit::factory()->for($this->a)->create();
        $this->inA(function () use ($outsider, $unit): void {
            $manager = app(RoleManagement::class);
            $role = $this->grant();
            $this->denied(fn () => $manager->assign($role, $outsider));
            $this->actor->load('roles.permissions');
            $staleCopy = $this->actor->fresh()->load('roles.permissions');
            $manager->revoke($role, $this->actor);
            $this->assertFalse($this->actor->relationLoaded('roles'));
            $this->assertFalse(app(TenantAuthorization::class)->allows($staleCopy, 'fixture.unit.read', $unit));
            $manager->delete($role);
            $this->assertNull($role->fresh());
        });
    }

    public function test_permission_revocation_is_immediate_even_for_stale_user_and_permission_cache(): void
    {
        $unit = OrganizationalUnit::factory()->for($this->a)->create();
        $this->inA(function () use ($unit): void {
            $role = $this->grant();
            $stale = $this->actor->fresh()->load('roles.permissions');
            $this->assertTrue($stale->hasPermissionTo('fixture.unit.read', 'web'));
            app(RoleManagement::class)->syncPermissions($role, []);
            $this->assertFalse(app(TenantAuthorization::class)->allows($stale, 'fixture.unit.read', $unit));
        });
    }

    public function test_membership_revocation_is_rechecked_inside_existing_context(): void
    {
        $this->inA(function (): void {
            $this->actor->load('memberships');
            $this->assertTrue(app(TenantContext::class)->verifiedFor($this->actor));
            OrganizationMembership::query()->where('user_id', $this->actor->id)->where('organization_id', $this->a->id)->delete();
            $this->assertFalse(app(TenantContext::class)->verifiedFor($this->actor));
            $this->denied(fn () => app(RoleManagement::class)->create('After revocation'));
        });
        $this->denied(fn () => $this->inA(fn () => $this->fail()));
    }

    public function test_assigned_roles_cannot_be_deleted_implicitly(): void
    {
        $this->inA(function (): void {
            $role = $this->grant();
            try {
                app(RoleManagement::class)->delete($role);
                $this->fail('Assigned role deletion must fail.');
            } catch (LogicException) {
                $this->assertNotNull($role->fresh());
                $this->assertDatabaseCount('model_has_roles', 1);
            }
        });
    }

    public function test_duplicate_role_names_are_rejected_by_package(): void
    {
        $this->inA(function (): void {
            app(RoleManagement::class)->create('Duplicate fixture');
            $this->expectException(RoleAlreadyExists::class);
            app(RoleManagement::class)->create('Duplicate fixture');
        });
    }

    public function test_invalid_role_names_and_unknown_permissions_do_not_write(): void
    {
        $this->inA(function (): void {
            $manager = app(RoleManagement::class);
            $role = $manager->create('Fixture');
            foreach ([fn () => $manager->create(' '), fn () => $manager->create(str_repeat('x', 126)),
                fn () => $manager->syncPermissions($role, ['invented.power'])] as $operation) {
                try {
                    $operation();
                    $this->fail('Invalid input must fail.');
                } catch (InvalidArgumentException) {
                    $this->assertDatabaseCount('permissions', 0);
                }
            }
            $this->assertDatabaseCount('roles', 1);
            $this->assertDatabaseCount('authorization_changes', 1);
        });
    }

    public function test_internal_management_has_no_context_free_access_or_public_mutation_command(): void
    {
        $this->denied(fn () => app(RoleManagement::class)->create('Unscoped'));
        $this->denied(fn () => app(RoleManagement::class)->list());
        foreach (['permission:create-role', 'permission:create-permission', 'permission:assign-role', 'permission:setup-teams'] as $command) {
            $this->artisan($command)->assertExitCode(1);
        }
        $this->assertDatabaseCount('roles', 0);
        $this->assertDatabaseCount('permissions', 0);
    }

    public function test_global_roles_are_rejected_by_model_boundary(): void
    {
        $this->expectException(LogicException::class);
        Role::create(['name' => 'Forbidden global', 'guard_name' => 'web', 'organization_id' => null]);
    }

    public function test_direct_user_permission_does_not_grant_trusted_application_access(): void
    {
        $unit = OrganizationalUnit::factory()->for($this->a)->create();
        $this->inA(function () use ($unit): void {
            $permission = Permission::create(['name' => 'fixture.unit.read', 'guard_name' => 'web']);
            $this->actor->givePermissionTo($permission);
            $this->assertTrue($this->actor->hasPermissionTo('fixture.unit.read', 'web'));
            $this->assertFalse(app(TenantAuthorization::class)->allows($this->actor, 'fixture.unit.read', $unit));
            $this->assertFalse(Gate::forUser($this->actor)->allows('fixture.unit.read'));
        });
    }

    public function test_real_cache_exception_cannot_restore_a_revoked_database_grant(): void
    {
        $unit = OrganizationalUnit::factory()->for($this->a)->create();
        $this->inA(function () use ($unit): void {
            $role = $this->grant();
            $stale = $this->actor->fresh()->load('roles.permissions');
            app(RoleManagement::class)->syncPermissions($role, []);
            Cache::extend('unavailable', fn () => Cache::repository(new class extends ArrayStore
            {
                public function get($key)
                {
                    throw new RuntimeException('Synthetic cache failure');
                }

                public function forget($key)
                {
                    throw new RuntimeException('Synthetic cache failure');
                }
            }));
            config(['cache.stores.unavailable' => ['driver' => 'unavailable'], 'permission.cache.store' => 'unavailable']);
            app(PermissionRegistrar::class)->initializeCache();
            try {
                Cache::store('unavailable')->get('fixture');
                $this->fail('Cache must actually fail.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Synthetic cache failure', $exception->getMessage());
            }
            $this->assertFalse(app(TenantAuthorization::class)->allows($stale, 'fixture.unit.read', $unit));
            try {
                app(RoleManagement::class)->syncPermissions($role, ['fixture.unit.read']);
                $this->fail('Failed cache mutation must not commit.');
            } catch (RuntimeException) {
                $this->assertDatabaseCount('role_has_permissions', 0);
                $this->assertFalse(app(TenantAuthorization::class)->allows($stale, 'fixture.unit.read', $unit));
            }
        });
    }

    public function test_management_audit_is_transactional_and_contains_ids_not_role_names_or_secrets(): void
    {
        $this->inA(function (): void {
            $role = app(RoleManagement::class)->create('Private display name fixture');
            $entry = DB::table('authorization_changes')->sole();
            $this->assertSame('role.created', $entry->event);
            $this->assertSame($role->id, $entry->role_id);
            $this->assertSame($this->actor->id, $entry->actor_id);
            $this->assertSame($this->a->id, $entry->organization_id);
            $this->assertStringNotContainsString('Private display name fixture', json_encode($entry));
            try {
                DB::transaction(function (): void {
                    app(RoleManagement::class)->create('Rolled back fixture');
                    throw new RuntimeException('rollback');
                });
            } catch (RuntimeException) {
                $this->assertDatabaseCount('roles', 1);
                $this->assertDatabaseCount('authorization_changes', 1);
            }
        });
    }

    private function inA(Closure $operation): mixed
    {
        return app(TenantContext::class)->run($this->actor, $this->a->id, $operation);
    }

    private function grant(): Role
    {
        $manager = app(RoleManagement::class);
        $role = $manager->create('Synthetic fixture');
        $manager->syncPermissions($role, ['fixture.unit.read']);
        $manager->assign($role, $this->actor);

        return $role;
    }

    private function denied(Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Expected authorization denial.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }
}
