<?php

namespace App\Actions\Notifications;

use App\Actions\Reviews\ReviewMaturityWorkspace;
use App\Authorization\ReviewMaturityAccess;
use App\Models\Approach;
use App\Models\OrganizationMembership;
use App\Models\ReviewMaturityReview;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** Delivery store and origin share a SQL transaction; no afterCommit crash-loss window. */
final class ReviewMaturityNotifications
{
    public function deliver(User $actor, Approach $approach, ReviewMaturityReview $review, string $event, ?object $reassessment = null): void
    {
        $proof = ReviewMaturityAccess::context() ?? throw new AuthorizationException;
        $scope = ReviewMaturityWorkspace::attributes($approach);
        $persisted = ReviewMaturityReview::where('organization_id', $proof['organization_id'])->where('fixture_id', $proof['fixture_id'])
            ->where('approach_id', $approach->id)->whereKey($review->id)->lockForUpdate()->first();
        if ($persisted === null || ($event === 'review_opened' ? ($persisted->state !== 'pending' || (int) $persisted->opened_by !== (int) $actor->id)
            : ($persisted->state !== $event || (int) $persisted->reviewer_id !== (int) $actor->id))) {
            throw new AuthorizationException;
        }
        $review = $persisted;
        if ($reassessment !== null) {
            if (! ReviewMaturityAccess::hasReassessmentScope() || (int) $reassessment->organization_id !== $proof['organization_id']
                || $reassessment->fixture_id !== $proof['fixture_id'] || (int) $reassessment->approach_id !== (int) $approach->id
                || (int) $reassessment->stage_id !== (int) $review->stage_id) {
                throw new AuthorizationException;
            }
            $saved = DB::table('maturity_reassessment_events')->where('organization_id', $proof['organization_id'])->where('fixture_id', $proof['fixture_id'])
                ->where('approach_id', $approach->id)->where('stage_id', $review->stage_id)->where('attempt_id', $reassessment->id)
                ->where('submission_id', $review->submission_id)->where('event', 'saved')->lockForUpdate()->first();
            if ($saved === null || (json_decode($saved->details_json, true, 64, JSON_THROW_ON_ERROR)['review_public_id'] ?? null) !== $review->public_id) {
                throw new AuthorizationException;
            }
        }
        $actorOperation = $reassessment === null ? ($event === 'review_opened'
            ? ($review->stage_id === null ? 'edit_content' : 'answer_stage') : ($review->stage_id === null ? 'review_content' : 'review_stage'))
            : ($event === 'review_opened' ? 'save_reassessment' : 'review_reassessment');
        app(ReviewMaturityAccess::class)->authorize($actor, $actorOperation, $approach, $review->stage_id);
        $recipientId = $event === 'review_opened' ? $proof['reviewer_id'] : $proof['author_id'];
        $recipient = User::where('organization_id', $proof['organization_id'])->whereKey($recipientId)->where('principal_type', 'institution')->lockForUpdate()->first();
        $access = app(ReviewMaturityAccess::class);
        $operation = $reassessment === null ? ($event === 'review_opened' ? ($review->stage_id === null ? 'review_content' : 'review_stage')
            : ($review->stage_id === null ? 'edit_content' : 'answer_stage')) : ($event === 'review_opened' ? 'review_reassessment' : 'save_reassessment');
        if ($recipient === null || ! OrganizationMembership::where('organization_id', $proof['organization_id'])->where('user_id', $recipientId)->lockForUpdate()->first()
            || ! $access->structural($recipient) || ! $access->current($recipient, 'read_workflow', null)
            || ! $access->current($recipient, $operation, $review->stage_id)) {
            return; // No substitute recipient or guessed role.
        }
        $key = hash('sha256', $review->public_id.':'.$event);
        if (DB::table('review_maturity_notifications')->where('organization_id', $proof['organization_id'])->where('fixture_id', $proof['fixture_id'])
            ->where('recipient_id', $recipientId)->where('event_key', $key)->lockForUpdate()->first()) {
            return;
        }
        [$title, $reason] = match ($event) {
            'review_opened' => ['مراجعة جديدة', 'حُفظ إصدار يحتاج إلى مراجعة مستقلة.'],
            'changes_requested' => ['طُلب تصحيح', 'راجع ملاحظات المراجعة في مساحة العمل المحمية.'],
            'approved' => ['اكتملت المراجعة', 'اعتُمد الإصدار المحدد ضمن هذا السيناريو الاختباري.'],
            default => throw new \DomainException('حدث إشعار غير صالح.'),
        };
        DB::table('review_maturity_notifications')->insert($scope + ['recipient_id' => $recipientId, 'stage_id' => $review->stage_id,
            'review_id' => $review->id, 'event' => $event, 'event_key' => $key, 'title' => $title, 'reason' => $reason]);
    }

    public function inbox(string $approachId, int $page = 1): LengthAwarePaginator
    {
        return app(ReviewMaturityAccess::class)->within('read_workflow', $approachId, null, function (User $actor, Approach $approach) use ($page) {
            $proof = ReviewMaturityAccess::context();

            return DB::table('review_maturity_notifications as notice')->leftJoin('maturity_workflow_stages as stage', fn ($join) => $join
                ->on('stage.organization_id', '=', 'notice.organization_id')->on('stage.fixture_id', '=', 'notice.fixture_id')
                ->on('stage.approach_id', '=', 'notice.approach_id')->on('stage.id', '=', 'notice.stage_id'))
                ->where('notice.organization_id', $approach->organization_id)->where('notice.fixture_id', $proof['fixture_id'])
                ->where('notice.approach_id', $approach->id)->where('notice.recipient_id', $actor->id)->orderByDesc('notice.id')
                ->paginate(20, ['notice.*', 'stage.public_id as stage_public_id'], 'page', max(1, $page));
        });
    }

    public function mark(string $approachId, string $publicId, bool $read): void
    {
        app(ReviewMaturityAccess::class)->within('read_workflow', $approachId, null, function (User $actor, Approach $approach) use ($publicId, $read): void {
            $proof = ReviewMaturityAccess::context();
            $row = DB::table('review_maturity_notifications')->where('organization_id', $approach->organization_id)->where('fixture_id', $proof['fixture_id'])
                ->where('approach_id', $approach->id)->where('recipient_id', $actor->id)->where('public_id', $publicId)->lockForUpdate()->first();
            if ($row === null) {
                throw new AuthorizationException;
            }
            DB::table('review_maturity_notifications')->where('id', $row->id)->update(['read_at' => $read ? ($row->read_at ?? ReviewMaturityAccess::timestamp()) : null]);
        });
    }
}
