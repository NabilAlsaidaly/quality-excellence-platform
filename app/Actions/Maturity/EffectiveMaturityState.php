<?php

namespace App\Actions\Maturity;

use App\Authorization\ReviewMaturityAccess;
use App\Domain\Maturity\ApprovedStageAssessment;
use App\Domain\Maturity\InitialMaturityCycle;
use App\Domain\Maturity\MaturityStage;
use App\Domain\Maturity\RatingBand;
use App\Domain\Maturity\RatingScale;
use App\Domain\Maturity\StageSubmission;
use App\Domain\Maturity\TestOnlyReassessmentDependencies;
use App\Domain\Reviews\ReviewSnapshot;
use App\Domain\Reviews\ReviewState;
use App\Domain\Reviews\ReviewWorkflow;
use App\Models\Approach;
use App\Models\Organization;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Deterministic projection of immutable decisions, using the approved dependency domain. */
final class EffectiveMaturityState
{
    public function load(Approach $approach): array
    {
        $proof = ReviewMaturityAccess::context() ?? throw new AuthorizationException;
        if (! ReviewMaturityAccess::hasReassessmentScope() || DB::transactionLevel() === 0
            || (int) $approach->id !== $proof['approach_id'] || (int) $approach->organization_id !== $proof['organization_id']) {
            throw new AuthorizationException;
        }
        $scope = fn (string $table) => DB::table($table)->where('organization_id', $proof['organization_id'])
            ->where('fixture_id', $proof['fixture_id'])->where('approach_id', $approach->id);
        $stages = $scope('maturity_workflow_stages')->orderBy('stage_ordinal')->lockForUpdate()->get()->keyBy('id');
        $initial = $scope('maturity_workflow_assessments')->orderBy('stage_ordinal')->lockForUpdate()->get();
        if ($initial->pluck('stage_ordinal')->all() !== [1, 2, 3, 4, 5]) {
            throw new ConflictHttpException('يلزم اكتمال المراحل الخمس الأولية قبل إعادة التقييم.');
        }
        $organization = Organization::findOrFail($approach->organization_id);
        $cycle = InitialMaturityCycle::begin($organization->public_id, $approach->public_id);
        $submissions = [];
        $effective = [];
        foreach ($initial as $row) {
            $stage = $stages[$row->stage_id] ?? throw new AuthorizationException;
            $submissionRow = $scope('maturity_workflow_submissions')->where('stage_id', $stage->id)->where('id', $row->submission_id)->lockForUpdate()->first() ?? throw new AuthorizationException;
            $submission = app(SaveStageSubmission::class)->hydrate($approach, $submissionRow);
            $assessment = $this->assessment($approach, $submission, $row);
            $cycle = $cycle->withAssessment($assessment);
            $submissions[$stage->stage] = $submission;
            $effective[$stage->stage] = $this->dto($row, $assessment, $submission, $stage);
        }
        $domain = TestOnlyReassessmentDependencies::fromInitial($cycle, $submissions);
        foreach ($scope('maturity_reassessment_decisions')->orderBy('id')->lockForUpdate()->get() as $row) {
            $stage = $stages[$row->stage_id] ?? throw new AuthorizationException;
            $attempt = $scope('maturity_reassessment_attempts')->where('id', $row->attempt_id)->where('stage_id', $stage->id)
                ->where('state', 'approved')->where('approved_submission_id', $row->submission_id)->where('approved_review_id', $row->review_id)->lockForUpdate()->first() ?? throw new AuthorizationException;
            $submissionRow = $scope('maturity_workflow_submissions')->where('stage_id', $stage->id)->where('id', $row->submission_id)->lockForUpdate()->first() ?? throw new AuthorizationException;
            $submission = app(SaveStageReassessment::class)->hydrate($approach, $attempt, $submissionRow);
            $assessment = $this->assessment($approach, $submission, $row);
            $domain = $domain->withApproval($assessment, $submission, $effective[$stage->stage]['review_public_id']);
            $effective[$stage->stage] = $this->dto($row, $assessment, $submission, $stage);
        }
        foreach ($effective as $key => &$item) {
            $stage = MaturityStage::from($key);
            $item['requires_revalidation'] = $domain->requiresRevalidation($stage);
            $item['may_reassess'] = $domain->mayReassess($stage);
            $item['dependency_state'] = $item['requires_revalidation'] ? 'REVALIDATION_REQUIRED' : 'VALID';
        }
        unset($item);

        return ['domain' => $domain, 'stages' => $effective];
    }

