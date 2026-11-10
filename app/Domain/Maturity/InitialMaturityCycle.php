<?php

namespace App\Domain\Maturity;

use DomainException;
use Illuminate\Support\Str;

/** Initial sequence only. Database binding, Policies and reassessment remain separate gates. */
final readonly class InitialMaturityCycle
{
    /** @param list<ApprovedStageAssessment> $assessments */
    private function __construct(
        public string $organizationPublicId,
        public string $approachPublicId,
        private array $assessments = [],
    ) {}

    public static function begin(string $organizationPublicId, string $approachPublicId): self
    {
        if (! Str::isUlid($organizationPublicId) || ! Str::isUlid($approachPublicId)) {
            throw new DomainException('هوية المؤسسة أو المنهجية غير صالحة.');
        }

        return new self($organizationPublicId, $approachPublicId);
    }

    public function nextStage(): ?MaturityStage
    {
        return MaturityStage::cases()[count($this->assessments)] ?? null;
    }

    public function withAssessment(ApprovedStageAssessment $assessment): self
    {
        if (strcasecmp($this->organizationPublicId, $assessment->review->target->organizationPublicId) !== 0
            || strcasecmp($this->approachPublicId, $assessment->approachPublicId) !== 0
            || $assessment->stage !== $this->nextStage()) {
            throw new DomainException('لا يمكن تجاوز ترتيب الدورة الأولية أو استبدال تقييم تاريخي.');
        }

        return new self($this->organizationPublicId, $this->approachPublicId, [...$this->assessments, $assessment]);
    }

    public function assessment(MaturityStage $stage): ?ApprovedStageAssessment
    {
        foreach ($this->assessments as $assessment) {
            if ($assessment->stage === $stage) {
                return $assessment;
            }
        }

        return null;
    }

    /** @return list<ApprovedStageAssessment> */
    public function history(): array
    {
        return $this->assessments;
    }
}
