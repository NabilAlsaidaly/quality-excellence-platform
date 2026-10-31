<?php

namespace Tests\Integration\MySql;

use App\Models\Organization;
use App\Models\OrganizationalUnit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\MySqlTestCase;

class TriggerMigrationSafetyTest extends MySqlTestCase
{
    use DatabaseMigrations;

    private const MIGRATION = '2026_10_31_000003_create_organizational_units_table';

    public function test_real_binlog_privilege_failure_cleans_table_and_allows_normal_retry(): void
    {
        $this->removeUnitsMigration();
        $organization = Organization::factory()->create();
        $settings = DB::selectOne('SELECT @@GLOBAL.log_bin AS binlog, @@GLOBAL.log_bin_trust_function_creators AS trusted');
        $this->assertSame(1, (int) $settings->binlog);
        $this->assertSame(0, (int) $settings->trusted);

        // This fixture account is created by the disposable MySQL service.
        // It can create/drop tables but lacks the binlog administrative privilege.
        config(['database.connections.trigger_restricted' => array_replace(config('database.connections.mysql'), [
            'username' => 'quality_platform_ci', 'password' => 'disposable-ci-password',
        ])]);
        $migration = $this->unitsMigration();
        try {
            DB::usingConnection('trigger_restricted', fn () => $migration->up());
            $this->fail('The restricted account must encounter actual MySQL error 1419.');
        } catch (QueryException $exception) {
            $this->assertSame(1419, $exception->errorInfo[1]);
        } finally {
            DB::purge('trigger_restricted');
        }

        $this->assertNoPartialMigration();
        $this->assertTrue($organization->is($organization->fresh()));
        $this->retryAndVerifyIntegrity();
        $this->assertEquals($settings, DB::selectOne('SELECT @@GLOBAL.log_bin AS binlog, @@GLOBAL.log_bin_trust_function_creators AS trusted'));
    }

