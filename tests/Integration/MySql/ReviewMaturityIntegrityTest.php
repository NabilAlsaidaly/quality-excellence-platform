<?php

namespace Tests\Integration\MySql;

use App\Actions\Maturity\ConfigureMaturityStage;
use App\Actions\Maturity\SaveStageSubmission;
use App\Actions\Reviews\DecideReview;
use App\Actions\Reviews\SaveReviewableApproach;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\MySqlTestCase;
use Tests\Support\ReviewMaturityFixtures;
use Tests\Support\TenantIsolationSchemaContract;

class ReviewMaturityIntegrityTest extends MySqlTestCase
{
    use DatabaseMigrations, ReviewMaturityFixtures;

    protected function tearDown(): void
    {
        $this->cleanupReviewMaturity();
        parent::tearDown();
    }

    public const TABLES = [
        'maturity_workflow_stages', 'review_maturity_assignments',
        'maturity_workflow_configurations', 'maturity_workflow_submissions',
        'maturity_workflow_evidence', 'review_maturity_reviews',
        'maturity_workflow_assessments', 'review_maturity_audit',
        'review_maturity_notifications',
    ];

    public function test_actual_mysql_compiles_all_workflow_tables_and_explicit_parent_keys(): void
    {
        $this->assertStringStartsWith('8.4.', DB::selectOne('SELECT VERSION() AS version')->version);
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table.' must exist.');
            $this->assertSame('InnoDB', DB::selectOne('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table])->ENGINE);
        }
        foreach (['approach_content_revisions' => ['approach_revision_workflow_scope', 'approach_id'],
            'document_versions' => ['document_version_workflow_scope', 'document_id']] as $table => [$key, $parent]) {
            $columns = DB::select('SELECT COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX', [$table, $key]);
            $this->assertSame(['organization_id', $parent, 'id'], array_column($columns, 'COLUMN_NAME'));
            $this->assertSame([0, 0, 0], array_column($columns, 'NON_UNIQUE'));
        }
        $this->assertSame(count(glob(database_path('migrations/*.php'))), DB::table('migrations')->count());
    }

    public function test_real_policy_review_and_five_initial_stage_journeys_on_mysql(): void
    {
        // Run the identical public acceptance assertions against actual MySQL, not an alternative SQL-only implementation.
        // Fixtures and cleanup belong to this guarded MySqlTestCase.
        $f = $this->reviewMaturityFixture();
        $first = $this->asWorkflow($f, 'author', fn () => app(SaveReviewableApproach::class)->execute($f['approach']->public_id, 'محتوى فعلي', 'none', (string) Str::ulid()));
        $this->asWorkflow($f, 'reviewer', fn () => app(DecideReview::class)->execute($first->public_id, $first->fingerprint, 'approved', 'مراجعة مستقلة.', (string) Str::ulid()));
        $this->assertSame('approved', $first->fresh()->state);
        foreach (DB::table('maturity_workflow_stages')->where('fixture_id', $f['proof']['fixture_id'])->orderBy('stage_ordinal')->get() as $stage) {
            $q = (string) Str::ulid();
            $e = (string) Str::ulid();
            $data = ['questions' => [['public_id' => $q, 'text' => 'سؤال محفوظ فعليًا', 'instructions' => 'تعليمات محلية', 'answer_required' => true,
                'active' => true, 'expected_evidence' => [['public_id' => $e, 'description' => 'شاهد اصطناعي مطلوب', 'required' => true]]]],
                'scale' => [['name' => 'بداية', 'description' => 'تقدير يدوي تجريبي', 'lower_bound' => 0, 'upper_bound' => 30], ['name' => 'تقدم', 'description' => 'تقدير تجريبي آخر', 'lower_bound' => 31, 'upper_bound' => 100]]];
            $config = $this->asWorkflow($f, 'reviewer', fn () => app(ConfigureMaturityStage::class)->execute($f['approach']->public_id, $stage->public_id, $data, 'none'));
            $version = DB::table('document_versions')->where('organization_id', $f['organization']->id)->first();
            $refs = [['question_public_id' => $q, 'expected_evidence_public_id' => $e, 'document_public_id' => $f['document_public_id'], 'version_public_id' => $version->public_id]];
            $round = $this->asWorkflow($f, 'author', fn () => app(SaveStageSubmission::class)->execute($f['approach']->public_id, $stage->public_id,
                [$q => 'إجابة المرحلة'], $refs, 'none', $config->public_id, (string) Str::ulid()));
            $this->asWorkflow($f, 'reviewer', fn () => app(DecideReview::class)->execute($round->public_id, $round->fingerprint, 'approved', 'الشاهد والإجابة مناسبان ضمن العرض.', (string) Str::ulid(), 0));
            $this->assertSame(1, DB::table('maturity_workflow_assessments')->where('stage_id', $stage->id)->count());
        }
        $this->assertSame(5, DB::table('maturity_workflow_assessments')->where('fixture_id', $f['proof']['fixture_id'])->count());
        $this->assertSame(12, DB::table('review_maturity_notifications')->where('fixture_id', $f['proof']['fixture_id'])->count());
    }

