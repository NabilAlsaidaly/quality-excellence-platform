<?php

namespace App\Actions\Maturity;

use App\Authorization\ReviewMaturityAccess;
use App\Domain\Maturity\ApprovedStageAssessment;
use App\Domain\Maturity\RatingBand;
use App\Domain\Maturity\RatingScale;
use App\Domain\Reviews\ReviewSnapshot;
use App\Domain\Reviews\ReviewState;
use App\Domain\Reviews\ReviewWorkflow;
use App\Models\Approach;
use App\Models\User;
use DateTimeImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Records immutable decisions only; C-scope effective impacts, notices and admission are not activated. */
final class DecideStageReassessment
{
    public function __construct(private OpenStageReassessment $boundary, private SaveStageReassessment $submissions) {}

    public function execute(string $attemptId, string $reviewId, string $expectedFingerprint, int $expectedRevision,
        string $decision, string $reason, string $requestKey, ?int $bandIndex = null): array
    {
        [$approachId, $stageId] = $this->boundary->coordinates($attemptId);

        return $this->boundary->within('review_reassessment', $approachId, $stageId,
            function (User $actor, Approach $approach) use ($attemptId, $reviewId, $expectedFingerprint, $expectedRevision, $decision, $reason, $requestKey, $bandIndex): array {
                $attempt = $this->boundary->attempt($attemptId);
                if (! Str::isUlid($requestKey) || ! Str::isUlid($reviewId) || ! in_array($decision, ['approved', 'changes_requested'], true)
                    || ! mb_check_encoding($reason, 'UTF-8') || mb_strlen($reason) > 65535 || preg_match('/[^\p{Z}\s]/u', $reason) !== 1) {
                    throw new DomainException('قرار المراجعة أو تعليله غير صالح.');
                }
                $hash = hash('sha256', json_encode([$reviewId, $expectedFingerprint, $expectedRevision, $decision, $reason, $bandIndex, $actor->public_id], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                $prior = DB::table('maturity_reassessment_events')->where('organization_id', $attempt->organization_id)
                    ->where('attempt_id', $attempt->id)->where('request_key', $requestKey)->lockForUpdate()->first();
                if ($prior !== null) {
                    $details = json_decode($prior->details_json, true, 64, JSON_THROW_ON_ERROR);
                    if ($prior->event !== $decision || (int) $prior->actor_id !== (int) $actor->id || ! hash_equals($details['request_hash'] ?? '', $hash)) {
                        throw new ConflictHttpException('معرّف القرار مستخدم لبيانات أخرى.');
                    }

                    return ['review_public_id' => $details['review_public_id'], 'decision_public_id' => $details['decision_public_id'], 'revision' => $details['revision']];
                }
                if ($attempt->state !== 'open' || $attempt->revision !== $expectedRevision || $expectedRevision >= 4294967295) {
                    throw new ConflictHttpException('تغيرت المحاولة أو حُسمت.');
                }
                $review = DB::table('review_maturity_reviews')->where('organization_id', $attempt->organization_id)
                    ->where('fixture_id', $attempt->fixture_id)->where('approach_id', $attempt->approach_id)
                    ->where('stage_id', $attempt->stage_id)->where('public_id', $reviewId)->lockForUpdate()->first() ?? throw new AuthorizationException;
                $this->assertReviewBelongsToAttempt($attempt, $review, $actor);
                if ($review->state !== 'pending' || ! hash_equals($review->fingerprint, $expectedFingerprint)) {
                    throw new ConflictHttpException('تغير إصدار المراجعة؛ أعد فتحه.');
                }
                $row = DB::table('maturity_workflow_submissions')->where('organization_id', $attempt->organization_id)
                    ->where('fixture_id', $attempt->fixture_id)->where('approach_id', $attempt->approach_id)
                    ->where('stage_id', $attempt->stage_id)->where('id', $review->submission_id)->lockForUpdate()->first() ?? throw new AuthorizationException;
                $value = $this->submissions->hydrate($approach, $attempt, $row);
                if (! hash_equals($review->fingerprint, $value->fingerprint())) {
                    throw new ConflictHttpException('لا تطابق المراجعة لقطة الإجابات المحفوظة.');
                }
                $this->submissions->verifyEvidence($actor, $value);
                $domain = ReviewSnapshot::open($review->public_id, $value->reviewTarget(), new DateTimeImmutable($review->created_at.' UTC'));
                $change = ReviewWorkflow::decide($domain, $actor->public_id, $value->publicId, ReviewState::from($decision), $reason,
                    new DateTimeImmutable(ReviewMaturityAccess::timestamp().' UTC'));
                $assessment = null;
                if ($decision === 'approved') {
                    $value->assertComplete();
                    if ($bandIndex === null) {
                        throw new DomainException('اختر حالة التقييم يدويًا.');
                    }
                    $snapshot = $value->snapshot();
                    $scale = new RatingScale(array_map(fn ($band) => new RatingBand($band['name'], $band['description'], $band['lower_bound'], $band['upper_bound']), $snapshot['configuration']['scale']));
                    $assessment = new ApprovedStageAssessment($approach->public_id, $value->stage(), $scale, $bandIndex,
                        $snapshot['answers'], $change->current, submission: $value);
                } elseif ($bandIndex !== null) {
                    throw new DomainException('طلب التصحيح لا يسجل تقييمًا جديدًا.');
                }
                // The unchanged review table pins its foundational review_stage assignment.
                // This NEVER replaces the separate review_reassessment Policy admission above.
                $access = app(ReviewMaturityAccess::class);
                $baseGrant = $access->current($actor, 'review_stage', $attempt->stage_id) ?? throw new AuthorizationException;
                $newGrant = $access->current($actor, 'review_reassessment', $attempt->stage_id) ?? throw new AuthorizationException;
                if (DB::table('review_maturity_reviews')->where('id', $review->id)->where('state', 'pending')
                    ->where('fingerprint', $expectedFingerprint)->update(['state' => $decision, 'reviewer_id' => $actor->id,
                        'decision_assignment_id' => $baseGrant->id, 'decision_key' => $requestKey, 'decision_fingerprint' => $hash,
                        'decided_at' => ReviewMaturityAccess::timestamp(), 'reason' => $reason]) !== 1) {
                    throw new ConflictHttpException('سبق حسم المراجعة.');
                }
                $changes = ['revision' => $expectedRevision + 1];
                if ($assessment !== null) {
                    $changes += ['state' => 'approved', 'approved_submission_id' => $row->id, 'approved_review_id' => $review->id];
                }
                if (DB::table('maturity_reassessment_attempts')->where('id', $attempt->id)->where('state', 'open')
                    ->where('revision', $expectedRevision)->update($changes) !== 1) {
                    throw new ConflictHttpException('ظهر تحديث متزامن للمحاولة.');
                }
                $decisionId = null;
                if ($assessment !== null) {
                    $decisionId = (string) Str::ulid();
                    // Parent must be approved with this exact pair BEFORE immediate child FK validation.
                    DB::table('maturity_reassessment_decisions')->insert(['public_id' => $decisionId,
                        'organization_id' => $attempt->organization_id, 'fixture_id' => $attempt->fixture_id,
                        'approach_id' => $attempt->approach_id, 'stage_id' => $attempt->stage_id,
                        'created_at' => ReviewMaturityAccess::timestamp(), 'attempt_id' => $attempt->id,
                        'submission_id' => $row->id, 'review_id' => $review->id, 'reviewer_id' => $actor->id,
                        'selected_band_index' => $bandIndex, 'snapshot_json' => json_encode($assessment->snapshot(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                        'reason' => $reason, 'policy_ref' => 'TEST_ONLY_D014_V4']);
                }
                $this->boundary->event($attempt, $actor, $decision, $requestKey, $row->id,
                    ['request_hash' => $hash, 'review_public_id' => $review->public_id, 'decision_public_id' => $decisionId,
                        'assignment_public_id' => $newGrant->public_id, 'revision' => $expectedRevision + 1]);

                return ['review_public_id' => $review->public_id, 'decision_public_id' => $decisionId, 'revision' => $expectedRevision + 1];
            });
    }

    /** Locked, read-only invariant; exposes no write or authorization bypass. */
    public function assertReviewBelongsToAttempt(object $attempt, object $review, User $reviewer): void
    {
        $proof = ReviewMaturityAccess::context() ?? throw new AuthorizationException;
        if (DB::transactionLevel() === 0 || (int) $attempt->organization_id !== $proof['organization_id']
            || $attempt->fixture_id !== $proof['fixture_id'] || (int) $attempt->approach_id !== $proof['approach_id']
            || (int) $review->organization_id !== (int) $attempt->organization_id || $review->fixture_id !== $attempt->fixture_id
            || (int) $review->approach_id !== (int) $attempt->approach_id || (int) $review->stage_id !== (int) $attempt->stage_id
            || $review->target_kind !== 'maturity_submission' || (int) $reviewer->organization_id !== (int) $attempt->organization_id) {
            throw new AuthorizationException;
        }
        if (DB::table('maturity_workflow_assessments')->where('organization_id', $attempt->organization_id)
            ->where('fixture_id', $attempt->fixture_id)->where('approach_id', $attempt->approach_id)
            ->where(fn ($query) => $query->where('review_id', $review->id)->orWhere('submission_id', $review->submission_id))->lockForUpdate()->first()) {
            throw new ConflictHttpException('لا يجوز إعادة استخدام مراجعة أو إجابات التقييم الأولي.');
        }
        if ((int) $attempt->author_id === (int) $reviewer->id) {
            throw new DomainException('يلزم مراجع مستقل عن صاحب المحاولة.');
        }
        $saved = DB::table('maturity_reassessment_events')->where('organization_id', $attempt->organization_id)
            ->where('fixture_id', $attempt->fixture_id)->where('approach_id', $attempt->approach_id)->where('stage_id', $attempt->stage_id)
            ->where('attempt_id', $attempt->id)->where('event', 'saved')->orderByDesc('id')->lockForUpdate()->first();
        if ($saved === null || (int) $saved->submission_id !== (int) $review->submission_id) {
            throw new ConflictHttpException('يلزم آخر إصدار جديد محفوظ لهذه المحاولة تحديدًا.');
        }
        $savedDetails = json_decode($saved->details_json, true, 64, JSON_THROW_ON_ERROR);
        if (strcasecmp($savedDetails['review_public_id'] ?? '', $review->public_id) !== 0
            || (int) $saved->actor_id !== (int) $attempt->author_id) {
            throw new ConflictHttpException('لا تنتمي المراجعة إلى عملية الحفظ الأصلية للمحاولة.');
        }
        if (DB::table('maturity_reassessment_events')->where('organization_id', $attempt->organization_id)
            ->where('fixture_id', $attempt->fixture_id)->where('event', 'saved')->where('submission_id', $review->submission_id)
            ->where('attempt_id', '!=', $attempt->id)->lockForUpdate()->first()
            || DB::table('maturity_reassessment_decisions')->where('organization_id', $attempt->organization_id)
                ->where('review_id', $review->id)->lockForUpdate()->first()) {
            throw new ConflictHttpException('الإصدار أو المراجعة مستخدمان لمحاولة أخرى أو قرار سابق.');
        }
        $submission = DB::table('maturity_workflow_submissions')->where('organization_id', $attempt->organization_id)
            ->where('fixture_id', $attempt->fixture_id)->where('approach_id', $attempt->approach_id)->where('stage_id', $attempt->stage_id)
            ->where('id', $review->submission_id)->lockForUpdate()->first();
        if ($submission === null || (int) $submission->configuration_id !== (int) $attempt->configuration_id
            || (int) $submission->actor_id === (int) $reviewer->id || (int) $submission->actor_id !== (int) $saved->actor_id
            || (int) $review->opened_by !== (int) $submission->actor_id) {
            throw new ConflictHttpException('لا يطابق الإصدار الإعداد الأصلي أو استقلال المراجع.');
        }
    }
}
