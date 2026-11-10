<?php

namespace App\Actions\Maturity;

use App\Actions\Reviews\RecordReviewMaturityAudit;
use App\Actions\Reviews\ReviewMaturityWorkspace;
use App\Authorization\ReviewMaturityAccess;
use App\Domain\Maturity\ApprovedStageAssessment;
use App\Domain\Maturity\MaturityStage;
use App\Domain\Maturity\RatingBand;
use App\Domain\Maturity\RatingScale;
use App\Domain\Reviews\ReviewSnapshot;
use App\Models\Approach;
use App\Models\ReviewMaturityReview;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class RecordInitialStageAssessment
{
    public function execute(User $actor, Approach $approach, ReviewMaturityReview $review, object $revision, ReviewSnapshot $domain,
        ?int $bandIndex, ?string $reason, ?string $notes): void
    {
        app(ReviewMaturityAccess::class)->authorize($actor, 'review_stage', $approach, $review->stage_id);
        $scope = ReviewMaturityWorkspace::attributes($approach);
        $stage = DB::table('maturity_workflow_stages')->where('organization_id', $approach->organization_id)->where('fixture_id', $scope['fixture_id'])
            ->where('approach_id', $approach->id)->where('id', $review->stage_id)->lockForUpdate()->first();
        $writer = app(SaveStageSubmission::class);
        $writer->assertInitialStage($approach, $stage);
        $currentConfig = DB::table('maturity_workflow_configurations')->where('organization_id', $approach->organization_id)->where('fixture_id', $scope['fixture_id'])
            ->where('approach_id', $approach->id)->where('stage_id', $stage->id)->orderByDesc('version_number')->lockForUpdate()->first();
        if ($currentConfig === null || (int) $currentConfig->id !== (int) $revision->configuration_id) {
            throw new ConflictHttpException('تغير إعداد المرحلة بعد حفظ الإجابات.');
        }
        $submission = $writer->hydrate($approach, $revision);
        $submission->assertComplete();
        $writer->verifyEvidence($actor, $submission);
        if ($bandIndex === null || $reason === null || ! mb_check_encoding($reason, 'UTF-8') || mb_strlen($reason) > 65535
            || preg_match('/[^\p{Z}\s]/u', $reason) !== 1 || ($notes !== null && (! mb_check_encoding($notes, 'UTF-8') || mb_strlen($notes) > 65535))) {
            throw new DomainException('اختر حالة تقييم يدويًا مع تعليل واضح.');
        }
        $snapshot = $submission->snapshot();
        $scale = new RatingScale(array_map(fn ($band) => new RatingBand($band['name'], $band['description'], $band['lower_bound'], $band['upper_bound']), $snapshot['configuration']['scale']));
        $value = new ApprovedStageAssessment($approach->public_id, MaturityStage::from($stage->stage), $scale, $bandIndex, $snapshot['answers'], $domain, submission: $submission);
        $previous = DB::table('maturity_workflow_assessments')->where('organization_id', $approach->organization_id)->where('fixture_id', $scope['fixture_id'])
            ->where('approach_id', $approach->id)->orderByDesc('stage_ordinal')->lockForUpdate()->first();
        DB::table('maturity_workflow_assessments')->insert($scope + ['stage_id' => $stage->id, 'stage_ordinal' => $stage->stage_ordinal,
            'submission_id' => $revision->id, 'review_id' => $review->id, 'reviewer_id' => $actor->id, 'previous_assessment_id' => $previous?->id,
            'selected_band_index' => $bandIndex, 'snapshot_json' => json_encode($value->snapshot(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
            'reason' => $reason, 'notes' => $notes]);
        app(RecordReviewMaturityAudit::class)->execute($actor, $approach, 'assessment_recorded', 'assessment:'.$review->public_id, $stage->id, $review->id);
    }
}
