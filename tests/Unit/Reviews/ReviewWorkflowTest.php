<?php

namespace Tests\Unit\Reviews;

use App\Domain\Reviews\ReviewSnapshot;
use App\Domain\Reviews\ReviewState;
use App\Domain\Reviews\ReviewTarget;
use App\Domain\Reviews\ReviewTargetKind;
use App\Domain\Reviews\ReviewWorkflow;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReviewWorkflowTest extends TestCase
{
    private const ORG = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    private const TARGET = '01ARZ3NDEKTSV4RRFFQ69G5FAW';

    private const REVISION = '01ARZ3NDEKTSV4RRFFQ69G5FAX';

    private const AUTHOR = '01ARZ3NDEKTSV4RRFFQ69G5FAY';

    private const EDITOR = '01ARZ3NDEKTSV4RRFFQ69G5FAZ';

    private const REVIEWER = '01ARZ3NDEKTSV4RRFFQ69G5FB0';

    private const REVIEW = '01ARZ3NDEKTSV4RRFFQ69G5FB1';

    private const SECOND = '01ARZ3NDEKTSV4RRFFQ69G5FB2';

    private function target(string $revision = self::REVISION, string $body = 'first'): ReviewTarget
    {
        return new ReviewTarget(self::ORG, ReviewTargetKind::ApproachContent, self::TARGET, $revision,
            [self::AUTHOR, self::EDITOR], hash('sha256', $body));
    }

    private function time(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-10T08:00:00Z');
    }

    public function test_actual_reviewable_change_opens_once_and_same_content_emits_no_duplicate_signal(): void
    {
        $first = ReviewWorkflow::saved(null, $this->target(), self::REVIEW, $this->time());
        $this->assertTrue($first->opened);
        $this->assertSame(ReviewState::Pending, $first->current->state);
        $this->assertCount(1, $first->signals);
        $same = ReviewWorkflow::saved($first->current, $this->target(), self::SECOND, $this->time());
        $this->assertFalse($same->opened);
        $this->assertSame($first->current, $same->current);
        $this->assertSame([], $same->signals);
    }

    public function test_new_revision_supersedes_pending_without_mutating_the_prior_snapshot(): void
    {
        $first = ReviewSnapshot::open(self::REVIEW, $this->target(), $this->time());
        $next = ReviewWorkflow::saved($first, $this->target(self::SECOND, 'second'), self::SECOND, $this->time()->modify('+1 hour'));
        $this->assertSame(ReviewState::Pending, $first->state);
        $this->assertSame(ReviewState::Superseded, $next->previous->state);
        $this->assertSame(ReviewState::Pending, $next->current->state);
        $this->assertSame(self::REVIEW, $next->current->supersedes);
        $this->assertSame(self::SECOND, $next->current->target->revisionPublicId);
    }

    public static function authors(): array
    {
        return [[self::AUTHOR], [self::EDITOR]];
    }

    #[DataProvider('authors')]
    public function test_a_contributor_can_never_approve_their_own_target(string $actor): void
    {
        $review = ReviewSnapshot::open(self::REVIEW, $this->target(), $this->time());
        $this->expectException(DomainException::class);
        ReviewWorkflow::decide($review, $actor, self::REVISION, ReviewState::Approved, 'مراجعة', $this->time());
    }

    public function test_correction_then_new_revision_preserves_decision_and_approval_has_no_draft_fallback(): void
    {
        $review = ReviewSnapshot::open(self::REVIEW, $this->target(), $this->time());
        $correction = ReviewWorkflow::decide($review, self::REVIEWER, self::REVISION, ReviewState::ChangesRequested, 'استكمال الشاهد', $this->time());
        $next = ReviewWorkflow::saved($correction->current, $this->target(self::SECOND, 'corrected'), self::SECOND, $this->time()->modify('+1 day'));
        $this->assertSame(ReviewState::ChangesRequested, $correction->current->state);
        $this->assertSame('استكمال الشاهد', $correction->current->reason);
        $this->assertSame(ReviewState::Pending, $next->current->state);
        $approved = ReviewWorkflow::decide($next->current, self::REVIEWER, self::SECOND, ReviewState::Approved, null, $this->time()->modify('+1 day'));
        $this->assertSame(self::SECOND, $approved->current->approvedRevision());
        $later = ReviewWorkflow::saved($approved->current, $this->target(self::REVIEW, 'later draft'), self::REVISION, $this->time()->modify('+2 days'));
        $this->assertNull($later->current->approvedRevision());
        $this->assertSame(self::SECOND, $approved->current->approvedRevision());
    }

    public function test_stale_revision_cannot_be_approved_and_correction_requires_a_reason(): void
    {
        $review = ReviewSnapshot::open(self::REVIEW, $this->target(), $this->time());
        foreach ([[self::SECOND, ReviewState::Approved, null], [self::REVISION, ReviewState::ChangesRequested, '   ']] as [$revision, $state, $reason]) {
            try {
                ReviewWorkflow::decide($review, self::REVIEWER, $revision, $state, $reason, $this->time());
                $this->fail('Invalid decision accepted.');
            } catch (DomainException) {
                $this->assertSame(ReviewState::Pending, $review->state);
            }
        }
    }

    public function test_signal_has_stable_idempotence_key_and_no_private_content_or_recipient_guess(): void
    {
        $change = ReviewWorkflow::saved(null, $this->target(), self::REVIEW, $this->time());
        $signal = $change->signals[0];
        $this->assertSame($signal->key(), $change->signals[0]->key());
        $this->assertSame(['event', 'organization_public_id', 'review_public_id', 'target_kind', 'target_public_id', 'revision_public_id'], array_keys($signal->payload()));
        $this->assertStringNotContainsString('first', json_encode($signal->payload()));
        $this->assertArrayNotHasKey('recipient', $signal->payload());
    }

    public function test_new_review_cannot_predate_the_existing_decision(): void
    {
        $review = ReviewSnapshot::open(self::REVIEW, $this->target(), $this->time());
        $approved = $review->decide(self::REVIEWER, self::REVISION, ReviewState::Approved, null, $this->time()->modify('+1 day'));
        $this->expectException(DomainException::class);
        ReviewWorkflow::saved($approved, $this->target(self::SECOND, 'later content'), self::SECOND, $this->time());
    }

    public function test_foreign_tenant_or_mutated_immutable_revision_cannot_replace_review(): void
    {
        $review = ReviewSnapshot::open(self::REVIEW, $this->target(), $this->time());
        foreach ([new ReviewTarget(self::SECOND, ReviewTargetKind::ApproachContent, self::TARGET, self::REVISION, [self::AUTHOR], hash('sha256', 'foreign')),
            $this->target(self::REVISION, 'tampered')] as $target) {
            try {
                ReviewWorkflow::saved($review, $target, self::SECOND, $this->time());
                $this->fail('Invalid replacement accepted.');
            } catch (DomainException) {
                $this->assertSame(ReviewState::Pending, $review->state);
                $this->assertNull($review->approvedRevision());
            }
        }
    }

    public function test_an_approved_decision_cannot_be_replaced_in_place(): void
    {
        $approved = ReviewSnapshot::open(self::REVIEW, $this->target(), $this->time())
            ->decide(self::REVIEWER, self::REVISION, ReviewState::Approved, null, $this->time());
        $this->expectException(DomainException::class);
        $approved->decide(self::REVIEWER, self::REVISION, ReviewState::ChangesRequested, 'second decision', $this->time());
    }
}
