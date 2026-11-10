<?php

namespace Tests\Feature\Maturity;

use App\Actions\Documents\ManageDocumentWorkspaceAssignments;
use App\Actions\Maturity\ConfigureMaturityStage;
use App\Actions\Maturity\SaveStageSubmission;
use App\Actions\Reviews\DecideReview;
use App\Actions\Reviews\ReadReviewMaturityWorkspace;
use App\Models\MaturityWorkflowAssessment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\ReviewMaturityFixtures;
use Tests\TestCase;

class InitialStageWorkflowTest extends TestCase
{
    use DatabaseMigrations, ReviewMaturityFixtures;

    protected function tearDown(): void
    {
        $this->cleanupReviewMaturity();
        parent::tearDown();
    }

    public function test_five_real_initial_stages_keep_unrated_submissions_and_manual_lowest_approved_categories(): void
    {
        $f = $this->reviewMaturityFixture();
        $previous = null;
        foreach (DB::table('maturity_workflow_stages')->orderBy('stage_ordinal')->get() as $stage) {
            $question = (string) Str::ulid();
            $expected = (string) Str::ulid();
            $data = ['questions' => [['public_id' => $question, 'text' => 'كيف تطبقون هذه المرحلة؟', 'instructions' => 'اربط الشاهد الاصطناعي.',
                'answer_required' => true, 'active' => true, 'expected_evidence' => [['public_id' => $expected, 'description' => 'مثال تطبيقي', 'required' => true]]]],
                'scale' => [['name' => 'بداية', 'description' => 'تصنيف تجريبي أدنى', 'lower_bound' => 0, 'upper_bound' => 30],
                    ['name' => 'تقدم', 'description' => 'تصنيف تجريبي آخر', 'lower_bound' => 31, 'upper_bound' => 100]]];
            $config = $this->asWorkflow($f, 'reviewer', fn () => app(ConfigureMaturityStage::class)->execute($f['approach']->public_id, $stage->public_id, $data, 'none'));
            $version = DB::table('document_versions')->first();
            $references = [['question_public_id' => $question, 'expected_evidence_public_id' => $expected,
                'document_public_id' => $f['document_public_id'], 'version_public_id' => $version->public_id]];
            $review = $this->asWorkflow($f, 'author', fn () => app(SaveStageSubmission::class)->execute($f['approach']->public_id, $stage->public_id,
                [$question => 'إجابة محفوظة في MySQL وSQLite'], $references, 'none', $config->public_id, (string) Str::ulid()));
            $this->asWorkflow($f, 'reviewer', fn () => app(DecideReview::class)->execute($review->public_id, $review->fingerprint, 'approved',
                'تقييم يدوي مستقل للشاهد والإجابة.', (string) Str::ulid(), 0));
            $assessment = MaturityWorkflowAssessment::where('stage_id', $stage->id)->firstOrFail();
            $this->assertSame($previous, $assessment->previous_assessment_id);
            $this->assertSame(0, $assessment->selected_band_index);
            $this->assertSame('بداية', $assessment->snapshot_json['scale'][0]['name']);
            $submission = json_decode(DB::table('maturity_workflow_submissions')->where('id', $review->submission_id)->value('content_json'), true);
            $this->assertArrayNotHasKey('selected_band_index', $submission);
            $this->assertSame($config->public_id, strtoupper($submission['configuration']['public_id']));
            $previous = $assessment->id;
        }
        $this->assertSame(5, DB::table('maturity_workflow_assessments')->count());
        $this->assertSame(10, DB::table('review_maturity_notifications')->count());
    }

    private function configured(array $f, int $ordinal = 1): array
    {
        $stage = DB::table('maturity_workflow_stages')->where('fixture_id', $f['proof']['fixture_id'])->where('stage_ordinal', $ordinal)->first();
        $question = (string) Str::ulid();
        $expected = (string) Str::ulid();
        $data = ['questions' => [['public_id' => $question, 'text' => 'سؤال الإصدار الأول', 'instructions' => 'أكمل الإجابة والشاهد.', 'answer_required' => true,
            'active' => true, 'expected_evidence' => [['public_id' => $expected, 'description' => 'شاهد مطلوب', 'required' => true]]]],
            'scale' => [['name' => 'بداية', 'description' => 'تصنيف اختباري يدوي', 'lower_bound' => 0, 'upper_bound' => 100]]];
        $config = $this->asWorkflow($f, 'reviewer', fn () => app(ConfigureMaturityStage::class)->execute($f['approach']->public_id, $stage->public_id, $data, 'none'));
        $version = DB::table('document_versions')->where('organization_id', $f['organization']->id)->first();
        $references = [['question_public_id' => $question, 'expected_evidence_public_id' => $expected, 'document_public_id' => $f['document_public_id'], 'version_public_id' => $version->public_id]];

        return compact('stage', 'config', 'question', 'expected', 'data', 'references');
    }

    public function test_incomplete_draft_can_open_review_but_approval_rolls_back_without_assessment_or_notice(): void
    {
        $f = $this->reviewMaturityFixture();
        $s = $this->configured($f);
        $round = $this->asWorkflow($f, 'author', fn () => app(SaveStageSubmission::class)->execute($f['approach']->public_id, $s['stage']->public_id, [], [], 'none', $s['config']->public_id, (string) Str::ulid()));
        try {
            $this->asWorkflow($f, 'reviewer', fn () => app(DecideReview::class)->execute($round->public_id, $round->fingerprint, 'approved', 'محاولة اعتماد ناقصة', (string) Str::ulid(), 0));
            $this->fail('Incomplete stage approved.');
        } catch (\DomainException) {
            $this->assertSame('pending', $round->fresh()->state);
        }
        $this->assertSame(0, DB::table('maturity_workflow_assessments')->count());
        $this->assertSame(1, DB::table('review_maturity_notifications')->count());
    }

