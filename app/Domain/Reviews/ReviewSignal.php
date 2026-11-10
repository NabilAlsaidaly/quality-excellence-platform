<?php

namespace App\Domain\Reviews;

use DomainException;

/** Safe event intent for a future transactional outbox; no guessed recipient or content. */
final readonly class ReviewSignal
{
    public function __construct(private ReviewSnapshot $review)
    {
        if ($review->state === ReviewState::Superseded) {
            throw new DomainException('إشارة المراجعة غير مدعومة.');
        }
    }

    public function payload(): array
    {
        return ['event' => match ($this->review->state) {
            ReviewState::Pending => 'review_opened', ReviewState::ChangesRequested => 'changes_requested',
            ReviewState::Approved => 'approved', default => throw new DomainException('إشارة غير صالحة.'),
        }, 'organization_public_id' => $this->review->target->organizationPublicId,
            'review_public_id' => $this->review->publicId, 'target_kind' => $this->review->target->kind->value,
            'target_public_id' => $this->review->target->targetPublicId, 'revision_public_id' => $this->review->target->revisionPublicId];
    }

    public function key(): string
    {
        return hash('sha256', json_encode($this->payload(), JSON_THROW_ON_ERROR));
    }
}
