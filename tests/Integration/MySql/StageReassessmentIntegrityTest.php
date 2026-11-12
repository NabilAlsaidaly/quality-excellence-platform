<?php

namespace Tests\Integration\MySql;

use App\Actions\Maturity\ConfigureMaturityStage;
use App\Actions\Maturity\SaveStageSubmission;
use App\Actions\Reviews\DecideReview;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\MySqlTestCase;
use Tests\Support\ReviewMaturityFixtures;
use Tests\Support\TenantIsolationSchemaContract;

class StageReassessmentIntegrityTest extends MySqlTestCase
{
    use DatabaseMigrations, ReviewMaturityFixtures;

    private const PERSISTENCE_MIGRATIONS = [
        '2026_11_13_000001_add_reassessment_parent_key' => ['maturity_workflow_assessments', null],
        '2026_11_13_000002_create_document_intakes_table' => ['document_versions', 'document_intakes'],
        '2026_11_13_000003_create_document_intake_events_table' => ['document_intakes', 'document_intake_events'],
        '2026_11_13_000004_create_maturity_reassessment_attempts_table' => ['maturity_workflow_assessments', 'maturity_reassessment_attempts'],
        '2026_11_13_000005_create_maturity_reassessment_decisions_table' => ['review_maturity_reviews', 'maturity_reassessment_decisions'],
        '2026_11_13_000006_create_maturity_reassessment_events_table' => ['maturity_reassessment_attempts', 'maturity_reassessment_events'],
    ];

