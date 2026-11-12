<?php

namespace App\Actions\Reviews;

use App\Actions\Maturity\RecordInitialStageAssessment;
use App\Actions\Notifications\ReviewMaturityNotifications;
use App\Authorization\ReviewMaturityAccess;
use App\Domain\Reviews\ReviewSnapshot;
use App\Domain\Reviews\ReviewState;
use App\Models\Approach;
use App\Models\ReviewMaturityReview;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class DecideReview
{
    public function execute(string $publicId, string $expectedFingerprint, string $decision, ?string $reason,
        string $requestKey, ?int $bandIndex = null, ?string $notes = null): ReviewMaturityReview
    {
        $proof = ReviewMaturityAccess::context() ?? throw new AuthorizationException;
        $candidate = Str::isUlid($publicId) ? ReviewMaturityReview::where('organization_id', $proof['organization_id'])->where('fixture_id', $proof['fixture_id'])
            ->where('approach_id', $proof['approach_id'])->where('public_id', $publicId)->firstOrFail() : throw new AuthorizationException;
        $approachId = Approach::where('organization_id', $proof['organization_id'])->whereKey($proof['approach_id'])->value('public_id');
        $operation = $candidate->target_kind === 'approach_content' ? 'review_content' : 'review_stage';

        return app(ReviewMaturityAccess::class)->within($operation, $approachId, $candidate->stage_id,
            function (User $actor, Approach $approach) use ($candidate, $expectedFingerprint, $decision, $reason, $requestKey, $bandIndex, $notes, $operation): ReviewMaturityReview {
                if (! Str::isUlid($requestKey) || ! in_array($decision, ['approved', 'changes_requested'], true)) {
                    throw new \DomainException('قرار المراجعة غير صالح.');
                }
                $review = ReviewMaturityReview::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                if ($review->submission_id !== null && DB::table('maturity_reassessment_events')
                    ->where('organization_id', $review->organization_id)->where('fixture_id', $review->fixture_id)
                    ->where('approach_id', $approach->id)->where('stage_id', $review->stage_id)
                    ->where('submission_id', $review->submission_id)->where('event', 'saved')->lockForUpdate()->exists()) {
                    // Initial review_stage authority never substitutes for the
                    // separate reassessment admission and immutable lineage.
                    throw new AuthorizationException;
                }
                $decisionHash = hash('sha256', json_encode([$expectedFingerprint, $decision, $reason, $bandIndex, $notes], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                if ($review->decision_key === $requestKey && $review->decision_fingerprint === $decisionHash && (int) $review->reviewer_id === (int) $actor->id) {
                    return $review;
                }
                if ($review->state !== 'pending' || ! hash_equals($review->fingerprint, $expectedFingerprint)) {
                    throw new ConflictHttpException('تغيرت المراجعة؛ أعد فتح الإصدار الحالي.');
                }
                $table = $review->target_kind === 'approach_content' ? 'approach_content_revisions' : 'maturity_workflow_submissions';
                $revision = DB::table($table)->where('organization_id', $approach->organization_id)->where('approach_id', $approach->id)
                    ->when($review->stage_id !== null, fn ($query) => $query->where('stage_id', $review->stage_id))
                    ->orderByDesc('version_number')->lockForUpdate()->first();
                $expectedId = $review->approach_revision_id ?? $review->submission_id;
                if ($revision === null || (int) $revision->id !== (int) $expectedId) {
                    throw new ConflictHttpException('ظهرت مسودة أحدث.');
                }
                $target = app(SynchronizeReview::class)->target($approach, $review->target_kind, $revision, $review->stage_id);
                if (! hash_equals($target->fingerprint, $review->fingerprint)) {
                    throw new ConflictHttpException('لا تطابق اللقطة المحفوظة سند المراجعة.');
                }
                $domain = ReviewSnapshot::open($review->public_id, $target, new DateTimeImmutable($review->created_at.' UTC'))
                    ->decide($actor->public_id, $target->revisionPublicId, ReviewState::from($decision), $reason, new DateTimeImmutable(ReviewMaturityAccess::timestamp().' UTC'));
                $assignment = app(ReviewMaturityAccess::class)->current($actor, $operation, $review->stage_id) ?? throw new AuthorizationException;
                DB::table('review_maturity_reviews')->where('id', $review->id)->where('state', 'pending')->update([
                    'state' => $decision, 'reviewer_id' => $actor->id, 'decided_at' => ReviewMaturityAccess::timestamp(),
                    'decision_assignment_id' => $assignment->id, 'decision_key' => $requestKey, 'decision_fingerprint' => $decisionHash, 'reason' => $reason]);
                $review->refresh();
                if ($review->target_kind === 'maturity_submission' && $decision === 'approved') {
                    app(RecordInitialStageAssessment::class)->execute($actor, $approach, $review, $revision, $domain, $bandIndex, $reason, $notes);
                }
                app(RecordReviewMaturityAudit::class)->execute($actor, $approach, $decision === 'approved' ? 'review_approved' : 'changes_requested',
                    'decided:'.$review->public_id, $review->stage_id, $review->id);
                app(ReviewMaturityNotifications::class)->deliver($actor, $approach, $review, $decision);

                return $review;
            });
    }
}