    public function test_changed_config_stale_answer_and_stage_skip_are_conflicts_with_no_partial_writes(): void
    {
        $f = $this->reviewMaturityFixture();
        $s = $this->configured($f);
        $round = $this->asWorkflow($f, 'author', fn () => app(SaveStageSubmission::class)->execute($f['approach']->public_id, $s['stage']->public_id,
            [$s['question'] => 'إجابة أولى'], $s['references'], 'none', $s['config']->public_id, (string) Str::ulid()));
        $data = $s['data'];
        $data['questions'][0]['text'] = 'إعداد أحدث';
        $this->asWorkflow($f, 'reviewer', fn () => app(ConfigureMaturityStage::class)->execute($f['approach']->public_id, $s['stage']->public_id, $data, $s['config']->public_id));
        try {
            $this->asWorkflow($f, 'reviewer', fn () => app(DecideReview::class)->execute($round->public_id, $round->fingerprint, 'approved', 'قرار قديم', (string) Str::ulid(), 0));
            $this->fail('Old configuration approved.');
        } catch (ConflictHttpException) {
            $this->assertSame('pending', $round->fresh()->state);
        }
        $second = $this->configured($f, 2);
        try {
            $this->asWorkflow($f, 'author', fn () => app(SaveStageSubmission::class)->execute($f['approach']->public_id, $second['stage']->public_id,
                [$second['question'] => 'تجاوز'], $second['references'], 'none', $second['config']->public_id, (string) Str::ulid()));
            $this->fail('Stage skip accepted.');
        } catch (ConflictHttpException) {
            $this->assertSame(1, DB::table('maturity_workflow_submissions')->count());
        }
        $this->assertSame(0, DB::table('maturity_workflow_assessments')->count());
    }

    public function test_revoked_document_policy_denies_approval_and_redacts_historical_reference_identity(): void
    {
        $f = $this->reviewMaturityFixture();
        $s = $this->configured($f);
        $round = $this->asWorkflow($f, 'author', fn () => app(SaveStageSubmission::class)->execute($f['approach']->public_id, $s['stage']->public_id,
            [$s['question'] => 'إجابة محفوظة'], $s['references'], 'none', $s['config']->public_id, (string) Str::ulid()));
        $grant = DB::table('document_workspace_assignments')->where('fixture_id', $f['proof']['fixture_id'])->where('grantee_id', $f['reviewer']->id)->where('operation', 'history')->first();
        $this->asWorkflow($f, 'issuer', fn () => app(ManageDocumentWorkspaceAssignments::class)->revoke($f['issuer'], $grant->public_id));
        try {
            $this->asWorkflow($f, 'reviewer', fn () => app(DecideReview::class)->execute($round->public_id, $round->fingerprint, 'approved', 'مرفوض بعد السحب', (string) Str::ulid(), 0));
            $this->fail('Revoked version access still approved.');
        } catch (AuthorizationException) {
            $this->assertSame('pending', $round->fresh()->state);
        }
        $current = $this->asWorkflow($f, 'reviewer', fn () => app(ReadReviewMaturityWorkspace::class)->show($f['approach']->public_id));
        $reference = $current['stages'][0]['submission']['evidence'][0];
        $this->assertArrayNotHasKey('document_public_id', $reference);
        $this->assertArrayNotHasKey('version_public_id', $reference);
        $this->assertSame(1, DB::table('maturity_workflow_evidence')->count());
        $this->assertSame(0, DB::table('maturity_workflow_assessments')->count());
    }

    public function test_used_settings_can_be_versioned_without_rewriting_original_assessment_questions_answers_or_scale(): void
    {
        $f = $this->reviewMaturityFixture();
        $s = $this->configured($f);
        $round = $this->asWorkflow($f, 'author', fn () => app(SaveStageSubmission::class)->execute($f['approach']->public_id, $s['stage']->public_id,
            [$s['question'] => 'الإجابة التاريخية'], $s['references'], 'none', $s['config']->public_id, (string) Str::ulid()));
        $this->asWorkflow($f, 'reviewer', fn () => app(DecideReview::class)->execute($round->public_id, $round->fingerprint, 'approved', 'تعليل محفوظ', (string) Str::ulid(), 0));
        $data = $s['data'];
        $data['questions'][0]['text'] = 'سؤال جديد';
        $data['scale'][0]['name'] = 'سلم جديد';
        $this->asWorkflow($f, 'reviewer', fn () => app(ConfigureMaturityStage::class)->execute($f['approach']->public_id, $s['stage']->public_id, $data, $s['config']->public_id));
        $current = $this->asWorkflow($f, 'reviewer', fn () => app(ReadReviewMaturityWorkspace::class)->show($f['approach']->public_id));
        $this->assertSame('سؤال جديد', $current['stages'][0]['configuration']['questions'][0]['text']);
        $this->assertSame('سؤال الإصدار الأول', $current['stages'][0]['assessment']['submission_snapshot']['configuration']['questions'][0]['text']);
        $this->assertSame('الإجابة التاريخية', $current['stages'][0]['assessment']['answers'][0]['answer']);
        $this->assertSame('بداية', $current['stages'][0]['assessment']['scale'][0]['name']);
        $this->assertSame(2, DB::table('maturity_workflow_configurations')->count());
        $this->assertSame(1, DB::table('maturity_workflow_assessments')->count());
    }
}
