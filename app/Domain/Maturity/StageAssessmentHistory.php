<?php

namespace App\Domain\Maturity;

use App\Domain\Reviews\ReviewConflict;
use DomainException;
use Illuminate\Support\Str;

/**
 * Append-only recorded history over the existing approval contract.
 * Does not authorize, persist, select the currently effective assessment or apply D014 consequences.
 */
final readonly class StageAssessmentHistory
{
    private function __construct(private array $records) {}

    public static function fromInitial(string $assessmentPublicId, ApprovedStageAssessment $assessment): self
    {
        if (! Str::isUlid($assessmentPublicId)) {
            throw new DomainException('هوية التقييم التاريخي غير صالحة.');
        }

        return new self([['assessment_public_id' => strtolower($assessmentPublicId), 'attempt_public_id' => null,
            'previous_assessment_public_id' => null, 'assessment' => $assessment]]);
    }

    public function withReassessment(string $attemptPublicId, string $expectedPreviousAssessmentPublicId,
        string $assessmentPublicId, ApprovedStageAssessment $assessment): self
    {
        foreach ([$attemptPublicId, $expectedPreviousAssessmentPublicId, $assessmentPublicId] as $id) {
            if (! Str::isUlid($id)) {
                throw new DomainException('مرجع إعادة التقييم غير صالح.');
            }
        }
        $attempt = strtolower($attemptPublicId);
        $previous = strtolower($expectedPreviousAssessmentPublicId);
        $id = strtolower($assessmentPublicId);
        foreach ($this->records as $record) {
            if ($record['attempt_public_id'] === $attempt) {
                if ($record['assessment_public_id'] === $id && $record['previous_assessment_public_id'] === $previous
                    && $record['assessment']->snapshot() === $assessment->snapshot() && $record['assessment']->review == $assessment->review) {
                    return $this;
                }
                throw new ReviewConflict('أعيد استخدام معرّف المحاولة لقرار مختلف.');
            }
        }
        $latest = $this->records[array_key_last($this->records)];
        $old = $latest['assessment'];
        if ($previous !== $latest['assessment_public_id']) {
            throw new ReviewConflict('تغير آخر تقييم مسجل؛ أعد فتح التاريخ الحالي.');
        }
        if (strcasecmp($old->approachPublicId, $assessment->approachPublicId) !== 0 || $old->stage !== $assessment->stage
            || ! $old->review->target->sameTarget($assessment->review->target)) {
            throw new DomainException('لا يمكن خلط تقييمات مؤسسات أو منهجيات أو مراحل مختلفة.');
        }
        foreach ($this->records as $record) {
            if ($record['assessment_public_id'] === $id || $record['assessment']->review->publicId === $assessment->review->publicId
                || strcasecmp($record['assessment']->review->target->revisionPublicId, $assessment->review->target->revisionPublicId) === 0) {
                throw new ReviewConflict('يلزم إصدار ومراجعة مستقلان لإعادة التقييم.');
            }
        }
        if ($assessment->review->decidedAt < $old->review->decidedAt) {
            throw new ReviewConflict('لا يجوز إلحاق قرار أقدم بالتاريخ الحالي.');
        }

        return new self([...$this->records, ['assessment_public_id' => $id, 'attempt_public_id' => $attempt,
            'previous_assessment_public_id' => $previous, 'assessment' => $assessment]]);
    }

    public function latestRecordedAssessment(): ApprovedStageAssessment
    {
        return $this->records[array_key_last($this->records)]['assessment'];
    }

    public function records(): array
    {
        return $this->records;
    }

    public function compare(string $previousId, string $nextId): array
    {
        $lookup = array_column($this->records, 'assessment', 'assessment_public_id');
        if (! isset($lookup[strtolower($previousId)], $lookup[strtolower($nextId)])) {
            throw new DomainException('لا ينتمي التقييم المطلوب إلى هذا التاريخ.');
        }

        return ['previous' => $lookup[strtolower($previousId)]->snapshot(), 'next' => $lookup[strtolower($nextId)]->snapshot()];
    }

    /** Dependency candidates only. No validity flag, stage lock, rerating, notification or reset is inferred. */
    public function downstreamReviewIds(InitialMaturityCycle $cycle): array
    {
        $assessment = $this->latestRecordedAssessment();
        if (strcasecmp($cycle->organizationPublicId, $assessment->review->target->organizationPublicId) !== 0
            || strcasecmp($cycle->approachPublicId, $assessment->approachPublicId) !== 0) {
            throw new DomainException('الدورة لا تنتمي إلى سياق التقييم.');
        }
        $index = array_search($assessment->stage, MaturityStage::cases(), true);

        return array_values(array_map(fn ($row) => $row->review->publicId,
            array_filter($cycle->history(), fn ($row) => array_search($row->stage, MaturityStage::cases(), true) > $index)));
    }
}
