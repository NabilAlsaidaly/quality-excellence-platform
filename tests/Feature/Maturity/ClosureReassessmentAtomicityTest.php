<?php

namespace Tests\Feature\Maturity;

use App\Actions\Maturity\ConfigureMaturityStage;
use App\Actions\Maturity\DecideStageReassessment;
use App\Actions\Maturity\EffectiveMaturityState;
use App\Actions\Maturity\ManageStageReassessmentOperations;
use App\Actions\Maturity\OpenStageReassessment;
use App\Actions\Maturity\SaveStageReassessment;
use App\Actions\Maturity\SaveStageSubmission;
use App\Actions\Reviews\DecideReview;
use App\Authorization\ReviewMaturityAccess;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\ReviewMaturityFixtures;
use Tests\TestCase;

class ClosureReassessmentAtomicityTest extends TestCase
{
    use DatabaseMigrations, ReviewMaturityFixtures;

    protected function tearDown(): void
    {
        try {
            if ($this->app) {
                foreach ($this->workflowFixtures as $fixture) {
                    foreach (['maturity_reassessment_events', 'maturity_reassessment_decisions', 'maturity_reassessment_attempts',
                        'maturity_reassessment_operation_changes', 'maturity_reassessment_operation_assignments'] as $table) {
                        foreach (DB::table($table)->where('organization_id', $fixture['organization']->id)->where('fixture_id', $fixture['proof']['fixture_id'])->orderByDesc('id')->pluck('id') as $id) {
                            DB::table($table)->where('id', $id)->delete();
                        }
                    }
                }
                $this->cleanupReviewMaturity();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_notification_failure_rolls_back_approval_and_effective_impact_then_exact_retry_and_stale_conflict_preserve_history(): void
    {
        $fixture = $this->reviewMaturityFixture();
        $fixture['workflow_proof']['reassessment_operation_scope'] = 'CLOSURE_REASSESSMENT_LOCAL_001';
        $stages = DB::table('maturity_workflow_stages')->where('approach_id', $fixture['approach']->id)->orderBy('stage_ordinal')->get();
        foreach ($stages as $stage) {
            $question = (string) Str::ulid();
            $configuration = $this->asWorkflow($fixture, 'reviewer', fn () => app(ConfigureMaturityStage::class)->execute($fixture['approach']->public_id, $stage->public_id,
                ['questions' => [['public_id' => $question, 'text' => 'سؤال اختبار الذرية', 'instructions' => 'إجابة نصية موثقة للاختبار.', 'answer_required' => true, 'active' => true,
                    'expected_evidence' => [['public_id' => (string) Str::ulid(), 'description' => 'شاهد اختياري في اختبار الذرية', 'required' => false]]]],
                    'scale' => [['name' => 'بداية', 'description' => 'اختبار فقط', 'lower_bound' => 0, 'upper_bound' => 30],
                        ['name' => 'تقدم', 'description' => 'اختبار فقط', 'lower_bound' => 31, 'upper_bound' => 100]]], 'none'));
            $review = $this->asWorkflow($fixture, 'author', fn () => app(SaveStageSubmission::class)->execute($fixture['approach']->public_id, $stage->public_id,
                [$question => 'إجابة أولية محفوظة'], [], 'none', $configuration->public_id, (string) Str::ulid()));
            $this->asWorkflow($fixture, 'reviewer', fn () => app(DecideReview::class)->execute($review->public_id, $review->fingerprint,
                'approved', 'اعتماد أولي مستقل.', (string) Str::ulid(), 1));
        }
        $stage = $stages[0];
        foreach ([['author', ['open_reassessment', 'save_reassessment']], ['reviewer', ['review_reassessment']]] as [$actor, $operations]) {
            foreach ($operations as $operation) {
                $this->asWorkflow($fixture, 'issuer', fn () => app(ManageStageReassessmentOperations::class)->issue($fixture[$actor]->public_id, $operation, $stage->public_id));
            }
        }
        $state = fn () => $this->asWorkflow($fixture, 'author', fn () => app(ReviewMaturityAccess::class)->within('read_workflow', $fixture['approach']->public_id, null,
            fn () => app(EffectiveMaturityState::class)->load($fixture['approach'])));
        $before = $state();
        $initial = DB::table('maturity_workflow_assessments')->orderBy('id')->get()->toJson();
        $current = $before['stages'][$stage->stage];
        $attempt = $this->asWorkflow($fixture, 'author', fn () => app(OpenStageReassessment::class)->execute($fixture['approach']->public_id, $stage->public_id,
            $current['assessment_public_id'], (string) Str::ulid()));
        $answers = array_fill_keys(array_column($current['configuration']['questions'], 'public_id'), 'إجابة جديدة مستقلة.');
        $saved = $this->asWorkflow($fixture, 'author', fn () => app(SaveStageReassessment::class)->execute($attempt['attempt_public_id'], $answers, [], 1, (string) Str::ulid()));
        $review = DB::table('review_maturity_reviews')->where('public_id', $saved['review_public_id'])->sole();
        $notices = DB::table('review_maturity_notifications')->orderBy('id')->get()->toJson();
        $key = (string) Str::ulid();
        $decide = fn (string $requestKey) => $this->asWorkflow($fixture, 'reviewer', fn () => app(DecideStageReassessment::class)->execute($attempt['attempt_public_id'],
            $review->public_id, $review->fingerprint, $saved['revision'], 'approved', 'قرار مستقل بتصنيف أقل.', $requestKey, 0));
        $armed = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed): void {
            if ($armed && preg_match('/\Ainsert into ["`]?review_maturity_notifications["`]?\s/i', $sql)) {
                throw new RuntimeException('Injected reassessment notification failure.');
            }
        });
        try {
            try {
                $decide($key);
                $this->fail('Notification failure cannot commit the review, decision or dependency effect.');
            } catch (RuntimeException $error) {
                $this->assertSame('Injected reassessment notification failure.', $error->getMessage());
            } finally {
                $armed = false;
            }
            $this->assertSame(json_encode($before, JSON_THROW_ON_ERROR), json_encode($state(), JSON_THROW_ON_ERROR),
                'The complete effective state must retain identical serialized values, independently of hydrated object identity.');
            $this->assertDatabaseCount('maturity_reassessment_decisions', 0);
            $this->assertDatabaseMissing('maturity_reassessment_events', ['event' => 'approved']);
            $this->assertDatabaseHas('review_maturity_reviews', ['id' => $review->id, 'state' => 'pending']);
            $this->assertDatabaseHas('maturity_reassessment_attempts', ['public_id' => $attempt['attempt_public_id'], 'state' => 'open', 'revision' => $saved['revision']]);
            $this->assertSame($notices, DB::table('review_maturity_notifications')->orderBy('id')->get()->toJson());
            $decision = $decide($key);
            $this->assertSame($decision, $decide($key), 'Exact retry must not duplicate a decision or its notices.');
            $this->assertDatabaseCount('maturity_reassessment_decisions', 1);
            $this->assertSame(1, DB::table('review_maturity_notifications')->count() - count(json_decode($notices, true)),
                'The review-opened notice already exists; approval adds exactly its named author recipient.');
            $this->assertDatabaseHas('review_maturity_notifications', ['review_id' => $review->id, 'event' => 'approved', 'recipient_id' => $fixture['author']->id]);
            try {
                $decide((string) Str::ulid());
                $this->fail('A new stale decision must conflict after approval.');
            } catch (ConflictHttpException) {
                $this->assertDatabaseCount('maturity_reassessment_decisions', 1);
            }
            foreach ($stages->skip(1) as $later) {
                $this->assertSame('REVALIDATION_REQUIRED', $state()['stages'][$later->stage]['dependency_state']);
            }
            $this->assertSame($initial, DB::table('maturity_workflow_assessments')->orderBy('id')->get()->toJson());
        } finally {
            $armed = false;
        }
    }
}
