<?php

namespace Tests\Feature\Maturity;

use App\Actions\Maturity\ConfigureMaturityStage;
use App\Actions\Maturity\DecideStageReassessment;
use App\Actions\Maturity\OpenStageReassessment;
use App\Actions\Maturity\SaveStageReassessment;
use App\Actions\Maturity\SaveStageSubmission;
use App\Actions\Reviews\DecideReview;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\ReviewMaturityFixtures;
use Tests\TestCase;

class StageReassessmentWorkflowTest extends TestCase
{
    use DatabaseMigrations, ReviewMaturityFixtures;

    public function test_unjournaled_creation_is_never_adopted_or_dropped_automatically(): void
    {
        $name = '2026_11_13_000004_create_maturity_reassessment_attempts_table';
        $entry = (array) DB::table('migrations')->where('migration', $name)->first();
        $tables = Schema::getTableListing();
        DB::table('migrations')->where('migration', $name)->delete();
        $journal = DB::table('migrations')->orderBy('id')->get()->toArray();
        try {
            foreach ([['2026_11_13_000004_create_maturity_reassessment_attempts_table.php', 'up', 'Existing persistence table'],
                ['2026_11_13_000006_create_maturity_reassessment_events_table.php', 'down', 'Unknown persistence shape/journal']] as [$file, $method, $message]) {
                try {
                    (require database_path('migrations/'.$file))->{$method}();
                    $this->fail('A crash before journaling requires explicit reviewed recovery.');
                } catch (\RuntimeException $error) {
                    $this->assertStringContainsString($message, $error->getMessage());
                }
                $this->assertSame($tables, Schema::getTableListing());
                $this->assertEquals($journal, DB::table('migrations')->orderBy('id')->get()->toArray());
            }
        } finally {
            // Restore only the exact captured entry of this owned in-memory fixture.
            DB::table('migrations')->insert($entry);
        }
    }