    public function test_mysql_metadata_preserves_ascii_identifiers_and_literal_bytes(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE qep68_ascii_codec_fixture (
 source_ascii VARCHAR(10) NOT NULL,
 CONSTRAINT qep68_codec_identifier CHECK (source_ascii = _ascii'clean'),
 CONSTRAINT qep68_codec_literal CHECK (source_ascii <> _ascii'_ascii')
) ENGINE=InnoDB
SQL);
        try {
            $metadata = TenantIsolationSchemaContract::read(DB::connection());
            $checks = $metadata['qep68_ascii_codec_fixture']['checks'];
            $this->assertSame(['enforced' => true, 'clause' => "(`source_ascii`='clean')"], $checks['qep68_codec_identifier']);
            $this->assertSame(['enforced' => true, 'clause' => "(`source_ascii`<>'_ascii')"], $checks['qep68_codec_literal']);
        } finally {
            Schema::drop('qep68_ascii_codec_fixture');
        }
    }

    public function test_migration_target_requires_explicit_local_opt_in_or_the_existing_repository_ci_job(): void
    {
        $names = ['MYSQL_TESTS_ENABLED', 'GITHUB_ACTIONS', 'CI', 'GITHUB_REPOSITORY', 'GITHUB_RUN_ID'];
        $before = array_combine($names, array_map(getenv(...), $names));
        $guard = require database_path('migrations/2026_11_13_000001_add_reassessment_parent_key.php');
        try {
            foreach ($names as $name) {
                putenv($name);
            }
            try {
                $guard->assertTarget();
                $this->fail('Unowned local migration must refuse.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('Refuse persistence DDL', $error->getMessage());
            }
            foreach (['GITHUB_ACTIONS' => 'true', 'CI' => 'true', 'GITHUB_REPOSITORY' => 'NabilAlsaidaly/quality-excellence-platform', 'GITHUB_RUN_ID' => '123'] as $name => $value) {
                putenv($name.'='.$value);
            }
            $guard->assertTarget();
            $this->assertTrue(true, 'Existing CI preflight needs no CI file/configuration change.');
        } finally {
            foreach ($before as $name => $value) {
                putenv($value === false ? $name : $name.'='.$value);
            }
        }
    }

    public function test_disabled_check_causes_rollback_refusal_before_any_drop(): void
    {
        $this->rollbackEmptyClosureFixtures();
        $table = 'maturity_reassessment_attempts';
        $check = DB::selectOne("SELECT CONSTRAINT_NAME AS name FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_TYPE='CHECK' ORDER BY CONSTRAINT_NAME LIMIT 1", [$table])->name;
        $journal = DB::table('migrations')->orderBy('id')->pluck('migration')->all();
        DB::statement('ALTER TABLE '.$table.' ALTER CHECK '.$check.' NOT ENFORCED');
        try {
            try {
                (require database_path('migrations/2026_11_13_000006_create_maturity_reassessment_events_table.php'))->down();
                $this->fail('Enforcement drift must refuse reverse DDL.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('enforcement drift', $error->getMessage());
            }
            $this->assertTrue(Schema::hasTable('maturity_reassessment_events'));
            $this->assertSame($journal, DB::table('migrations')->orderBy('id')->pluck('migration')->all());
        } finally {
            DB::statement('ALTER TABLE '.$table.' ALTER CHECK '.$check.' ENFORCED');
        }
    }

    public function test_each_additive_migration_refuses_a_missing_parent_without_partial_creation_and_retries_normally(): void
    {
        $names = array_keys(self::PERSISTENCE_MIGRATIONS);
        foreach ($names as $position => $name) {
            $this->rollbackEmptyClosureFixtures();
            $paths = array_map(fn ($migration) => 'database/migrations/'.$migration.'.php', array_slice($names, $position));
            $this->artisan('migrate:rollback', ['--path' => $paths, '--step' => DB::table('migrations')->count(), '--force' => true])->assertExitCode(0);
            [$parent, $target] = self::PERSISTENCE_MIGRATIONS[$name];
            $journal = DB::table('migrations')->orderBy('id')->pluck('migration')->all();
            DB::statement('RENAME TABLE `'.$parent.'` TO `persistence_missing_parent_fixture`');
            try {
                try {
                    (require database_path('migrations/'.$name.'.php'))->up();
                    $this->fail('Missing-parent DDL must fail atomically.');
                } catch (QueryException $error) {
                    $this->assertSame($target === null ? 1146 : 1824, $error->errorInfo[1]);
                }
                if ($target !== null) {
                    $this->assertFalse(Schema::hasTable($target));
                }
                $this->assertSame($journal, DB::table('migrations')->orderBy('id')->pluck('migration')->all());
            } finally {
                DB::statement('RENAME TABLE `persistence_missing_parent_fixture` TO `'.$parent.'`');
                $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
            }
            $this->assertSame(count(glob(database_path('migrations/*.php'))), DB::table('migrations')->count());
            try {
                (require database_path('migrations/'.$name.'.php'))->up();
                $this->fail('An existing object must never be adopted automatically.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('Existing', $error->getMessage());
            }
        }
    }

    public function test_schema_contract_rejects_each_new_table_constraint_mutation(): void
    {
        $contract = TenantIsolationSchemaContract::class;
        $schema = $contract::read(DB::connection());
        $this->assertSame([], $contract::violations($schema));
        foreach (array_filter(array_column(self::PERSISTENCE_MIGRATIONS, 1)) as $table) {
            foreach (['foreign_keys', 'indexes', 'checks', 'generated'] as $kind) {
                foreach ($schema[$table][$kind] ?? [] as $key => $definition) {
                    if ($kind === 'indexes' && ! $definition['unique']) {
                        continue;
                    }
                    $mutated = $schema;
                    unset($mutated[$table][$kind][$key]);
                    $this->assertNotSame([], $contract::violations($mutated), $table.' '.$kind.' '.(string) $key);
                }
            }
            foreach ($schema[$table]['columns'] as $column => $definition) {
                $mutated = $schema;
                $mutated[$table]['columns'][$column]['nullable'] = ! $definition['nullable'];
                $this->assertNotSame([], $contract::violations($mutated), $table.'.'.$column);
            }
        }
    }

    public function test_approved_parent_decision_and_audit_roll_back_as_one_transaction(): void
    {
        $f = $this->reassessmentFixture();
        $attempt = $this->insertAttempt($f);
        $review = $this->copyApprovedReview($f);
        $initial = (array) $f['initial'];
        try {
            DB::transaction(function () use ($f, $attempt, $review): void {
                DB::table('maturity_reassessment_attempts')->where('id', $attempt->id)->lockForUpdate()->first();
                DB::table('maturity_reassessment_attempts')->where('id', $attempt->id)->update([
                    'state' => 'approved', 'revision' => 2, 'approved_submission_id' => $review->submission_id, 'approved_review_id' => $review->id,
                ]);
                DB::table('maturity_reassessment_decisions')->insert($this->decisionPayload($f, $attempt, $review));
                DB::table('maturity_reassessment_events')->insert([
                    'public_id' => (string) Str::ulid(), 'organization_id' => $f['organization']->id,
                    'fixture_id' => $f['proof']['fixture_id'], 'approach_id' => $f['approach']->id,
                    'stage_id' => $f['stage']->id, 'attempt_id' => $attempt->id, 'actor_id' => $f['reviewer']->id,
                    'submission_id' => $review->submission_id, 'request_key' => (string) Str::ulid(),
                    'event' => 'approved', 'details_json' => '{}', 'created_at' => now('UTC'),
                ]);
                throw new \RuntimeException('owned_atomic_rollback_probe');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('owned_atomic_rollback_probe', $error->getMessage());
        }
        $this->assertSame((array) $attempt, (array) DB::table('maturity_reassessment_attempts')->where('id', $attempt->id)->first());
        $this->assertSame(0, DB::table('maturity_reassessment_decisions')->where('attempt_id', $attempt->id)->count());
        $this->assertSame(0, DB::table('maturity_reassessment_events')->where('attempt_id', $attempt->id)->count());
        $this->assertSame($initial, (array) DB::table('maturity_workflow_assessments')->where('id', $f['initial']->id)->first());
    }

    public function test_real_schema_requires_approved_attempt_and_exact_terminal_review_pair(): void
    {
        $this->assertTrue(Schema::hasTable('maturity_reassessment_decisions'), 'The approved v4 decision table is required.');
        $ddl = DB::selectOne('SHOW CREATE TABLE maturity_reassessment_decisions')->{'Create Table'};
        $this->assertStringContainsString('mr_dec_approved_attempt_fk', $ddl);
        $this->assertStringContainsString('mr_dec_attempt_pair_fk', $ddl);
        $this->assertStringContainsString('GENERATED ALWAYS', $ddl);
        $this->assertSame(count(glob(database_path('migrations/*.php'))), DB::table('migrations')->count());
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($this->workflowFixtures as $fixture) {
            foreach (['maturity_reassessment_events', 'maturity_reassessment_decisions', 'maturity_reassessment_attempts'] as $table) {
                if (Schema::hasTable($table)) {
                    foreach (DB::table($table)->where('organization_id', $fixture['organization']->id)
                        ->where('fixture_id', $fixture['proof']['fixture_id'])->orderByDesc('id')->pluck('id') as $id) {
                        DB::table($table)->where('id', $id)->delete();
                    }
                }
            }
        }
        $this->cleanupReviewMaturity();
        parent::tearDown();
    }

    private function reassessmentFixture(): array
    {
        $f = $this->reviewMaturityFixture();
        $stage = DB::table('maturity_workflow_stages')->where('approach_id', $f['approach']->id)->orderBy('stage_ordinal')->first();
        $question = (string) Str::ulid();
        $data = ['questions' => [['public_id' => $question, 'text' => 'سؤال اختبار الاستمرارية', 'instructions' => '',
            'answer_required' => true, 'active' => true, 'expected_evidence' => [
                ['public_id' => (string) Str::ulid(), 'description' => 'شاهد اختياري للاختبار', 'required' => false]]]],
            'scale' => [['name' => 'بداية', 'description' => '', 'lower_bound' => 0, 'upper_bound' => 30],
                ['name' => 'تقدم', 'description' => '', 'lower_bound' => 31, 'upper_bound' => 100]]];
        $configuration = $this->asWorkflow($f, 'reviewer', fn () => app(ConfigureMaturityStage::class)
            ->execute($f['approach']->public_id, $stage->public_id, $data, 'none'));
        $review = $this->asWorkflow($f, 'author', fn () => app(SaveStageSubmission::class)->execute(
            $f['approach']->public_id, $stage->public_id, [$question => 'إجابة محفوظة'], [], 'none',
            $configuration->public_id, (string) Str::ulid()));
        $this->asWorkflow($f, 'reviewer', fn () => app(DecideReview::class)->execute(
            $review->public_id, $review->fingerprint, 'approved', 'قرار أولي مستقل محفوظ.', (string) Str::ulid(), 1));
        $initial = DB::table('maturity_workflow_assessments')->where('stage_id', $stage->id)->first();

        return $f + compact('stage', 'configuration', 'review', 'initial');
    }

    private function insertAttempt(array $f, ?object $previous = null): object
    {
        $id = DB::table('maturity_reassessment_attempts')->insertGetId([
            'public_id' => (string) Str::ulid(), 'organization_id' => $f['organization']->id,
            'fixture_id' => $f['proof']['fixture_id'], 'approach_id' => $f['approach']->id,
            'stage_id' => $f['stage']->id, 'created_at' => now('UTC')->format('Y-m-d H:i:s'),
            'initial_assessment_id' => $f['initial']->id, 'previous_attempt_id' => $previous?->id,
            'attempt_number' => ($previous?->attempt_number ?? 0) + 1, 'author_id' => $f['author']->id,
            'configuration_id' => $f['configuration']->id, 'request_key' => (string) Str::ulid(),
            'request_hash' => hash('sha256', 'attempt-'.Str::ulid()), 'state' => 'open', 'revision' => 1,
        ]);

        return DB::table('maturity_reassessment_attempts')->where('id', $id)->first();
    }

    /** Relational fixture only; does not claim new Policy/Action authorization or domain approval. */
    private function copyApprovedReview(array $f): object
    {
        $submission = (array) DB::table('maturity_workflow_submissions')->where('id', $f['initial']->submission_id)->first();
        unset($submission['id']);
        $submission['public_id'] = (string) Str::ulid();
        $submission['version_number'] = DB::table('maturity_workflow_submissions')->where('stage_id', $f['stage']->id)->max('version_number') + 1;
        $submissionId = DB::table('maturity_workflow_submissions')->insertGetId($submission);
        $prior = DB::table('review_maturity_reviews')->where('stage_id', $f['stage']->id)->orderByDesc('review_number')->first();
        $review = (array) $prior;
        foreach (['id', 'scope_key', 'previous_review_number', 'active_slot', 'decision_operation'] as $generated) {
            unset($review[$generated]);
        }
        $review['public_id'] = (string) Str::ulid();
        $review['submission_id'] = $submissionId;
        $review['review_number'] = $prior->review_number + 1;
        $review['supersedes_id'] = $prior->id;
        $review['request_key'] = (string) Str::ulid();
        $review['decision_key'] = (string) Str::ulid();
        $id = DB::table('review_maturity_reviews')->insertGetId($review);

        return DB::table('review_maturity_reviews')->where('id', $id)->first();
    }

    private function decisionPayload(array $f, object $attempt, object $review): array
    {
        return ['public_id' => (string) Str::ulid(), 'organization_id' => $f['organization']->id,
            'fixture_id' => $f['proof']['fixture_id'], 'approach_id' => $f['approach']->id,
            'stage_id' => $f['stage']->id, 'created_at' => now('UTC')->format('Y-m-d H:i:s'),
            'attempt_id' => $attempt->id, 'submission_id' => $review->submission_id,
            'review_id' => $review->id, 'reviewer_id' => $f['reviewer']->id,
            'selected_band_index' => 1, 'snapshot_json' => $f['initial']->snapshot_json,
            'reason' => 'لقطة علاقات لا تمنح صلاحية.', 'policy_ref' => 'TEST_ONLY_D014_V4'];
    }

    private function rejectStatement(callable $operation, string $kind = 'foreign'): void
    {
        DB::beginTransaction();
        try {
            $operation();
            $this->fail('Invalid persistence SQL accepted.');
        } catch (QueryException $error) {
            $this->assertSame(DB::getDriverName() === 'mysql' ? ($kind === 'unique' ? 1062 : ($kind === 'check' ? 3819 : 1452)) : 19, $error->errorInfo[1]);
        } finally {
            DB::rollBack();
        }
    }

    public function test_open_attempt_cannot_have_a_decision_and_the_exact_terminal_pair_is_required(): void
    {
        $f = $this->reassessmentFixture();
        $before = (array) $f['initial'];
        $attempt = $this->insertAttempt($f);
        $review = $this->copyApprovedReview($f);
        $data = $this->decisionPayload($f, $attempt, $review);
        $this->rejectStatement(fn () => DB::table('maturity_reassessment_decisions')->insert($data));
        DB::table('maturity_reassessment_attempts')->where('id', $attempt->id)->update([
            'state' => 'approved', 'approved_submission_id' => $review->submission_id, 'approved_review_id' => $review->id,
        ]);
        $wrong = $this->copyApprovedReview($f);
        $this->rejectStatement(fn () => DB::table('maturity_reassessment_decisions')->insert($this->decisionPayload($f, $attempt, $wrong)));
        DB::table('maturity_reassessment_decisions')->insert($data);
        $this->assertSame(1, DB::table('maturity_reassessment_decisions')->where('attempt_id', $attempt->id)->count());
        $this->assertSame($before, (array) DB::table('maturity_workflow_assessments')->where('id', $f['initial']->id)->first());
        $next = $this->insertAttempt($f, DB::table('maturity_reassessment_attempts')->where('id', $attempt->id)->first());
        DB::table('maturity_reassessment_attempts')->where('id', $next->id)->update([
            'state' => 'approved', 'approved_submission_id' => $review->submission_id, 'approved_review_id' => $review->id,
        ]);
        $this->rejectStatement(fn () => DB::table('maturity_reassessment_decisions')->insert($this->decisionPayload($f, $next, $review)), 'unique');
    }

    public function test_single_active_slot_and_positive_acyclic_predecessor_are_database_invariants(): void
    {
        $f = $this->reassessmentFixture();
        $attempt = $this->insertAttempt($f);
        $this->rejectStatement(fn () => $this->insertAttempt($f), 'unique');
        $this->rejectStatement(fn () => DB::table('maturity_reassessment_attempts')->where('id', $attempt->id)
            ->update(['attempt_number' => 0]), 'check');
        $this->rejectStatement(fn () => DB::table('maturity_reassessment_attempts')->where('id', $attempt->id)
            ->update(['state' => 'approved']), 'check');
        $this->rejectStatement(fn () => DB::table('maturity_reassessment_attempts')->where('id', $attempt->id)
            ->update(['classification' => 'OPERATIONAL']), 'check');
    }

    public function test_populated_rollback_refuses_before_any_reverse_ddl_and_preserves_journal(): void
    {
        $f = $this->reassessmentFixture();
        $this->insertAttempt($f);
        $tables = Schema::getTableListing();
        $journal = DB::table('migrations')->orderBy('id')->pluck('migration')->all();
        try {
            (require database_path('migrations/2026_11_13_000006_create_maturity_reassessment_events_table.php'))->down();
            $this->fail('Populated rollback must refuse.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('preserved before reverse DDL', $error->getMessage());
        }
        $this->assertSame($tables, Schema::getTableListing());
        $this->assertSame($journal, DB::table('migrations')->orderBy('id')->pluck('migration')->all());
    }
}
