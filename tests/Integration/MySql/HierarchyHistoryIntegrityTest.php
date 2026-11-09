<?php

namespace Tests\Integration\MySql;

use App\Actions\OrganizationalUnits\OrganizationalUnitManagement;
use App\Authorization\TenantContext;
use App\Models\Organization;
use App\Models\OrganizationalUnit;
use App\Models\OrganizationMembership;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\MySqlTestCase;

class HierarchyHistoryIntegrityTest extends MySqlTestCase
{
    use DatabaseMigrations;

    private const MIGRATION = '2026_11_03_000007_create_organizational_unit_changes_table';

    public function test_history_keys_and_three_unit_relationships_have_actual_tenant_constraints(): void
    {
        foreach (['id', 'organization_id', 'actor_id', 'unit_id', 'previous_parent_id', 'new_parent_id'] as $column) {
            $metadata = DB::selectOne("SELECT COLUMN_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organizational_unit_changes' AND COLUMN_NAME = ?", [$column]);
            $this->assertSame('bigint unsigned', $metadata->type);
        }
        foreach (['unit' => 'unit_id', 'previous_parent' => 'previous_parent_id', 'new_parent' => 'new_parent_id'] as $name => $column) {
            $columns = DB::select("SELECT COLUMN_NAME AS col, REFERENCED_COLUMN_NAME AS ref FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'organizational_unit_changes' AND CONSTRAINT_NAME = ? ORDER BY ORDINAL_POSITION", ['unit_changes_'.$name.'_fk']);
            $this->assertSame(['organization_id', $column], array_column($columns, 'col'));
            $this->assertSame(['organization_id', 'id'], array_column($columns, 'ref'));
        }
    }

    public function test_raw_history_cross_tenant_references_and_invalid_events_are_rejected(): void
    {
        $membership = OrganizationMembership::factory()->create();
        $unit = OrganizationalUnit::factory()->for($membership->organization)->create();
        $foreign = OrganizationalUnit::factory()->create();
        $row = ['organization_id' => $membership->organization_id, 'actor_id' => $membership->user_id,
            'unit_id' => $unit->id, 'event' => 'created', 'created_at' => now()];
        foreach ([
            ['organization_id' => $foreign->organization_id],
            ['unit_id' => $foreign->id],
            ['previous_parent_id' => $foreign->id],
            ['new_parent_id' => $foreign->id],
            ['actor_id' => PHP_INT_MAX],
        ] as $override) {
            $this->sqlFailure(fn () => DB::table('organizational_unit_changes')->insert(array_replace($row, $override)), 1452);
        }
        $this->sqlFailure(fn () => DB::table('organizational_unit_changes')->insert(array_replace($row, ['event' => 'invented'])), 3819);
        $this->assertDatabaseCount('organizational_unit_changes', 0);
    }

    public function test_history_restricts_actor_unit_organization_and_old_parent_deletion(): void
    {
        $membership = OrganizationMembership::factory()->create();
        $oldParent = OrganizationalUnit::factory()->for($membership->organization)->create();
        $child = OrganizationalUnit::factory()->childOf($oldParent)->create();
        app(TenantContext::class)->run($membership->user, $membership->organization_id,
            fn () => app(OrganizationalUnitManagement::class)->move($membership->user, $child->public_id, null));
        foreach ([
            fn () => DB::table('users')->where('id', $membership->user_id)->delete(),
            fn () => DB::table('organizations')->where('id', $membership->organization_id)->delete(),
            fn () => DB::table('organizational_units')->where('id', $child->id)->delete(),
            fn () => DB::table('organizational_units')->where('id', $oldParent->id)->delete(),
        ] as $operation) {
            $this->sqlFailure($operation, 1451);
        }
        $foreign = Organization::factory()->create();
        $this->sqlFailure(fn () => DB::table('organizational_unit_changes')->update(['organization_id' => $foreign->id]), 1452);
        $this->assertNull($child->fresh()->parent_id);
        $this->assertSame($oldParent->id, DB::table('organizational_unit_changes')->sole()->previous_parent_id);
    }

    public function test_additive_history_migration_rolls_back_and_repeats_without_altering_baseline_data(): void
    {
        $unit = OrganizationalUnit::factory()->create();
        DB::table('permissions')->insert(['name' => 'fixture.history.regression', 'guard_name' => 'web']);
        $before = $unit->fresh()->getAttributes();
        $settings = DB::selectOne('SELECT @@GLOBAL.log_bin AS binlog, @@GLOBAL.log_bin_trust_function_creators AS trusted');
        $this->rollbackHistoryMigration();
        $this->assertFalse(Schema::hasTable('organizational_unit_changes'));
        $this->assertSame(25, DB::table('migrations')->count());
        $this->assertSame($before, $unit->fresh()->getAttributes());
        $this->assertDatabaseCount('permissions', 1);
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('organizational_unit_changes'));
        $this->assertSame(26, DB::table('migrations')->count());
        $this->assertSame($before, $unit->fresh()->getAttributes());
        $this->assertDatabaseCount('permissions', 1);
        $this->assertSame(2, (int) DB::selectOne("SELECT COUNT(*) AS total FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'organizational_units'")->total);
        $this->assertEquals($settings, DB::selectOne('SELECT @@GLOBAL.log_bin AS binlog, @@GLOBAL.log_bin_trust_function_creators AS trusted'));
    }

    public function test_failed_inline_history_fk_create_leaves_no_partial_table_and_allows_retry(): void
    {
        $this->rollbackHistoryMigration();
        $migration = require database_path('migrations/'.self::MIGRATION.'.php');
        DB::statement('RENAME TABLE organizational_units TO hierarchy_units_fixture');
        try {
            $this->sqlFailure(fn () => $migration->up(), 1824);
            $this->assertFalse(Schema::hasTable('organizational_unit_changes'));
            $this->assertSame(0, DB::table('migrations')->where('migration', self::MIGRATION)->count());
        } finally {
            DB::statement('RENAME TABLE hierarchy_units_fixture TO organizational_units');
        }
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('organizational_unit_changes'));
        $this->assertSame(26, DB::table('migrations')->count());
    }

    public function test_existing_history_table_and_rows_are_never_adopted_or_deleted_on_create_failure(): void
    {
        $membership = OrganizationMembership::factory()->create();
        app(TenantContext::class)->run($membership->user, $membership->organization_id,
            fn () => app(OrganizationalUnitManagement::class)->create($membership->user, 'Preserved fixture'));
        $before = DB::table('organizational_unit_changes')->sole();
        $migration = require database_path('migrations/'.self::MIGRATION.'.php');
        $this->sqlFailure(fn () => $migration->up(), 1050);
        $this->assertEquals($before, DB::table('organizational_unit_changes')->sole());
        $this->assertDatabaseCount('organizational_units', 1);
    }

    private function rollbackHistoryMigration(): void
    {
        // Newer migrations need not be removed to exercise this exact fixture.
        $this->artisan('migrate:rollback', [
            '--path' => 'database/migrations/'.self::MIGRATION.'.php',
            '--step' => DB::table('migrations')->count(), '--force' => true,
        ])->assertExitCode(0);
    }

    private function sqlFailure(Closure $operation, int $code): void
    {
        try {
            $operation();
            $this->fail('Expected actual MySQL constraint failure.');
        } catch (QueryException $error) {
            $this->assertSame($code, $error->errorInfo[1]);
        }
    }
}
