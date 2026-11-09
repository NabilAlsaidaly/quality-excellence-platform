<?php

namespace Tests\Integration\MySql;

use App\Authorization\RoleManagement;
use App\Authorization\TenantAuthorization;
use App\Authorization\TenantContext;
use App\Models\Organization;
use App\Models\OrganizationalUnit;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ApproachAssignmentFixtures;
use Tests\Support\MySqlTestCase;

class PermissionIntegrityTest extends MySqlTestCase
{
    use ApproachAssignmentFixtures, DatabaseMigrations;

    public function test_new_identifiers_are_unsigned_bigint_and_composite_scope_is_real(): void
    {
        foreach (['roles' => ['id', 'organization_id'], 'permissions' => ['id'],
            'model_has_roles' => ['model_id', 'role_id', 'organization_id'],
            'model_has_permissions' => ['model_id', 'permission_id', 'organization_id'],
            'authorization_changes' => ['id', 'organization_id', 'actor_id']] as $table => $columns) {
            foreach ($columns as $column) {
                $actual = DB::selectOne('SELECT COLUMN_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
                $this->assertSame('bigint unsigned', $actual->type);
            }
        }
        $columns = DB::select("SELECT COLUMN_NAME AS col, REFERENCED_COLUMN_NAME AS ref FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'assigned_roles_scope_fk' ORDER BY ORDINAL_POSITION");
        $this->assertSame(['organization_id', 'role_id'], array_column($columns, 'col'));
        $this->assertSame(['organization_id', 'id'], array_column($columns, 'ref'));
    }

    public function test_raw_sql_global_role_and_duplicate_scoped_name_are_rejected(): void
    {
        $org = Organization::factory()->create();
        $row = ['organization_id' => $org->id, 'name' => 'Fixture', 'guard_name' => 'web'];
        DB::table('roles')->insert($row);
        $this->sqlFailure(fn () => DB::table('roles')->insert(array_replace($row, ['organization_id' => null])), 1048);
        $this->sqlFailure(fn () => DB::table('roles')->insert($row), 1062);
        $other = Organization::factory()->create();
        DB::table('roles')->insert(array_replace($row, ['organization_id' => $other->id]));
        $this->assertDatabaseCount('roles', 2);
    }

    public function test_raw_cross_organization_assignment_is_rejected_even_for_member_of_both(): void
    {
        $membership = OrganizationMembership::factory()->create();
        $other = OrganizationMembership::factory()->for($membership->user)->create();
        $role = Role::create(['organization_id' => $membership->organization_id, 'name' => 'Fixture', 'guard_name' => 'web']);
        $this->sqlFailure(fn () => DB::table('model_has_roles')->insert([
            'organization_id' => $other->organization_id, 'role_id' => $role->id,
            'model_id' => $membership->user_id, 'model_type' => User::class,
        ]), 1452);
        $this->assertDatabaseCount('model_has_roles', 0);
    }

    public function test_raw_assignment_to_nonmember_is_rejected(): void
    {
        $membership = OrganizationMembership::factory()->create();
        $outsider = User::factory()->create();
        $role = Role::create(['organization_id' => $membership->organization_id, 'name' => 'Fixture', 'guard_name' => 'web']);
        $this->sqlFailure(fn () => DB::table('model_has_roles')->insert([
            'organization_id' => $membership->organization_id, 'role_id' => $role->id,
            'model_id' => $outsider->id, 'model_type' => User::class,
        ]), 1452);
    }

    public function test_existing_assignments_and_permission_links_restrict_deletion(): void
    {
        $membership = OrganizationMembership::factory()->create();
        config(['authorization.capabilities' => ['fixture.read' => Organization::class]]);
        app(TenantContext::class)->run($membership->user, $membership->organization_id, function () use ($membership): void {
            $manager = app(RoleManagement::class);
            $role = $manager->create('Fixture');
            $manager->syncPermissions($role, ['fixture.read']);
            $manager->assign($role, $membership->user);
            foreach ([
                fn () => DB::table('roles')->where('id', $role->id)->delete(),
                fn () => DB::table('organization_memberships')->where('id', $membership->id)->delete(),
                fn () => DB::table('permissions')->delete(),
                fn () => DB::table('organizations')->where('id', $membership->organization_id)->delete(),
            ] as $operation) {
                $this->sqlFailure($operation, 1451);
            }
            $this->assertDatabaseCount('authorization_changes', 3);
            $manager->revoke($role, $membership->user);
            $membership->delete();
            $this->assertFalse(app(TenantAuthorization::class)->allows($membership->user, 'fixture.read', $membership->organization));
            $this->assertDatabaseCount('model_has_roles', 0);
            $this->assertDatabaseCount('authorization_changes', 4);
        });
    }

    public function test_inline_fk_failure_creates_no_partial_table_and_retry_succeeds(): void
    {
        $this->rollbackEmptyAssignmentMigrations();
        // Include the later unit/organization history before the original four.
        $this->artisan('migrate:rollback', ['--step' => 16, '--force' => true])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('role_has_permissions'));
        $this->assertTrue(Schema::hasTable('roles'));
        DB::statement('RENAME TABLE roles TO permission_roles_fixture');
        $migration = require database_path('migrations/2026_11_03_000003_create_permission_role_has_permissions_table.php');
        try {
            $this->sqlFailure(fn () => $migration->up(), 1824);
            $this->assertFalse(Schema::hasTable('role_has_permissions'));
            $this->assertSame(0, DB::table('migrations')->where('migration', '2026_11_03_000003_create_permission_role_has_permissions_table')->count());
        } finally {
            DB::statement('RENAME TABLE permission_roles_fixture TO roles');
        }
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('role_has_permissions'));
        $this->assertSame(26, DB::table('migrations')->count());
        $this->assertSame(2, (int) DB::selectOne("SELECT COUNT(*) AS total FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'organizational_units'")->total);
    }

    public function test_new_migration_existing_table_failure_preserves_data(): void
    {
        $org = Organization::factory()->create();
        $role = Role::create(['organization_id' => $org->id, 'name' => 'Preserved fixture', 'guard_name' => 'web']);
        $migration = require database_path('migrations/2026_11_03_000002_create_permission_roles_table.php');
        $this->sqlFailure(fn () => $migration->up(), 1050);
        $this->assertSame('Preserved fixture', $role->fresh()->name);
    }

    public function test_internal_mutation_locks_actor_membership_against_concurrent_removal(): void
    {
        $membership = OrganizationMembership::factory()->create();
        config(['database.connections.permission_writer' => config('database.connections.mysql')]);
        $writer = DB::connection('permission_writer');
        $writer->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $armed = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed, $writer, $membership): void {
            if ($armed && str_starts_with($sql, 'insert into `roles`')) {
                $armed = false;
                $this->sqlFailure(fn () => $writer->table('organization_memberships')->where('id', $membership->id)->delete(), 1205);
            }
        });
        try {
            app(TenantContext::class)->run($membership->user, $membership->organization_id,
                fn () => app(RoleManagement::class)->create('Concurrent fixture'));
            $this->assertFalse($armed);
            $this->assertNotNull($membership->fresh());
            $this->assertDatabaseCount('authorization_changes', 1);
        } finally {
            $armed = false;
            DB::purge('permission_writer');
        }
    }

    public function test_only_new_tables_rollback_and_repeat_without_touching_existing_tenant_data(): void
    {
        $unit = OrganizationalUnit::factory()->create();
        $settings = DB::selectOne('SELECT @@GLOBAL.log_bin AS binlog, @@GLOBAL.log_bin_trust_function_creators AS trusted');
        $this->rollbackEmptyAssignmentMigrations();
        $this->artisan('migrate:rollback', ['--step' => 18, '--force' => true])->assertExitCode(0);
        foreach (['permissions', 'roles', 'role_has_permissions', 'model_has_roles', 'model_has_permissions', 'authorization_changes'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $this->assertTrue($unit->is($unit->fresh()));
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertTrue($unit->is($unit->fresh()));
        $this->assertEquals($settings, DB::selectOne('SELECT @@GLOBAL.log_bin AS binlog, @@GLOBAL.log_bin_trust_function_creators AS trusted'));
        $this->assertDatabaseCount('roles', 0);
        $this->assertDatabaseCount('permissions', 0);
    }

    private function sqlFailure(Closure $operation, int $error): void
    {
        try {
            $operation();
            $this->fail('Expected an actual MySQL integrity failure.');
        } catch (QueryException $exception) {
            $this->assertSame($error, $exception->errorInfo[1]);
        }
    }
}