    private function rejectSql(callable $operation, int $code): void
    {
        DB::beginTransaction();
        try {
            $operation();
            $this->fail('Invalid workflow SQL accepted.');
        } catch (QueryException $error) {
            $this->assertSame($code, $error->errorInfo[1]);
        } finally {
            DB::rollBack();
        }
    }

    public function test_assignment_exact_positive_24_hour_boundaries_and_composite_subject_links(): void
    {
        $f = $this->reviewMaturityFixture();
        $row = (array) DB::table('review_maturity_assignments')->where('operation', 'edit_content')->first();
        unset($row['id'], $row['scope_key']);
        foreach ([['2026-11-10 00:00:00', '2026-11-10 00:00:00'], ['2026-11-10 00:00:00', '2026-11-09 00:00:00'],
            ['2026-11-10 00:00:00', '2026-11-10 23:59:59'], ['2026-11-10 00:00:00', '2026-11-11 00:00:01'],
            ['9999-12-31 00:00:00', '9999-12-31 23:59:59']] as [$start, $end]) {
            $copy = array_replace($row, ['public_id' => (string) Str::ulid(), 'fixture_id' => bin2hex(random_bytes(16)), 'starts_at' => $start, 'expires_at' => $end]);
            $this->rejectSql(fn () => DB::table('review_maturity_assignments')->insert($copy), 3819);
        }
        $copy = array_replace($row, ['public_id' => (string) Str::ulid(), 'fixture_id' => bin2hex(random_bytes(16)), 'expires_at' => null]);
        $this->rejectSql(fn () => DB::table('review_maturity_assignments')->insert($copy), 1048);
        $this->rejectSql(fn () => DB::table('review_maturity_assignments')->insert(array_replace($row, ['public_id' => (string) Str::ulid()])), 1062);
        DB::beginTransaction();
        try {
            DB::table('review_maturity_assignments')->insert(array_replace($row, ['public_id' => (string) Str::ulid(), 'fixture_id' => bin2hex(random_bytes(16)),
                'starts_at' => '9999-12-30 23:59:59', 'expires_at' => '9999-12-31 23:59:59']));
            $this->assertSame('9999-12-31 23:59:59', DB::table('review_maturity_assignments')->orderByDesc('id')->value('expires_at'));
        } finally {
            DB::rollBack();
        }
        $other = $this->reviewMaturityFixture();
        $this->rejectSql(fn () => DB::table('review_maturity_assignments')->insert(array_replace($row, ['public_id' => (string) Str::ulid(),
            'grantee_id' => $other['author']->id])), 1452);
        $audit = (array) DB::table('review_maturity_audit')->where('fixture_id', $f['proof']['fixture_id'])->where('operation', 'edit_content')->first();
        unset($audit['id'], $audit['scope_key']);
        foreach (['subject_id' => $f['reviewer']->id, 'operation' => 'review_content'] as $field => $value) {
            $this->rejectSql(fn () => DB::table('review_maturity_audit')->insert(array_replace($audit, ['public_id' => (string) Str::ulid(),
                'event_key' => (string) Str::ulid(), $field => $value])), 1452);
        }
        $this->rejectSql(fn () => DB::table('review_maturity_audit')->insert(array_replace($audit, ['public_id' => (string) Str::ulid(),
            'event_key' => (string) Str::ulid(), 'operation' => null])), 3819);
    }

    public function test_strict_contract_detects_each_new_fk_unique_check_column_and_stored_expression_mutation(): void
    {
        $schema = TenantIsolationSchemaContract::read(DB::connection());
        $this->assertSame([], TenantIsolationSchemaContract::violations($schema));
        foreach (self::TABLES as $table) {
            foreach (['foreign_keys', 'checks', 'indexes'] as $kind) {
                foreach ($schema[$table][$kind] as $name => $value) {
                    if ($kind === 'indexes' && ! $value['unique']) {
                        continue;
                    }
                    $changed = $schema;
                    unset($changed[$table][$kind][$name]);
                    $this->assertNotEmpty(TenantIsolationSchemaContract::violations($changed), $table.' '.$kind.' '.$name);
                    if ($kind === 'checks') {
                        $changed = $schema;
                        $changed[$table]['checks'][$name]['clause'] = '(1=1)';
                        $this->assertNotEmpty(TenantIsolationSchemaContract::violations($changed));
                    }
                }
            }
            foreach ($schema[$table]['columns'] as $name => $value) {
                $changed = $schema;
                $changed[$table]['columns'][$name]['nullable'] = ! $value['nullable'];
                $this->assertNotEmpty(TenantIsolationSchemaContract::violations($changed));
            }
            foreach ($schema[$table]['generated'] ?? [] as $name => $value) {
                $changed = $schema;
                $changed[$table]['generated'][$name]['expression'] = 'NULL';
                $this->assertNotEmpty(TenantIsolationSchemaContract::violations($changed));
            }
        }
    }

