<?php

namespace App\Domain\Reviews;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Str;

/** Domain transition proof only; real Policy, transaction and persistence remain mandatory. */
final readonly class ReviewSnapshot
{
    private function __construct(
        public string $publicId,
        public ReviewTarget $target,
        public ReviewState $state,
        public DateTimeImmutable $openedAt,
        public ?DateTimeImmutable $decidedAt = null,
        public ?string $reviewerPublicId = null,
        public ?string $reason = null,
        public ?string $supersedes = null,
    ) {}

    public static function open(string $publicId, ReviewTarget $target, DateTimeImmutable $at, ?string $supersedes = null): self
    {
        if (! Str::isUlid($publicId) || ($supersedes !== null && (! Str::isUlid($supersedes) || strcasecmp($publicId, $supersedes) === 0))) {
            throw new DomainException('هوية دورة المراجعة غير صالحة.');
        }

        return new self($publicId, $target, ReviewState::Pending, $at->setTimezone(new DateTimeZone('UTC')), supersedes: $supersedes);
    }

    public function decide(string $reviewer, string $expectedRevision, ReviewState $decision, ?string $reason, DateTimeImmutable $at): self
    {
        if ($this->state !== ReviewState::Pending || strcasecmp($this->target->revisionPublicId, $expectedRevision) !== 0) {
            throw new ReviewConflict('تغير الإصدار أو حُسمت المراجعة؛ أعد فتح السجل الحالي.');
        }
        if (! Str::isUlid($reviewer) || in_array(strtolower($reviewer), $this->target->contributorPublicIds, true)) {
            throw new DomainException('لا يجوز اعتماد أو مراجعة مساهمة المستخدم نفسه.');
        }
        if (! in_array($decision, [ReviewState::Approved, ReviewState::ChangesRequested], true) || $at < $this->openedAt
            || ($reason !== null && (! mb_check_encoding($reason, 'UTF-8') || mb_strlen($reason) > 65535))
            || ($decision === ReviewState::ChangesRequested && ($reason === null || preg_match('/[^\p{Z}\s]/u', $reason) !== 1))) {
            throw new DomainException('قرار المراجعة أو سبب طلب التصحيح غير صالح.');
        }

        return new self($this->publicId, $this->target, $decision, $this->openedAt,
            $at->setTimezone(new DateTimeZone('UTC')), $reviewer, $reason, $this->supersedes);
    }

    public function superseded(DateTimeImmutable $at): self
    {
        if ($this->state !== ReviewState::Pending || $at < $this->openedAt) {
            throw new ReviewConflict('لا يمكن استبدال قرار مراجعة تاريخي.');
        }

        return new self($this->publicId, $this->target, ReviewState::Superseded, $this->openedAt,
            $at->setTimezone(new DateTimeZone('UTC')), supersedes: $this->supersedes);
    }

    public function approvedRevision(): ?string
    {
        return $this->state === ReviewState::Approved ? $this->target->revisionPublicId : null;
    }
}
