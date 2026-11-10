<?php

namespace App\Domain\Reviews;

use DateTimeImmutable;
use DomainException;

/** Qualifying-save transition algebra. It neither persists nor authorizes a user. */
final class ReviewWorkflow
{
    public static function saved(?ReviewSnapshot $latest, ReviewTarget $target, string $newReviewId, DateTimeImmutable $at): ReviewChange
    {
        if ($latest !== null) {
            if ($at < ($latest->decidedAt ?? $latest->openedAt)) {
                throw new ReviewConflict('لا يمكن أن تسبق المراجعة الجديدة آخر حدث في سجلها.');
            }
            if (! $latest->target->sameTarget($target)) {
                throw new DomainException('لا يجوز نقل المراجعة إلى مؤسسة أو هدف آخر.');
            }
            if (strcasecmp($latest->target->revisionPublicId, $target->revisionPublicId) === 0
                && ! hash_equals($latest->target->fingerprint, $target->fingerprint)) {
                throw new ReviewConflict('لا يمكن تغيير محتوى إصدار ثابت الهوية.');
            }
            if (hash_equals($latest->target->fingerprint, $target->fingerprint)) {
                return new ReviewChange($latest, null, false, []);
            }
        }
        $previous = $latest?->state === ReviewState::Pending ? $latest->superseded($at) : $latest;
        $current = ReviewSnapshot::open($newReviewId, $target, $at, $latest?->publicId);

        return new ReviewChange($current, $previous, true, [new ReviewSignal($current)]);
    }

    public static function decide(ReviewSnapshot $review, string $reviewer, string $expectedRevision, ReviewState $decision, ?string $reason, DateTimeImmutable $at): ReviewChange
    {
        $current = $review->decide($reviewer, $expectedRevision, $decision, $reason, $at);

        return new ReviewChange($current, $review, false, [new ReviewSignal($current)]);
    }
}