    public function test_every_new_down_refuses_root_only_and_grant_only_history_before_first_drop_or_alter(): void
    {
        $f = $this->reviewMaturityFixture();
        $grant = (array) DB::table('review_maturity_assignments')->where('fixture_id', $f['proof']['fixture_id'])->where('operation', 'edit_content')->first();
        unset($grant['scope_key']);
        // Minimal owned schema fixtures, separate from the fully audited application flow.
        DB::table('review_maturity_audit')->where('fixture_id', $f['proof']['fixture_id'])->delete();
        DB::table('review_maturity_assignments')->where('fixture_id', $f['proof']['fixture_id'])->delete();
        DB::table('maturity_workflow_stages')->where('fixture_id', $f['proof']['fixture_id'])->where('stage_ordinal', '>', 1)->delete();
        $before = TenantIsolationSchemaContract::read(DB::connection());
        $journal = DB::table('migrations')->orderBy('id')->get()->toArray();
        foreach (['root_only', 'grant_only'] as $state) {
            if ($state === 'grant_only') {
                DB::table('maturity_workflow_stages')->where('fixture_id', $f['proof']['fixture_id'])->delete();
                DB::table('review_maturity_assignments')->insert($grant);
            }
            foreach (glob(database_path('migrations/2026_11_12_*.php')) as $path) {
                try {
                    (require $path)->down();
                    $this->fail('Populated '.$state.' rollback accepted.');
                } catch (\RuntimeException $error) {
                    $this->assertStringContainsString('preserved', $error->getMessage());
                }
                $this->assertSame($before, TenantIsolationSchemaContract::read(DB::connection()));
                $this->assertEquals($journal, DB::table('migrations')->orderBy('id')->get()->toArray());
            }
        }
    }

    public function test_each_new_atomic_create_or_key_alter_fails_without_partial_shape_and_normally_retries(): void
    {
        $paths = glob(database_path('migrations/2026_11_12_*.php'));
        $this->assertCount(11, $paths);
        foreach ($paths as $index => $path) {
            // Original sources/rows remain intact. Only EMPTY, exactly inventoried workflow DDL is reversed.
            $tail = array_slice($paths, $index);
            $this->artisan('migrate:rollback', ['--path' => array_map(fn ($p) => 'database/migrations/'.basename($p), $tail),
                '--step' => DB::table('migrations')->count(), '--force' => true])->assertExitCode(0);
            $migration = require $path;
            $parent = $index === 0 ? 'approach_content_revisions' : ($index === 1 ? 'document_versions' : 'approaches');
            $temporary = 'rm_atomic_parent_fixture';
            DB::statement('RENAME TABLE '.$parent.' TO '.$temporary);
            try {
                try {
                    $migration->up();
                    $this->fail('Missing parent DDL unexpectedly succeeded.');
                } catch (QueryException $error) {
                    $this->assertSame($index < 2 ? 1146 : 1824, $error->errorInfo[1]);
                }
                if ($index >= 2) {
                    $this->assertFalse(Schema::hasTable(self::TABLES[$index - 2]));
                }
                $this->assertSame(0, DB::table('migrations')->where('migration', basename($path, '.php'))->count());
            } finally {
                DB::statement('RENAME TABLE '.$temporary.' TO '.$parent);
            }
            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
            $this->assertSame(count(glob(database_path('migrations/*.php'))), DB::table('migrations')->count());
            try {
                $migration->up();
                $this->fail('Existing unjournaled shape was adopted.');
            } catch (QueryException $error) {
                $this->assertSame($index < 2 ? 1061 : 1050, $error->errorInfo[1]);
            }
        }
    }

    public function test_unknown_external_reference_and_missing_key_journal_refuse_before_any_reverse_statement(): void
    {
        DB::statement('CREATE TABLE rm_external_fixture (stage_id BIGINT UNSIGNED NOT NULL, CONSTRAINT rm_external_fk FOREIGN KEY(stage_id) REFERENCES maturity_workflow_stages(id) ON DELETE RESTRICT ON UPDATE RESTRICT) ENGINE=InnoDB');
        try {
            $before = TenantIsolationSchemaContract::read(DB::connection());
            foreach (glob(database_path('migrations/2026_11_12_*.php')) as $path) {
                try {
                    (require $path)->down();
                    $this->fail('Unknown external dependency ignored.');
                } catch (\RuntimeException $error) {
                    $this->assertStringContainsString('external workflow dependency', $error->getMessage());
                }
                $this->assertSame($before, TenantIsolationSchemaContract::read(DB::connection()));
            }
        } finally {
            Schema::drop('rm_external_fixture');
        }
        $name = '2026_11_12_000001_add_approach_revision_workflow_candidate_key';
        $journal = (array) DB::table('migrations')->where('migration', $name)->first();
        DB::table('migrations')->where('migration', $name)->delete();
        try {
            try {
                (require database_path('migrations/'.$name.'.php'))->down();
                $this->fail('Unjournaled candidate key adopted for rollback.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('candidate-key journal', $error->getMessage());
            }
            $this->assertTrue(collect(Schema::getIndexes('approach_content_revisions'))->contains('name', 'approach_revision_workflow_scope'));
        } finally {
            DB::table('migrations')->insert($journal);
        }
    }
}