    public function assertMayMutate(Approach $approach, object $stage): void
    {
        if (! $this->load($approach)['domain']->mayReassess(MaturityStage::from($stage->stage))) {
            throw new ConflictHttpException('يلزم إعادة التحقق من المراحل السابقة بالترتيب؛ يبقى التاريخ متاحًا للقراءة.');
        }
    }

    /** Calculated before writes, recorded atomically in the immutable approval event. */
    public function impact(Approach $approach, ApprovedStageAssessment $assessment, StageSubmission $submission): array
    {
        $before = $this->load($approach);
        $old = $before['stages'][$assessment->stage->value];
        $after = $before['domain']->withApproval($assessment, $submission, $old['review_public_id']);
        $states = [];
        foreach (MaturityStage::cases() as $stage) {
            $states[$stage->value] = $after->requiresRevalidation($stage) ? 'REVALIDATION_REQUIRED' : 'VALID';
        }
        $comparison = $assessment->snapshot()['selected_band_index'] <=> $old['selected_band_index'];

        return ['previous_assessment_public_id' => $old['assessment_public_id'], 'comparison' => match ($comparison) {
            -1 => 'LOWER', 0 => 'EQUAL', 1 => 'HIGHER',
        }, 'dependency_states' => $states];
    }

    private function assessment(Approach $approach, StageSubmission $submission, object $row): ApprovedStageAssessment
    {
        $proof = ReviewMaturityAccess::context();
        $review = DB::table('review_maturity_reviews')->where('organization_id', $proof['organization_id'])->where('fixture_id', $proof['fixture_id'])
            ->where('approach_id', $approach->id)->where('id', $row->review_id)->where('stage_id', $row->stage_id)
            ->where('submission_id', $row->submission_id)->where('state', 'approved')->lockForUpdate()->first() ?? throw new AuthorizationException;
        $reviewer = User::where('organization_id', $proof['organization_id'])->whereKey($review->reviewer_id)->lockForUpdate()->first() ?? throw new AuthorizationException;
        if (! hash_equals($submission->fingerprint(), $review->fingerprint) || $review->reason !== $row->reason) {
            throw new ConflictHttpException('لا يطابق القرار لقطة التقييم التاريخية.');
        }
        $opened = ReviewSnapshot::open($review->public_id, $submission->reviewTarget(), new DateTimeImmutable($review->created_at.' UTC'));
        $approved = ReviewWorkflow::decide($opened, $reviewer->public_id, $submission->publicId, ReviewState::Approved, $review->reason,
            new DateTimeImmutable($review->decided_at.' UTC'))->current;
        $snapshot = json_decode($row->snapshot_json, true, 64, JSON_THROW_ON_ERROR);
        $scale = new RatingScale(array_map(fn ($band) => new RatingBand($band['name'], $band['description'], $band['lower_bound'], $band['upper_bound']), $submission->snapshot()['configuration']['scale']));
        $value = new ApprovedStageAssessment($approach->public_id, $submission->stage(), $scale, $row->selected_band_index,
            $submission->snapshot()['answers'], $approved, submission: $submission);
        if ($snapshot != $value->snapshot()) {
            throw new ConflictHttpException('تغيرت لقطة التقييم المحفوظة.');
        }

        return $value;
    }

    private function dto(object $row, ApprovedStageAssessment $assessment, StageSubmission $submission, object $stage): array
    {
        return ['stage_public_id' => $stage->public_id, 'ordinal' => $stage->stage_ordinal, 'assessment_public_id' => $row->public_id,
            'review_public_id' => $assessment->review->publicId, 'submission_public_id' => $submission->publicId,
            'selected_band_index' => $assessment->snapshot()['selected_band_index'], 'selected_band_name' => $assessment->selectedBand()->name,
            'snapshot' => $assessment->snapshot(), 'configuration' => $submission->snapshot()['configuration']];
    }
}
