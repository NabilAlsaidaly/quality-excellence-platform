<?php

namespace Tests\Integration\MySql;

use App\Models\Organization;
use App\Models\OrganizationalUnit;
use App\Models\OrganizationMembership;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MySqlTestCase;

class TenantIntegrityTest extends MySqlTestCase
{
    use DatabaseMigrations;

    public function test_migrations_can_rollback_and_repeat_on_disposable_mysql(): void
    {
        $this->artisan('migrate:rollback', ['--force' => true])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('organizations'));
        $this->assertFalse(Schema::hasTable('organizational_units'));
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('organization_memberships'));
        $this->assertSame(6, DB::table('migrations')->count());
    }

    public function test_internal_primary_and_foreign_keys_are_unsigned_bigint(): void
    {
        foreach (['organizations' => ['id'], 'organization_memberships' => ['id', 'user_id', 'organization_id'], 'organizational_units' => ['id', 'organization_id', 'parent_id']] as $table => $columns) {
            foreach ($columns as $column) {
                $metadata = DB::selectOne('SELECT COLUMN_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
                $this->assertSame('bigint unsigned', $metadata->type, "$table.$column");
            }
        }
    }

    public function test_composite_foreign_key_and_candidate_key_exist(): void
    {
        $columns = DB::select("SELECT COLUMN_NAME AS column_name, REFERENCED_COLUMN_NAME AS referenced_column FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'organizational_units' AND CONSTRAINT_NAME = 'units_same_organization_parent_fk' ORDER BY ORDINAL_POSITION");
        $this->assertSame(['organization_id', 'parent_id'], array_column($columns, 'column_name'));
        $this->assertSame(['organization_id', 'id'], array_column($columns, 'referenced_column'));
        $index = collect(Schema::getIndexes('organizational_units'))->firstWhere('name', 'units_organization_id_unique');
        $this->assertTrue($index['unique']);
        $this->assertSame(['organization_id', 'id'], $index['columns']);
    }

    public function test_valid_roots_children_and_multiple_memberships_persist(): void
    {
        $membership = OrganizationMembership::factory()->create();
        $other = OrganizationMembership::factory()->for($membership->user)->create();
        $root = OrganizationalUnit::factory()->for($membership->organization)->create();
        $child = OrganizationalUnit::factory()->childOf($root)->create();
        $this->assertSame(2, $membership->user->memberships()->count());
        $this->assertNotSame($membership->organization_id, $other->organization_id);
        $this->assertSame($root->id, $child->fresh()->parent_id);
        $this->assertNull($root->fresh()->parent_id);
    }

    public static function invalidReferences(): array
    {
        return [
            'membership-user' => ['organization_memberships', 'user_id'],
            'membership-organization' => ['organization_memberships', 'organization_id'],
            'unit-organization' => ['organizational_units', 'organization_id'],
            'unit-parent' => ['organizational_units', 'parent_id'],
        ];
    }

    #[DataProvider('invalidReferences')]
    public function test_orphan_references_are_rejected(string $table, string $column): void
    {
        $model = $table === 'organization_memberships'
            ? OrganizationMembership::factory()->create()
            : OrganizationalUnit::factory()->create();
        $this->assertMysqlError(1452, fn () => DB::table($table)->where('id', $model->id)->update([$column => 999999999]));
    }

    public function test_duplicate_membership_is_rejected_by_mysql(): void
    {
        $membership = OrganizationMembership::factory()->create();
        $this->assertMysqlError(1062, fn () => DB::table('organization_memberships')->insert($membership->only('user_id', 'organization_id')));
    }

    public static function publicTables(): array
    {
        return ['organizations' => ['organizations', Organization::class], 'units' => ['organizational_units', OrganizationalUnit::class]];
    }

    #[DataProvider('publicTables')]
    public function test_duplicate_public_ulid_is_rejected(string $table, string $modelClass): void
    {
        $first = $modelClass::factory()->create();
        $second = $modelClass::factory()->create();
        $this->assertMysqlError(1062, fn () => DB::table($table)->where('id', $second->id)->update(['public_id' => $first->public_id]));
    }

    public function test_cross_organization_parent_is_rejected_on_insert_and_update(): void
    {
        $first = OrganizationalUnit::factory()->create();
        $other = OrganizationalUnit::factory()->create();
        $this->assertMysqlError(1452, fn () => DB::table('organizational_units')->insert([
            'organization_id' => $other->organization_id, 'parent_id' => $first->id,
            'public_id' => (string) Str::ulid(), 'name' => 'Invalid child fixture',
        ]));
        $this->assertMysqlError(1452, fn () => DB::table('organizational_units')->where('id', $other->id)->update(['parent_id' => $first->id]));
        $this->assertNull($other->fresh()->parent_id);
    }

    public function test_parent_tenant_cannot_be_changed_while_children_reference_it(): void
    {
        $parent = OrganizationalUnit::factory()->create();
        OrganizationalUnit::factory()->childOf($parent)->create();
        $other = Organization::factory()->create();
        $this->assertMysqlError(1451, fn () => DB::table('organizational_units')->where('id', $parent->id)->update(['organization_id' => $other->id]));
    }

    public function test_direct_self_parent_is_rejected_by_mysql_for_raw_insert_and_update(): void
    {
        $unit = OrganizationalUnit::factory()->create();
        $this->assertMysqlError(1644, fn () => DB::table('organizational_units')->where('id', $unit->id)->update(['parent_id' => $unit->id]));
        $this->assertMysqlError(1644, fn () => DB::table('organizational_units')->insert([
            'id' => 999999999, 'organization_id' => $unit->organization_id, 'parent_id' => 999999999,
            'public_id' => (string) Str::ulid(), 'name' => 'Invalid self fixture',
        ]));
    }

    public function test_referenced_users_organizations_and_parents_cannot_be_deleted(): void
    {
        $membership = OrganizationMembership::factory()->create();
        $root = OrganizationalUnit::factory()->for($membership->organization)->create();
        OrganizationalUnit::factory()->childOf($root)->create();
        $this->assertMysqlError(1451, fn () => $membership->user->delete());
        $this->assertMysqlError(1451, fn () => $membership->organization->delete());
        $this->assertMysqlError(1451, fn () => $root->delete());
        $unitOnlyOrganization = Organization::factory()->create();
        OrganizationalUnit::factory()->for($unitOnlyOrganization)->create();
        $this->assertMysqlError(1451, fn () => $unitOnlyOrganization->delete());
        $this->assertSame(1, OrganizationMembership::count());
        $this->assertSame(3, OrganizationalUnit::count());
    }

    private function assertMysqlError(int $code, callable $operation): void
    {
        try {
            $operation();
            $this->fail("MySQL must reject this operation with error $code.");
        } catch (QueryException $exception) {
            $this->assertSame($code, $exception->errorInfo[1], $exception->getMessage());
        }
    }
}
