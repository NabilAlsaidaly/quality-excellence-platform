<?php

namespace App\Actions\Reviews;

use App\Actions\Maturity\SaveStageSubmission;
use App\Actions\Notifications\ReviewMaturityNotifications;
use App\Authorization\ReviewMaturityAccess;
use App\Domain\Reviews\ReviewTarget;
use App\Domain\Reviews\ReviewTargetKind;
use App\Models\Approach;
use App\Models\Organization;
use App\Models\ReviewMaturityReview;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class SynchronizeReview
{
    public function execute(User $actor, Approach $approach, string $kind, object $revision, ?int $stageId, string $requestKey): ReviewMaturityReview
    {
        $access = app(ReviewMaturityAccess::class);
        $access->authorize($actor, $kind === 'approach_content' ? 'edit_content' : 'answer_stage', $approach, $stageId);
        $scope = ReviewMaturityWorkspace::attributes($approach);
        $target = $this->target($approach, $kind, $revision, $stageId);
        $previous = ReviewMaturityReview::where('organization_id', $approach->organization_id)->where('fixture_id', $scope['fixture_id'])
            ->where('approach_id', $approach->id)->where('target_kind', $kind)->where('stage_id', $stageId)->orderByDesc('review_number')->lockForUpdate()->first();
        if ($previous !== null && hash_equals($previous->fingerprint, $target->fingerprint)) {
            return $previous;
        }
        if ($previous?->state === 'pending') {
            DB::table('review_maturity_reviews')->where('id', $previous->id)->where('state', 'pending')
                ->update(['state' => 'superseded', 'decided_at' => ReviewMaturityAccess::timestamp()]);
            app(RecordReviewMaturityAudit::class)->execute($actor, $approach, 'review_superseded', 'superseded:'.$previous->public_id, $stageId, $previous->id);
        }
        $id = DB::table('review_maturity_reviews')->insertGetId($scope + ['stage_id' => $stageId, 'target_kind' => $kind,
            'approach_revision_id' => $kind === 'approach_content' ? $revision->id : null,
            'submission_id' => $kind === 'maturity_submission' ? $revision->id : null,
            'review_number' => ($previous?->review_number ?? 0) + 1, 'fingerprint' => $target->fingerprint,
            'opened_by' => $actor->id, 'request_key' => $requestKey, 'state' => 'pending', 'supersedes_id' => $previous?->id]);
        $review = ReviewMaturityReview::findOrFail($id);
        app(RecordReviewMaturityAudit::class)->execute($actor, $approach, 'review_opened', 'opened:'.$review->public_id, $stageId, $review->id);
        app(ReviewMaturityNotifications::class)->deliver($actor, $approach, $review, 'review_opened');

        return $review;
    }

    /** Rebuild from the actual immutable SQL revision and server-resolved contributors. */
    public function target(Approach $approach, string $kind, object $revision, ?int $stageId): ReviewTarget
    {
        if ((int) $revision->organization_id !== (int) $approach->organization_id || (int) $revision->approach_id !== (int) $approach->id) {
            throw new AuthorizationException;
        }
        if ($kind === 'maturity_submission') {
            return app(SaveStageSubmission::class)->hydrate($approach, $revision)->reviewTarget();
        }
        $contributors = DB::table('approach_content_revisions')->where('organization_id', $approach->organization_id)->where('approach_id', $approach->id)
            ->orderBy('id')->lockForUpdate()->pluck('actor_id')->push($approach->created_by)->unique();
        $ids = User::where('organization_id', $approach->organization_id)->whereIn('id', $contributors)->orderBy('id')->lockForUpdate()->pluck('public_id')->all();

        return new ReviewTarget(Organization::findOrFail($approach->organization_id)->public_id, ReviewTargetKind::ApproachContent,
            $approach->public_id, $revision->public_id, $ids, hash('sha256', json_encode(['body' => $revision->body], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }
}
