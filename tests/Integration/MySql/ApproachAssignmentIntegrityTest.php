<?php

namespace Tests\Integration\MySql;

use App\Models\ApproachAssignmentChange;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\ApproachAssignmentFixtures;
use Tests\Support\MySqlTestCase;

class ApproachAssignmentIntegrityTest extends MySqlTestCase
{
    use ApproachAssignmentFixtures, DatabaseMigrations;

    private const PATHS = [
        'database/migrations/2026_11_10_000001_create_approach_assignments_table.php',
        'database/migrations/2026_11_10_000002_create_approach_assignment_changes_table.php',
    ];

    protected function tearDown(): void
    {
        if ($this->app) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->cleanupAssignmentFixtures();
        }
        parent::tearDown();
    }

    private function sqlFailure(callable $operation, int $code): void
    {
        DB::beginTransaction();
        try {
            $operation();
            $this->fail('Invalid raw data was accepted.');
        } catch (QueryException $error) {
            $this->assertSame($code, $error->errorInfo[1]);
        } finally {
            DB::rollBack();
        }
    }

    public static function auditFailurePositions(): array
    {
        return [[1], [4], [7]];
    }

    #[DataProvider('auditFailurePositions')]
    public function test_mysql_initial_assignment_audit_failure_rolls_back_the_entire_creation(int $position): void
    {
        $f = $this->assignmentFixture();
        $before = DB::table('approach_assignment_changes')->count();
        $seen = 0;
        ApproachAssignmentChange::creating(function (ApproachAssignmentChange $event) use (&$seen, $position): void {
            if ($event->approach_id !== null && ++$seen === $position) {
                throw new RuntimeException('Injected MySQL assignment audit failure.');
            }
        });
        $this->inAssignmentFixture($f, function () use ($f): void {
            try {
                $this->createAssignedApproach($f);
                $this->fail('Partial initial recipient persistence accepted.');
            } catch (RuntimeException $error) {
                $this->assertSame('Injected MySQL assignment audit failure.', $error->getMessage());
            }
        });
        $this->assertDatabaseCount('approaches', 0);
        $this->assertSame(0, DB::table('approach_assignments')->whereNotNull('approach_id')->count());
        $this->assertSame($before, DB::table('approach_assignment_changes')->count());
    }

    public function test_actual_raw_tenant_scope_time_test_classification_and_unique_constraints(): void
    {
        $f = $this->assignmentFixture();
        $foreign = $this->assignmentFixture();
        $this->inAssignmentFixture($f, fn () => $this->createAssignedApproach($f));
        $row = (array) DB::table('approach_assignments')->where('fixture_id', $f['proof']['fixture_id'])->where('operation', 'view')->first();
        unset($row['id'], $row['scope_key']);
        $this->sqlFailure(fn () => DB::table('approach_assignments')->insert(array_replace($row, ['public_id' => (string) Str::ulid()])), 1062);
        foreach ([['classification' => 'OPERATIONAL'], ['scenario' => 'other'], ['scope_kind' => 'institution'],
            ['operation' => 'approve'], ['expires_at' => date('Y-m-d H:i:s', strtotime($row['starts_at'].' UTC') + 90000)],
            ['revoked_at' => $row['starts_at'], 'revoked_by' => null], ['fixture_id' => str_repeat('z', 32)]] as $invalid) {
            $copy = array_replace($row, ['public_id' => (string) Str::ulid(), 'grantee_id' => $f['unassigned']->id], $invalid);
            $this->sqlFailure(fn () => DB::table('approach_assignments')->insert($copy), 3819);
        }
        $copy = array_replace($row, ['public_id' => (string) Str::ulid(), 'grantee_id' => $foreign['contributor']->id]);
        $this->sqlFailure(fn () => DB::table('approach_assignments')->insert($copy), 1452);
        $this->sqlFailure(fn () => DB::table('organizations')->where('id', $f['organization']->id)->delete(), 1451);
    }

    public function test_audit_cannot_reference_another_fixture_or_subject_and_enforces_test_only_evidence(): void
    {
        $f = $this->assignmentFixture();
        $row = (array) DB::table('approach_assignment_changes')->where('fixture_id', $f['proof']['fixture_id'])->first();
        unset($row['id']);
        foreach ([['fixture_id' => bin2hex(random_bytes(16))], ['subject_id' => $f['unassigned']->id]] as $invalid) {
            $copy = array_replace($row, ['public_id' => (string) Str::ulid(), 'event_key' => (string) Str::ulid()], $invalid);
            $this->sqlFailure(fn () => DB::table('approach_assignment_changes')->insert($copy), 1452);
        }
        $copy = array_replace($row, ['public_id' => (string) Str::ulid(), 'event_key' => (string) Str::ulid(), 'classification' => 'OPERATIONAL']);
        $this->sqlFailure(fn () => DB::table('approach_assignment_changes')->insert($copy), 3819);
    }

    public function test_each_atomic_ddl_failure_has_no_partial_table_and_retries_normally_with_measured_journal(): void
    {
        $actual = count(glob(database_path('migrations/*.php')));
        $this->assertSame($actual, DB::table('migrations')->count());
        $this->artisan('migrate:rollback', ['--path' => self::PATHS, '--step' => $actual, '--force' => true])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('approach_assignments'));
        DB::statement('RENAME TABLE approaches TO assignment_resource_parent_fixture');
        try {
            $migration = require base_path(self::PATHS[0]);
            try {
                $migration->up();
                $this->fail('Missing resource parent must fail atomically.');
            } catch (QueryException $error) {
                $this->assertSame(1824, $error->errorInfo[1]);
                $this->assertFalse(Schema::hasTable('approach_assignments'));
                $this->assertSame($actual - 2, DB::table('migrations')->count());
            }
        } finally {
            DB::statement('RENAME TABLE assignment_resource_parent_fixture TO approaches');
            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        }
        $this->artisan('migrate:rollback', ['--path' => [self::PATHS[1]], '--step' => $actual, '--force' => true])->assertExitCode(0);
        DB::statement('RENAME TABLE approach_assignments TO assignment_history_parent_fixture');
        try {
            $migration = require base_path(self::PATHS[1]);
            try {
                $migration->up();
                $this->fail('Missing assignment parent must fail atomically.');
            } catch (QueryException $error) {
                $this->assertSame(1824, $error->errorInfo[1]);
                $this->assertFalse(Schema::hasTable('approach_assignment_changes'));
                $this->assertSame($actual - 1, DB::table('migrations')->count());
            }
        } finally {
            DB::statement('RENAME TABLE assignment_history_parent_fixture TO approach_assignments');
            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        }
        foreach (self::PATHS as $path) {
            $migration = require base_path($path);
            try {
                $migration->up();
                $this->fail('Existing object must not be adopted or dropped.');
            } catch (QueryException $error) {
                $this->assertSame(1050, $error->errorInfo[1]);
            }
        }
        $this->assertSame($actual, DB::table('migrations')->count());
    }

    public function test_populated_first_reverse_step_refuses_before_any_drop_and_preserves_all_grants_and_events(): void
    {
        $f = $this->assignmentFixture();
        $before = DB::table('approach_assignments')->orderBy('id')->get()->toJson();
        $history = DB::table('approach_assignment_changes')->orderBy('id')->get()->toJson();
        foreach (array_reverse(self::PATHS) as $path) {
            try {
                $migration = require base_path($path);
                $migration->down();
                $this->fail('Populated rollback must refuse before any DROP.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('preserved', $error->getMessage());
            }
            $this->assertTrue(Schema::hasTable('approach_assignments'));
            $this->assertTrue(Schema::hasTable('approach_assignment_changes'));
            $this->assertSame($before, DB::table('approach_assignments')->orderBy('id')->get()->toJson());
            $this->assertSame($history, DB::table('approach_assignment_changes')->orderBy('id')->get()->toJson());
        }
    }
}
