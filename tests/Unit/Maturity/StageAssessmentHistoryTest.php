<?php

namespace Tests\Unit\Maturity;

use App\Domain\Maturity\ApprovedStageAssessment;
use App\Domain\Maturity\InitialMaturityCycle;
use App\Domain\Maturity\MaturityStage;
use App\Domain\Maturity\RatingBand;
use App\Domain\Maturity\RatingScale;
use App\Domain\Maturity\StageAssessmentHistory;
use App\Domain\Reviews\ReviewConflict;
use App\Domain\Reviews\ReviewSnapshot;
use App\Domain\Reviews\ReviewState;
use App\Domain\Reviews\ReviewTarget;
use App\Domain\Reviews\ReviewTargetKind;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

class StageAssessmentHistoryTest extends TestCase
{
    private function id(): string
    {
        return strtolower((string) Str::ulid());
    }

    private function approved(string $org, string $approach, MaturityStage $stage, string $target, int $band, int $minute): ApprovedStageAssessment
    {
        $scale = new RatingScale([new RatingBand('A', 'test', 0, 30), new RatingBand('B', 'test', 31, 100)]);
        $answers = ['answer' => 'snapshot '.$minute];
        $fingerprint = ApprovedStageAssessment::fingerprint($approach, $stage, $scale, $band, $answers);
        $review = ReviewSnapshot::open($this->id(), new ReviewTarget($org, ReviewTargetKind::MaturitySubmission,
            $target, $this->id(), [$this->id()], $fingerprint), new DateTimeImmutable('2026-11-11T00:00:00Z'));
        $review = $review->decide($this->id(), $review->target->revisionPublicId, ReviewState::Approved,
            'Independent test decision', new DateTimeImmutable('2026-11-11T00:'.sprintf('%02d', $minute).':00Z'));

        return new ApprovedStageAssessment($approach, $stage, $scale, $band, $answers, $review);
    }

    public function test_new_record_and_comparison_preserve_original_without_effective_status_or_downstream_policy(): void
    {
        $org = $this->id();
        $approach = $this->id();
        $target = $this->id();
        $initial = $this->approved($org, $approach, MaturityStage::Orientation, $target, 1, 1);
        $next = $this->approved($org, $approach, MaturityStage::Orientation, $target, 0, 2);
        $history = StageAssessmentHistory::fromInitial($initial->review->publicId, $initial);
        $recorded = $history->withReassessment($this->id(), $initial->review->publicId, $next->review->publicId, $next);
        $this->assertCount(1, $history->records());
        $this->assertCount(2, $recorded->records());
        $this->assertSame($initial->snapshot(), $recorded->records()[0]['assessment']->snapshot());
        $this->assertSame($next, $recorded->latestRecordedAssessment());
        $this->assertSame($initial->review->publicId, $recorded->records()[1]['previous_assessment_public_id']);
        $this->assertSame($initial->snapshot(), $recorded->compare($initial->review->publicId, $next->review->publicId)['previous']);
        $this->assertArrayNotHasKey('effective_status', $recorded->compare($initial->review->publicId, $next->review->publicId));
        $this->assertArrayNotHasKey('score', $recorded->compare($initial->review->publicId, $next->review->publicId));
    }

    public function test_exact_retry_returns_same_history_but_stale_previous_is_rejected(): void
    {
        $org = $this->id();
        $approach = $this->id();
        $target = $this->id();
        $attempt = $this->id();
        $initial = $this->approved($org, $approach, MaturityStage::Orientation, $target, 0, 1);
        $next = $this->approved($org, $approach, MaturityStage::Orientation, $target, 1, 2);
        $history = StageAssessmentHistory::fromInitial($initial->review->publicId, $initial)->withReassessment($attempt, $initial->review->publicId, $next->review->publicId, $next);
        $this->assertSame($history, $history->withReassessment($attempt, $initial->review->publicId, $next->review->publicId, $next));
        $this->expectException(ReviewConflict::class);
        $history->withReassessment($this->id(), $initial->review->publicId, $this->id(), $this->approved($org, $approach, MaturityStage::Orientation, $target, 0, 3));
    }

    public function test_cross_tenant_or_wrong_stage_assessment_cannot_enter_history(): void
    {
        $org = $this->id();
        $approach = $this->id();
        $target = $this->id();
        $initial = $this->approved($org, $approach, MaturityStage::Orientation, $target, 0, 1);
        $history = StageAssessmentHistory::fromInitial($initial->review->publicId, $initial);
        foreach ([$this->approved($this->id(), $approach, MaturityStage::Orientation, $target, 1, 2),
            $this->approved($org, $approach, MaturityStage::System, $target, 1, 2),
            $this->approved($org, $this->id(), MaturityStage::Orientation, $target, 1, 2),
            $this->approved($org, $approach, MaturityStage::Orientation, $this->id(), 1, 2)] as $invalid) {
            try {
                $history->withReassessment($this->id(), $initial->review->publicId, $invalid->review->publicId, $invalid);
                $this->fail('Foreign assessment accepted.');
            } catch (DomainException) {
                $this->assertCount(1, $history->records());
            }
        }
    }

    public function test_dependency_inspection_reports_ids_without_resetting_or_rerating_later_assessments(): void
    {
        $org = $this->id();
        $approach = $this->id();
        $cycle = InitialMaturityCycle::begin($org, $approach);
        $initial = [];
        foreach (MaturityStage::cases() as $i => $stage) {
            $initial[] = $assessment = $this->approved($org, $approach, $stage, $this->id(), 0, $i + 1);
            $cycle = $cycle->withAssessment($assessment);
        }
        $history = StageAssessmentHistory::fromInitial($initial[1]->review->publicId, $initial[1]);
        $this->assertSame(array_map(fn ($a) => $a->review->publicId, array_slice($initial, 2)), $history->downstreamReviewIds($cycle));
        $this->assertCount(5, $cycle->history());
        $this->assertNull($cycle->nextStage());
    }

    public function test_reusing_an_attempt_for_a_different_approval_is_a_conflict(): void
    {
        $org = $this->id();
        $approach = $this->id();
        $target = $this->id();
        $attempt = $this->id();
        $first = $this->approved($org, $approach, MaturityStage::Orientation, $target, 0, 1);
        $second = $this->approved($org, $approach, MaturityStage::Orientation, $target, 1, 2);
        $history = StageAssessmentHistory::fromInitial($first->review->publicId, $first)
            ->withReassessment($attempt, $first->review->publicId, $second->review->publicId, $second);
        $this->expectException(ReviewConflict::class);
        $history->withReassessment($attempt, $first->review->publicId, $this->id(), $this->approved($org, $approach, MaturityStage::Orientation, $target, 0, 3));
    }

    public function test_an_unrelated_cycle_cannot_be_used_to_infer_dependencies(): void
    {
        $assessment = $this->approved($this->id(), $this->id(), MaturityStage::Orientation, $this->id(), 0, 1);
        $history = StageAssessmentHistory::fromInitial($this->id(), $assessment);
        $this->expectException(DomainException::class);
        $history->downstreamReviewIds(InitialMaturityCycle::begin($this->id(), $this->id()));
    }
}