    public function test_failure_after_first_trigger_removes_that_trigger_and_allows_retry(): void
    {
        $this->removeUnitsMigration();
        $armed = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed): void {
            if ($armed && str_contains($sql, 'CREATE TRIGGER units_no_self_parent_update')) {
                $armed = false;
                $this->assertSame(1, $this->triggerCount());
                throw new RuntimeException('Injected second-trigger failure.');
            }
        });

        try {
            $this->unitsMigration()->up();
            $this->fail('The second-trigger failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected second-trigger failure.', $exception->getMessage());
        }

        $this->assertNoPartialMigration();
        $this->retryAndVerifyIntegrity();
    }

    public function test_existing_table_and_data_are_never_removed_on_create_failure(): void
    {
        $unit = OrganizationalUnit::factory()->create();
        try {
            $this->unitsMigration()->up();
            $this->fail('A pre-existing table must not be adopted or replaced.');
        } catch (QueryException $exception) {
            $this->assertSame(1050, $exception->errorInfo[1]);
        }
        $this->assertTrue($unit->is($unit->fresh()));
        $this->assertSame(2, $this->triggerCount());
    }

    public function test_cleanup_lock_prevents_a_write_between_empty_check_and_drop(): void
    {
        $this->removeUnitsMigration();
        $organization = Organization::factory()->create();
        config(['database.connections.concurrent_writer' => config('database.connections.mysql')]);
        $writer = DB::connection('concurrent_writer');
        $writer->statement('SET SESSION lock_wait_timeout = 1');
        $armed = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed, $writer, $organization): void {
            if (! $armed) {
                return;
            }
            if (str_contains($sql, 'CREATE TRIGGER units_no_self_parent_insert')) {
                throw new RuntimeException('Injected trigger failure before competing write.');
            }
            if (str_starts_with($sql, 'drop table')) {
                $armed = false;
                try {
                    $writer->table('organizational_units')->insert([
                        'organization_id' => $organization->id, 'public_id' => (string) Str::ulid(), 'name' => 'Competing writer fixture',
                    ]);
                    $this->fail('The cleanup lock must block writes after the emptiness check.');
                } catch (QueryException $exception) {
                    $this->assertSame(1205, $exception->errorInfo[1]);
                }
            }
        });

        try {
            try {
                $this->unitsMigration()->up();
                $this->fail('The original trigger failure must propagate after safe cleanup.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Injected trigger failure before competing write.', $exception->getMessage());
            }
            $this->assertNoPartialMigration();
            $this->retryAndVerifyIntegrity();
        } finally {
            $armed = false;
            DB::purge('concurrent_writer');
        }
    }

    public function test_unexpected_rows_are_preserved_and_original_failure_is_retained(): void
    {
        $this->removeUnitsMigration();
        $organization = Organization::factory()->create();
        $publicId = (string) Str::ulid();
        $armed = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed, $organization, $publicId): void {
            if ($armed && str_contains($sql, 'CREATE TRIGGER units_no_self_parent_insert')) {
                $armed = false;
                DB::table('organizational_units')->insert([
                    'organization_id' => $organization->id, 'public_id' => $publicId, 'name' => 'Concurrent writer fixture',
                ]);
                throw new RuntimeException('Injected trigger failure with concurrent row.');
            }
        });

        try {
            try {
                $this->unitsMigration()->up();
                $this->fail('Non-empty table cleanup must fail closed.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('contains rows and was preserved', $exception->getMessage());
                $this->assertSame('Injected trigger failure with concurrent row.', $exception->getPrevious()->getMessage());
            }
            $this->assertSame($publicId, DB::table('organizational_units')->sole()->public_id);
            $this->assertSame(0, DB::table('migrations')->where('migration', self::MIGRATION)->count());
        } finally {
            // This test owns the synthetic row; application recovery never does this.
            Schema::dropIfExists('organizational_units');
        }
    }

    public function test_cleanup_failure_is_explicit_and_preserves_original_cause(): void
    {
        $this->removeUnitsMigration();
        $armed = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed): void {
            if (! $armed) {
                return;
            }
            if (str_contains($sql, 'CREATE TRIGGER units_no_self_parent_insert')) {
                throw new RuntimeException('Injected trigger denial.');
            }
            if (str_starts_with($sql, 'drop table')) {
                throw new RuntimeException('Injected DROP denial.');
            }
        });

        try {
            try {
                $this->unitsMigration()->up();
                $this->fail('Cleanup failure must not be hidden.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Injected DROP denial.', $exception->getMessage());
                $this->assertSame('Injected trigger denial.', $exception->getPrevious()->getMessage());
            }
            $this->assertTrue(Schema::hasTable('organizational_units'));
            $this->assertSame(0, DB::table('migrations')->where('migration', self::MIGRATION)->count());
        } finally {
            $armed = false;
            Schema::dropIfExists('organizational_units');
        }
    }

    private function removeUnitsMigration(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('organizational_units'));
    }

    private function unitsMigration(): Migration
    {
        return require database_path('migrations/'.self::MIGRATION.'.php');
    }

    private function triggerCount(): int
    {
        return (int) DB::selectOne("SELECT COUNT(*) AS count FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'organizational_units'")->count;
    }

    private function assertNoPartialMigration(): void
    {
        $this->assertFalse(Schema::hasTable('organizational_units'));
        $this->assertSame(0, $this->triggerCount());
        $this->assertSame(0, DB::table('migrations')->where('migration', self::MIGRATION)->count());
    }

    private function retryAndVerifyIntegrity(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertSame(1, DB::table('migrations')->where('migration', self::MIGRATION)->count());
        $this->assertSame(2, $this->triggerCount());
        $unit = OrganizationalUnit::factory()->create();
        try {
            DB::table('organizational_units')->where('id', $unit->id)->update(['parent_id' => $unit->id]);
            $this->fail('A successful retry must retain the self-parent integrity guarantee.');
        } catch (QueryException $exception) {
            $this->assertSame(1644, $exception->errorInfo[1]);
        }
    }
}
