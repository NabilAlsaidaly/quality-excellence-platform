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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ClosureReassessmentWorkflowTest extends ReassessmentOperationAuthorizationTest
{
    protected function tearDown(): void
    {
        try {
            if ($this->app) {
                foreach ($this->workflowFixtures as $f) {
                    foreach (['maturity_reassessment_events', 'maturity_reassessment_decisions', 'maturity_reassessment_attempts'] as $table) {
                        foreach (DB::table($table)->where('organization_id', $f['organization']->id)->where('fixture_id', $f['proof']['fixture_id'])->orderByDesc('id')->pluck('id') as $id) {
                            DB::table($table)->where('id', $id)->delete();
                        }
                    }
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    protected function completeCycle(): array
    {
        $f = $this->reviewMaturityFixture();
        $f['workflow_proof']['reassessment_operation_scope'] = 'CLOSURE_REASSESSMENT_LOCAL_001';
        $stages = DB::table('maturity_workflow_stages')->where('approach_id', $f['approach']->id)->orderBy('stage_ordinal')->get();
        foreach ($stages as $stage) {
            $question = (string) Str::ulid();
            $data = ['questions' => [['public_id' => $question, 'text' => 'سؤال المرحلة الاختبارية '.$stage->stage_ordinal,
                'instructions' => 'إجابة موثقة', 'answer_required' => true, 'active' => true,
                'expected_evidence' => [['public_id' => (string) Str::ulid(), 'description' => 'شاهد اختياري للاختبار فقط', 'required' => false]]]],
                'scale' => [['name' => 'بداية', 'description' => 'حالة اختبارية', 'lower_bound' => 0, 'upper_bound' => 30],
                    ['name' => 'تقدم', 'description' => 'حالة اختبارية', 'lower_bound' => 31, 'upper_bound' => 100]]];
            $config = $this->asWorkflow($f, 'reviewer', fn () => app(ConfigureMaturityStage::class)->execute($f['approach']->public_id, $stage->public_id, $data, 'none'));
            $review = $this->asWorkflow($f, 'author', fn () => app(SaveStageSubmission::class)->execute($f['approach']->public_id,
                $stage->public_id, [$question => 'إجابة أصلية معتمدة'], [], 'none', $config->public_id, (string) Str::ulid()));
            $this->asWorkflow($f, 'reviewer', fn () => app(DecideReview::class)->execute($review->public_id, $review->fingerprint, 'approved', 'قرار أولي مستقل.', (string) Str::ulid(), 1));
            foreach ([['author', ['open_reassessment', 'save_reassessment', 'history_reassessment']], ['reviewer', ['review_reassessment', 'history_reassessment']]] as [$name, $operations]) {
                foreach ($operations as $operation) {
                    $this->asWorkflow($f, 'issuer', fn () => app(ManageStageReassessmentOperations::class)->issue($f[$name]->public_id, $operation, $stage->public_id));
                }
            }
        }

        return $f + ['stages' => $stages];
    }

    protected function state(array $f): array
    {
        return $this->asWorkflow($f, 'author', fn () => app(ReviewMaturityAccess::class)->within('read_workflow', $f['approach']->public_id, null,
            fn () => app(EffectiveMaturityState::class)->load($f['approach'])));
    }

    private function approve(array $f, object $stage, int $band): array
    {
        $effective = $this->state($f)['stages'][$stage->stage];
        $attempt = $this->asWorkflow($f, 'author', fn () => app(OpenStageReassessment::class)->execute(
            $f['approach']->public_id, $stage->public_id, $effective['assessment_public_id'], (string) Str::ulid()));
        $answers = array_fill_keys(array_column($effective['configuration']['questions'], 'public_id'), 'إجابة إعادة تقييم محفوظة');
        $saved = $this->asWorkflow($f, 'author', fn () => app(SaveStageReassessment::class)->execute($attempt['attempt_public_id'], $answers, [], 1, (string) Str::ulid()));
        $review = DB::table('review_maturity_reviews')->where('public_id', $saved['review_public_id'])->sole();

        return $this->asWorkflow($f, 'reviewer', fn () => app(DecideStageReassessment::class)->execute($attempt['attempt_public_id'], $review->public_id,
            $review->fingerprint, $saved['revision'], 'approved', 'قرار إعادة تقييم مستقل.', (string) Str::ulid(), $band));
    }

    public function test_lower_category_blocks_later_work_until_sequential_reapproval_and_keeps_initial_history(): void
    {
        $f = $this->completeCycle();
        $initial = DB::table('maturity_workflow_assessments')->where('approach_id', $f['approach']->id)->orderBy('id')->get()->toJson();
        $this->approve($f, $f['stages'][0], 0);
        $state = $this->state($f)['stages'];
        foreach ($f['stages']->skip(1) as $stage) {
            $this->assertSame('REVALIDATION_REQUIRED', $state[$stage->stage]['dependency_state']);
        }
        $third = $f['stages'][2];
        try {
            $this->asWorkflow($f, 'author', fn () => app(OpenStageReassessment::class)->execute($f['approach']->public_id, $third->public_id,
                $state[$third->stage]['assessment_public_id'], (string) Str::ulid()));
            $this->fail('Stage three must wait for stage-two revalidation.');
        } catch (ConflictHttpException) {
            $this->assertDatabaseCount('maturity_reassessment_attempts', 1);
        }
        foreach ($f['stages']->skip(1) as $stage) {
            $this->approve($f, $stage, 1);
            $this->assertFalse($this->state($f)['stages'][$stage->stage]['requires_revalidation']);
        }
        $this->assertSame($initial, DB::table('maturity_workflow_assessments')->where('approach_id', $f['approach']->id)->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('maturity_reassessment_decisions', 5);
        $this->assertSame(20, DB::table('review_maturity_notifications')->count(), 'Ten initial + ten reassessment notices share their origin transactions.');
        $event = DB::table('maturity_reassessment_events')->where('event', 'approved')->orderBy('id')->first();
        $this->assertSame('LOWER', json_decode($event->details_json, true)['effective_impact']['comparison']);
    }

    public function test_initial_review_action_cannot_decide_a_reassessment_submission(): void
    {
        $f = $this->completeCycle();
        $stage = $f['stages'][0];
        $effective = $this->state($f)['stages'][$stage->stage];
        $attempt = $this->asWorkflow($f, 'author', fn () => app(OpenStageReassessment::class)->execute(
            $f['approach']->public_id, $stage->public_id, $effective['assessment_public_id'], (string) Str::ulid()));
        $answers = array_fill_keys(array_column($effective['configuration']['questions'], 'public_id'), 'إجابة لإعادة التقييم');
        $saved = $this->asWorkflow($f, 'author', fn () => app(SaveStageReassessment::class)->execute($attempt['attempt_public_id'], $answers, [], 1, (string) Str::ulid()));
        $review = DB::table('review_maturity_reviews')->where('public_id', $saved['review_public_id'])->sole();
        try {
            $this->asWorkflow($f, 'reviewer', fn () => app(DecideReview::class)->execute($review->public_id, $review->fingerprint,
                'changes_requested', 'يلزم مسار إعادة التقييم وصلاحيته المنفصلة.', (string) Str::ulid()));
            $this->fail('Initial-stage review Action must not mutate a reassessment review.');
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('review_maturity_reviews', ['id' => $review->id, 'state' => 'pending']);
            $this->assertDatabaseMissing('maturity_reassessment_events', ['event' => 'changes_requested']);
            $this->assertDatabaseCount('maturity_reassessment_decisions', 0);
        }
    }

    public function test_equal_and_higher_approvals_preserve_validity_and_never_clear_existing_downstream_debt(): void
    {
        $f = $this->completeCycle();
        $stage = $f['stages'][0];
        $this->approve($f, $stage, 1);
        foreach ($this->state($f)['stages'] as $current) {
            $this->assertSame('VALID', $current['dependency_state']);
        }
        $this->approve($f, $stage, 0);
        $this->approve($f, $stage, 1);
        foreach ($f['stages']->skip(1) as $later) {
            $this->assertSame('REVALIDATION_REQUIRED', $this->state($f)['stages'][$later->stage]['dependency_state'],
                'Raising an earlier stage cannot silently reuse the later stages\' prior approvals.');
        }
        $events = DB::table('maturity_reassessment_events')->where('event', 'approved')->orderBy('id')->pluck('details_json')
            ->map(fn ($json) => json_decode($json, true)['effective_impact']['comparison'])->all();
        $this->assertSame(['EQUAL', 'LOWER', 'HIGHER'], $events);
        $this->assertDatabaseCount('maturity_workflow_assessments', 5);
    }

    public function test_correction_preserves_effective_history_and_original_configuration_until_independent_approval(): void
    {
        $f = $this->completeCycle();
        $stage = $f['stages'][0];
        $before = $this->state($f)['stages'];
        $original = $before[$stage->stage]['configuration'];
        $latest = DB::table('maturity_workflow_configurations')->where('stage_id', $stage->id)->first();
        $changed = ['questions' => $original['questions'], 'scale' => $original['scale']];
        $changed['questions'][0]['text'] = 'سؤال أحدث لا يبدل الأصل المعتمد';
        $this->asWorkflow($f, 'reviewer', fn () => app(ConfigureMaturityStage::class)->execute($f['approach']->public_id, $stage->public_id, $changed, $latest->public_id));
        $attempt = $this->asWorkflow($f, 'author', fn () => app(OpenStageReassessment::class)->execute($f['approach']->public_id,
            $stage->public_id, $before[$stage->stage]['assessment_public_id'], (string) Str::ulid()));
        $answers = array_fill_keys(array_column($original['questions'], 'public_id'), 'إجابة تحتاج إلى تصحيح');
        $saved = $this->asWorkflow($f, 'author', fn () => app(SaveStageReassessment::class)->execute($attempt['attempt_public_id'], $answers, [], 1, (string) Str::ulid()));
        $review = DB::table('review_maturity_reviews')->where('public_id', $saved['review_public_id'])->sole();
        $correct = $this->asWorkflow($f, 'reviewer', fn () => app(DecideStageReassessment::class)->execute($attempt['attempt_public_id'],
            $review->public_id, $review->fingerprint, $saved['revision'], 'changes_requested', 'وضح التطبيق قبل اعتماد المحاولة.', (string) Str::ulid()));
        $this->assertSame($before, $this->state($f)['stages']);
        $this->assertDatabaseCount('maturity_reassessment_decisions', 0);
        $answers = array_map(fn () => 'إجابة مصححة محفوظة', $answers);
        $saved = $this->asWorkflow($f, 'author', fn () => app(SaveStageReassessment::class)->execute($attempt['attempt_public_id'], $answers, [], $correct['revision'], (string) Str::ulid()));
        $newReview = DB::table('review_maturity_reviews')->where('public_id', $saved['review_public_id'])->sole();
        $this->asWorkflow($f, 'reviewer', fn () => app(DecideStageReassessment::class)->execute($attempt['attempt_public_id'],
            $newReview->public_id, $newReview->fingerprint, $saved['revision'], 'approved', 'اعتُمدت الإجابة المصححة مستقلًا.', (string) Str::ulid(), 1));
        $this->assertSame($original, $this->state($f)['stages'][$stage->stage]['configuration']);
        $this->assertDatabaseHas('review_maturity_reviews', ['id' => $review->id, 'state' => 'changes_requested']);
        $this->assertDatabaseCount('maturity_reassessment_decisions', 1);
        $this->assertDatabaseCount('maturity_workflow_assessments', 5);
        $this->assertDatabaseCount('review_maturity_notifications', 14);
    }
}
