<?php

namespace Tests\Integration\MySql;

use App\Actions\Organizations\OrganizationProvisioning;
use App\Models\Organization;
use App\Models\OrganizationalUnit;
use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\MySqlTestCase;

class OrganizationHistoryIntegrityTest extends MySqlTestCase
{
    use DatabaseMigrations;

    private const MIGRATION = '2026_11_03_000008_create_organization_changes_table';

    public function test_unsigned_history_keys_restrictive_fks_and_root_ulid_unique_constraint(): void
    {
        foreach (['id', 'organization_id', 'actor_id'] as $column) {
            $type = DB::selectOne("SELECT COLUMN_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organization_changes' AND COLUMN_NAME = ?", [$column])->type;
            $this->assertSame('bigint unsigned', $type);
        }
        foreach (['organization' => 'organizations', 'actor' => 'users'] as $name => $table) {
            $key = DB::selectOne("SELECT REFERENCED_TABLE_NAME AS target, COLUMN_NAME AS col FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'organization_changes' AND CONSTRAINT_NAME = ?", ['organization_changes_'.$name.'_fk']);
            $this->assertSame($table, $key->target);
            $this->assertSame($name === 'actor' ? 'actor_id' : 'organization_id', $key->col);
            $rule = DB::selectOne('SELECT DELETE_RULE AS deletion, UPDATE_RULE AS updating FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?', ['organization_changes_'.$name.'_fk']);
            $this->assertSame('RESTRICT', $rule->deletion);
            $this->assertSame('RESTRICT', $rule->updating);
        }
        $organization = Organization::factory()->create();
        $this->sqlFailure(fn () => DB::table('organizations')->insert(['name' => 'Duplicate public identity', 'public_id' => $organization->public_id]), 1062);
    }

    public function test_raw_history_fake_references_invalid_events_and_missing_new_name_fail(): void
    {
        $actor = User::factory()->create();
        $organization = Organization::factory()->create();
        $row = ['organization_id' => $organization->id, 'actor_id' => $actor->id,
            'event' => 'created', 'previous_name' => null, 'new_name' => 'Fixture', 'created_at' => now()];
        $this->sqlFailure(fn () => DB::table('organization_changes')->insert(array_replace($row, ['organization_id' => PHP_INT_MAX])), 1452);
        $this->sqlFailure(fn () => DB::table('organization_changes')->insert(array_replace($row, ['actor_id' => PHP_INT_MAX])), 1452);
        $this->sqlFailure(fn () => DB::table('organization_changes')->insert(array_replace($row, ['event' => 'unsupported'])), 3819);
        $this->sqlFailure(fn () => DB::table('organization_changes')->insert(array_replace($row, ['new_name' => null])), 1048);
        $this->assertDatabaseCount('organization_changes', 0);
    }

    public function test_organization_and_acting_identity_cannot_be_cascade_deleted_from_history(): void
    {
        $actor = User::factory()->create();
        $organization = app(OrganizationProvisioning::class)->create($actor, 'Preserved history');
        $this->assertDatabaseCount('organization_memberships', 0);
        $this->sqlFailure(fn () => DB::table('organizations')->where('id', $organization->id)->delete(), 1451);
        $this->sqlFailure(fn () => DB::table('users')->where('id', $actor->id)->delete(), 1451);
        $this->assertDatabaseCount('organization_changes', 1);
        $this->assertSame('Preserved history', $organization->fresh()->name);
        $this->assertNotNull($actor->fresh());
    }

    public function test_additive_rollback_and_repeat_preserve_existing_tenant_data_and_triggers(): void
    {
        $unit = OrganizationalUnit::factory()->create();
        $organizationBefore = $unit->organization->fresh()->getAttributes();
        $unitBefore = $unit->fresh()->getAttributes();
        $settings = DB::selectOne('SELECT @@GLOBAL.log_bin AS binlog, @@GLOBAL.log_bin_trust_function_creators AS trusted');
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/'.self::MIGRATION.'.php', '--step' => DB::table('migrations')->count(), '--force' => true])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('organization_changes'));
        $this->assertSame(18, DB::table('migrations')->count());
        $this->assertSame($organizationBefore, $unit->organization->fresh()->getAttributes());
        $this->assertSame($unitBefore, $unit->fresh()->getAttributes());
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('organization_changes'));
        $this->assertSame(19, DB::table('migrations')->count());
        $this->assertSame($unitBefore, $unit->fresh()->getAttributes());
        $this->assertSame(2, (int) DB::selectOne("SELECT COUNT(*) AS total FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'organizational_units'")->total);
        $this->assertEquals($settings, DB::selectOne('SELECT @@GLOBAL.log_bin AS binlog, @@GLOBAL.log_bin_trust_function_creators AS trusted'));
        $this->assertDatabaseCount('organization_changes', 0);
    }

    public function test_inline_fk_failure_leaves_no_partial_table_and_allows_ordinary_retry(): void
    {
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/'.self::MIGRATION.'.php', '--step' => DB::table('migrations')->count(), '--force' => true])->assertExitCode(0);
        $migration = require database_path('migrations/'.self::MIGRATION.'.php');
        DB::statement('RENAME TABLE organizations TO organization_root_fixture');
        try {
            $this->sqlFailure(fn () => $migration->up(), 1824);
            $this->assertFalse(Schema::hasTable('organization_changes'));
            $this->assertSame(0, DB::table('migrations')->where('migration', self::MIGRATION)->count());
        } finally {
            DB::statement('RENAME TABLE organization_root_fixture TO organizations');
        }
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('organization_changes'));
        $this->assertSame(19, DB::table('migrations')->count());
    }

    public function test_existing_history_table_and_data_are_preserved_when_create_fails(): void
    {
        $actor = User::factory()->create();
        $organization = app(OrganizationProvisioning::class)->create($actor, 'Existing fixture');
        $before = DB::table('organization_changes')->sole();
        $migration = require database_path('migrations/'.self::MIGRATION.'.php');
        $this->sqlFailure(fn () => $migration->up(), 1050);
        $this->assertEquals($before, DB::table('organization_changes')->sole());
        $this->assertSame('Existing fixture', $organization->fresh()->name);
    }

    private function sqlFailure(Closure $operation, int $code): void
    {
        try {
            $operation();
            $this->fail('Expected actual MySQL integrity failure.');
        } catch (QueryException $error) {
            $this->assertSame($code, $error->errorInfo[1]);
        }
    }
}
