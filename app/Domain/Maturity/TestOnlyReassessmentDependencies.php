<?php

namespace App\Domain\Maturity;

use App\Domain\Reviews\ReviewConflict;
use DomainException;

/**
 * Explicit opt-in calculation for the Lead's bounded D014 fixture decision.
 * No Policy, database mutation, operational grant or initial-cycle replacement.
 * This slice consumes the existing complete five-stage acceptance fixture only.
 */
final readonly class TestOnlyReassessmentDependencies
{
    private function __construct(private array $records, private array $requiresRevalidation) {}

    /** @param array<string,StageSubmission> $submissions Exact historically approved inputs, not editable settings. */
    public static function fromInitial(InitialMaturityCycle $cycle, array $submissions): self
    {
        if (count($cycle->history()) !== count(MaturityStage::cases()) || count($submissions) !== count(MaturityStage::cases())) {
            throw new DomainException('تتطلب نواة التحقق الاختبارية تاريخ المراحل الخمس كاملًا؛ لم تتغير أي دورة جزئية.');
        }
        $records = [];
        $required = [];
        foreach ($cycle->history() as $assessment) {
            $stage = $assessment->stage->value;
            $submission = $submissions[$stage] ?? null;
            if (! $submission instanceof StageSubmission) {
                throw new DomainException('لقطة الإجابات التاريخية غير متاحة.');
            }
            self::assertBinding($assessment, $submission);
            $records[$stage] = ['assessment' => $assessment, 'submission' => $submission];
            $required[$stage] = false;
        }

        return new self($records, $required);
    }

    public function requiresRevalidation(MaturityStage $stage): bool
    {
        return $this->requiresRevalidation[$stage->value];
    }

    /** Own historical flag does not forbid sequential revalidation after all earlier dependencies are valid. */
    public function mayReassess(MaturityStage $stage): bool
    {
        foreach (MaturityStage::cases() as $earlier) {
            if ($earlier === $stage) {
                return true;
            }
            if ($this->requiresRevalidation[$earlier->value]) {
                return false;
            }
        }

        return false;
    }

    public function withApproval(ApprovedStageAssessment $assessment, StageSubmission $submission, string $expectedPreviousReview): self
    {
        $stage = $assessment->stage;
        $previous = $this->records[$stage->value];
        $old = $previous['assessment'];
        if (strcasecmp($old->review->publicId, $expectedPreviousReview) !== 0) {
            throw new ReviewConflict('تغير سند التقييم السابق؛ أعد فتح التاريخ الحالي.');
        }
        self::assertBinding($assessment, $submission);
        if (! $old->review->target->sameTarget($assessment->review->target)
            || strcasecmp($old->approachPublicId, $assessment->approachPublicId) !== 0
            || $previous['submission']->snapshot()['configuration'] !== $submission->snapshot()['configuration']) {
            throw new DomainException('يلزم سياق المرحلة نفسه ونسخة الأسئلة والسلم المعتمدة نفسها.');
        }
        if (! $this->mayReassess($stage)) {
            throw new DomainException('أعد التحقق من اعتماد المرحلة السابقة أولًا.');
        }
        if ($old->review->publicId === $assessment->review->publicId
            || $old->review->target->revisionPublicId === $assessment->review->target->revisionPublicId
            || $assessment->review->decidedAt < $old->review->decidedAt) {
            throw new ReviewConflict('يلزم إصدار وقرار مستقلان وحديثان.');
        }
        $oldSnapshot = $old->snapshot();
        $newSnapshot = $assessment->snapshot();
        if ($oldSnapshot['scale'] !== $newSnapshot['scale']) {
            throw new DomainException('تعذر إثبات مقارنة الترتيب ضمن السلم التاريخي نفسه.');
        }
        $previousLower = null;
        foreach ($oldSnapshot['scale'] as $band) {
            if ($previousLower !== null && $band['lower_bound'] <= $previousLower) {
                // Do not sort/rewrite history or guess the meaning of a conflicting category order.
                throw new DomainException('تعذر إثبات ترتيب التصنيفات التاريخي؛ يلزم قرار محدد قبل المقارنة.');
            }
            $previousLower = $band['lower_bound'];
        }
        $requires = $this->requiresRevalidation;
        $requires[$stage->value] = false;
        if ($newSnapshot['selected_band_index'] < $oldSnapshot['selected_band_index']) {
            $after = false;
            foreach (MaturityStage::cases() as $candidate) {
                if ($after) {
                    $requires[$candidate->value] = true;
                }
                if ($candidate === $stage) {
                    $after = true;
                }
            }
        }
        $records = $this->records;
        $records[$stage->value] = ['assessment' => $assessment, 'submission' => $submission];

        return new self($records, $requires);
    }

    private static function assertBinding(ApprovedStageAssessment $assessment, StageSubmission $submission): void
    {
        $submission->assertComplete();
        $target = $submission->reviewTarget();
        $snapshot = $submission->snapshot();
        if ($assessment->stage !== $submission->stage() || strcasecmp($assessment->approachPublicId, $submission->approachPublicId) !== 0
            || ! $assessment->review->target->sameTarget($target)
            || strcasecmp($assessment->review->target->revisionPublicId, $target->revisionPublicId) !== 0
            || ! hash_equals($assessment->review->target->fingerprint, $submission->fingerprint())
            || $assessment->snapshot()['answers'] !== $snapshot['answers']
            || $assessment->snapshot()['scale'] !== $snapshot['configuration']['scale']) {
            throw new DomainException('لا يطابق قرار التقييم لقطة الإجابات والإعدادات المعتمدة.');
        }
    }
}
