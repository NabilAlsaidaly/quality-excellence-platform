<?php

namespace Tests\Unit\Maturity;

use App\Domain\Maturity\ApprovedStageAssessment;
use App\Domain\Maturity\InitialMaturityCycle;
use App\Domain\Maturity\MaturityStage;
use App\Domain\Maturity\RatingBand;
use App\Domain\Maturity\RatingScale;
use App\Domain\Reviews\ReviewSnapshot;
use App\Domain\Reviews\ReviewState;
use App\Domain\Reviews\ReviewTarget;
use App\Domain\Reviews\ReviewTargetKind;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

class InitialMaturityCycleTest extends TestCase
{
    private const ORG = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    private const APPROACH = '01ARZ3NDEKTSV4RRFFQ69G5FAW';

    private const AUTHOR = '01ARZ3NDEKTSV4RRFFQ69G5FAX';

    private const REVIEWER = '01ARZ3NDEKTSV4RRFFQ69G5FAY';

    private function scale(): RatingScale
    {
        return new RatingScale([new RatingBand('بداية', 'وصف تاريخي', 0, 30), new RatingBand('تقدم', 'وصف', 31, 70), new RatingBand('استقرار', 'وصف', 71, 100)]);
    }

    private function assessment(MaturityStage $stage, array $answers = ['answer' => 'نسخة الإجابة'], ?RatingScale $scale = null): ApprovedStageAssessment
    {
        $scale ??= $this->scale();
        $target = new ReviewTarget(self::ORG, ReviewTargetKind::MaturitySubmission, (string) Str::ulid(), (string) Str::ulid(), [self::AUTHOR],
            ApprovedStageAssessment::fingerprint(self::APPROACH, $stage, $scale, 0, $answers));
        $review = ReviewSnapshot::open((string) Str::ulid(), $target, new DateTimeImmutable('2026-11-10T08:00:00Z'))
            ->decide(self::REVIEWER, $target->revisionPublicId, ReviewState::Approved, 'تقييم مستقل', new DateTimeImmutable('2026-11-10T09:00:00Z'));

        return new ApprovedStageAssessment(self::APPROACH, $stage, $scale, 0, $answers, $review);
    }

    public function test_all_five_initial_stages_advance_only_with_independently_approved_manual_rating_even_lowest(): void
    {
        $cycle = InitialMaturityCycle::begin(self::ORG, self::APPROACH);
        foreach (MaturityStage::cases() as $stage) {
            $this->assertSame($stage, $cycle->nextStage());
            $previous = $cycle;
            $cycle = $cycle->withAssessment($this->assessment($stage));
            $this->assertNotSame($previous, $cycle);
            $this->assertSame('بداية', $cycle->assessment($stage)->selectedBand()->name);
        }
        $this->assertNull($cycle->nextStage());
        $this->assertCount(5, $cycle->history());
        $this->assertFalse(method_exists($cycle, 'overallScore'));
    }

    public function test_skipping_a_stage_or_reassessing_an_earlier_stage_cannot_change_history(): void
    {
        $cycle = InitialMaturityCycle::begin(self::ORG, self::APPROACH);
        try {
            $cycle->withAssessment($this->assessment(MaturityStage::System));
            $this->fail('Locked stage accepted.');
        } catch (DomainException) {
            $this->assertSame([], $cycle->history());
        }
        $cycle = $cycle->withAssessment($this->assessment(MaturityStage::Orientation));
        try {
            $cycle->withAssessment($this->assessment(MaturityStage::Orientation));
            $this->fail('Unratified reassessment effect invented.');
        } catch (DomainException) {
            $this->assertCount(1, $cycle->history());
            $this->assertSame(MaturityStage::System, $cycle->nextStage());
        }
    }

    public function test_snapshot_freezes_answers_and_scale_without_a_scale_placement_or_numeric_aggregate(): void
    {
        $answers = ['questions' => [['question' => 'نص أصلي', 'answer' => 'جواب أصلي', 'evidence_version' => (string) Str::ulid()]]];
        $scale = $this->scale();
        $assessment = $this->assessment(MaturityStage::Orientation, $answers, $scale);
        $answers['questions'][0]['answer'] = 'تم التعديل لاحقًا';
        $this->assertSame('جواب أصلي', $assessment->snapshot()['answers']['questions'][0]['answer']);
        $this->assertSame(0, $assessment->selectedBand()->lowerBound);
        $this->assertSame(30, $assessment->selectedBand()->upperBound);
        $this->assertArrayNotHasKey('score', $assessment->snapshot());
        $this->assertArrayNotHasKey('scale_organization_id', $assessment->snapshot());
        $this->assertCount(3, $assessment->snapshot()['scale']);
    }

    public function test_review_for_different_snapshot_or_unapproved_target_cannot_be_used(): void
    {
        $scale = $this->scale();
        $target = new ReviewTarget(self::ORG, ReviewTargetKind::MaturitySubmission, (string) Str::ulid(), (string) Str::ulid(), [self::AUTHOR], hash('sha256', 'another snapshot'));
        $review = ReviewSnapshot::open((string) Str::ulid(), $target, new DateTimeImmutable('2026-11-10T08:00:00Z'));
        $this->expectException(DomainException::class);
        new ApprovedStageAssessment(self::APPROACH, MaturityStage::Orientation, $scale, 0, ['answer' => 'unreviewed'], $review);
    }

    public function test_foreign_approach_assessment_cannot_advance_a_cycle(): void
    {
        $cycle = InitialMaturityCycle::begin(self::ORG, (string) Str::ulid());
        $this->expectException(DomainException::class);
        $cycle->withAssessment($this->assessment(MaturityStage::Orientation));
    }

    public function test_approved_review_of_different_answers_cannot_authorize_changed_snapshot(): void
    {
        $approved = $this->assessment(MaturityStage::Orientation);
        $this->expectException(DomainException::class);
        new ApprovedStageAssessment(self::APPROACH, MaturityStage::Orientation, $this->scale(), 0, ['answer' => 'unreviewed change'], $approved->review);
    }

    public function test_foreign_tenant_cycle_cannot_consume_an_approved_assessment(): void
    {
        $cycle = InitialMaturityCycle::begin((string) Str::ulid(), self::APPROACH);
        $this->expectException(DomainException::class);
        $cycle->withAssessment($this->assessment(MaturityStage::Orientation));
    }

    public function test_mutable_objects_cannot_be_smuggled_into_historical_answers(): void
    {
        $this->expectException(DomainException::class);
        ApprovedStageAssessment::fingerprint(self::APPROACH, MaturityStage::Orientation, $this->scale(), 0, ['answer' => new \stdClass]);
    }
}