    public function test_sqlite_missing_parent_is_refused_before_a_partially_applied_attempt_table(): void
    {
        $tail = array_map(fn ($name) => 'database/migrations/'.$name, [
            '2026_11_13_000004_create_maturity_reassessment_attempts_table.php',
            '2026_11_13_000005_create_maturity_reassessment_decisions_table.php',
            '2026_11_13_000006_create_maturity_reassessment_events_table.php',
        ]);
        $this->artisan('migrate:rollback', ['--path' => $tail, '--step' => DB::table('migrations')->count(), '--force' => true])->assertExitCode(0);
        DB::statement('ALTER TABLE maturity_workflow_assessments RENAME TO reassessment_parent_fixture');
        try {
            try {
                (require database_path('migrations/2026_11_13_000004_create_maturity_reassessment_attempts_table.php'))->up();
                $this->fail('SQLite must not journal a table whose required parent is missing.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('parent', $error->getMessage());
                $this->assertFalse(Schema::hasTable('maturity_reassessment_attempts'));
            }
        } finally {
            // Test-only cleanup of this exact failed EMPTY creation in the forced memory database.
            if (Schema::hasTable('maturity_reassessment_attempts') && ! DB::table('maturity_reassessment_attempts')->exists()) {
                Schema::drop('maturity_reassessment_attempts');
            }
            DB::statement('ALTER TABLE reassessment_parent_fixture RENAME TO maturity_workflow_assessments');
            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        }
    }

    public function test_an_initial_approved_review_cannot_be_reused_as_a_reassessment_decision(): void
    {
        $f = $this->reassessmentFixture();
        $attempt = $this->insertAttempt($f);
        $this->expectException(ConflictHttpException::class);
        $this->asWorkflow($f, 'reviewer', fn () => DB::transaction(fn () => app(DecideStageReassessment::class)->assertReviewBelongsToAttempt(
            $attempt, (object) DB::table('review_maturity_reviews')->where('id', $f['review']->id)->first(), $f['reviewer'])));
    }

    public function test_initial_answer_and_review_grants_do_not_activate_reassessment(): void
    {
        $f = $this->reassessmentFixture();
        $before = (array) $f['initial'];
        try {
            $this->asWorkflow($f, 'author', fn () => app(OpenStageReassessment::class)
                ->execute($f['approach']->public_id, $f['stage']->public_id, $f['initial']->public_id, (string) Str::ulid()));
            $this->fail('Initial-stage assignments cannot authorize reassessment.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('maturity_reassessment_attempts', 0);
            $this->assertSame($before, (array) DB::table('maturity_workflow_assessments')->where('id', $f['initial']->id)->first());
        }
    }

    public function test_original_approved_configuration_is_used_even_after_editable_configuration_changes(): void
    {
        $f = $this->reassessmentFixture();
        $attempt = $this->insertAttempt($f);
        $old = json_decode($f['configuration']->configuration_json, true, 64, JSON_THROW_ON_ERROR);
        $data = ['questions' => $old['questions'], 'scale' => $old['scale']];
        $data['questions'][0]['text'] = 'إعداد أحدث لا يستبدل اللقطة المعتمدة';
        $new = $this->asWorkflow($f, 'reviewer', fn () => app(ConfigureMaturityStage::class)->execute(
            $f['approach']->public_id, $f['stage']->public_id, $data, $f['configuration']->public_id));
        $pinned = $this->asWorkflow($f, 'reviewer', fn () => DB::transaction(fn () => app(SaveStageReassessment::class)->configurationRow($attempt)));
        $this->assertNotSame($new->id, $pinned->id);
        $this->assertSame($f['configuration']->id, $pinned->id);
        $this->assertSame($f['configuration']->configuration_json, $pinned->configuration_json);
        $this->expectException(ConflictHttpException::class);
        $this->asWorkflow($f, 'reviewer', fn () => DB::transaction(function () use ($attempt, $new): void {
            DB::table('maturity_reassessment_attempts')->where('id', $attempt->id)->update(['configuration_id' => $new->id]);
            app(SaveStageReassessment::class)->configurationRow(DB::table('maturity_reassessment_attempts')->where('id', $attempt->id)->first());
        }));
    }

    public function test_exact_saved_review_ownership_and_independent_reviewer_are_locked_invariants(): void
    {
        $f = $this->reassessmentFixture();
        $attempt = $this->insertAttempt($f);
        $review = $this->copyApprovedReview($f);
        DB::table('maturity_reassessment_events')->insert(['public_id' => (string) Str::ulid(),
            'organization_id' => $attempt->organization_id, 'fixture_id' => $attempt->fixture_id,
            'approach_id' => $attempt->approach_id, 'stage_id' => $attempt->stage_id, 'attempt_id' => $attempt->id,
            'actor_id' => $f['author']->id, 'submission_id' => $review->submission_id, 'request_key' => (string) Str::ulid(),
            'event' => 'saved', 'details_json' => json_encode(['review_public_id' => $review->public_id], JSON_THROW_ON_ERROR),
            'created_at' => now('UTC')->format('Y-m-d H:i:s')]);
        $this->asWorkflow($f, 'reviewer', fn () => DB::transaction(fn () => app(DecideStageReassessment::class)->assertReviewBelongsToAttempt($attempt, $review, $f['reviewer'])));
        $this->assertSame(0, DB::table('maturity_reassessment_decisions')->count(), 'Read-only invariant validation does not approve anything.');
        $this->expectException(\DomainException::class);
        $this->asWorkflow($f, 'author', fn () => DB::transaction(fn () => app(DecideStageReassessment::class)->assertReviewBelongsToAttempt($attempt, $review, $f['author'])));
    }

    public function test_review_ownership_cannot_cross_tenant_context(): void
    {
        $f = $this->reassessmentFixture();
        $other = $this->reassessmentFixture();
        $attempt = $this->insertAttempt($f);
        $this->expectException(AuthorizationException::class);
        $this->asWorkflow($f, 'reviewer', fn () => DB::transaction(fn () => app(DecideStageReassessment::class)->assertReviewBelongsToAttempt(
            $attempt, (object) DB::table('review_maturity_reviews')->where('id', $other['review']->id)->first(), $f['reviewer'])));
    }

    public function test_sqlite_persists_the_approved_attempt_state_and_exact_pair_foreign_keys(): void
    {
        $this->assertTrue(Schema::hasTable('maturity_reassessment_decisions'), 'The approved v4 decision table is required.');
        $keys = collect(DB::select('PRAGMA foreign_key_list(maturity_reassessment_decisions)'))->groupBy('id');
        $attemptKeys = $keys->filter(fn ($rows) => $rows->first()->table === 'maturity_reassessment_attempts');
        $this->assertCount(2, $attemptKeys);
        $this->assertTrue($attemptKeys->contains(fn ($rows) => $rows->sortBy('seq')->pluck('to')->all() === ['organization_id', 'fixture_id', 'approach_id', 'stage_id', 'id', 'state']));
        $this->assertTrue($attemptKeys->contains(fn ($rows) => $rows->sortBy('seq')->pluck('to')->all() === ['organization_id', 'fixture_id', 'approach_id', 'stage_id', 'id', 'approved_submission_id', 'approved_review_id']));
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
