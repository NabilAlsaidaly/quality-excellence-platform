<?php

namespace App\Actions\Maturity;

use App\Actions\Approaches\CreateApproach;
use App\Actions\Reviews\ReviewMaturityWorkspace;
use App\Authorization\DocumentWorkspaceAccess;
use App\Authorization\ReviewMaturityAccess;
use App\Models\Approach;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Dormant TEST_ONLY persistence; no old answer/review operation grants reassessment authority. */
final class OpenStageReassessment
{
    public function execute(string $approachId, string $stageId, string $expectedAssessment, string $requestKey): array
    {
        return $this->within('open_reassessment', $approachId, $stageId,
            function (User $actor, Approach $approach, object $stage) use ($expectedAssessment, $requestKey): array {
                if (! Str::isUlid($requestKey)) {
                    throw new ConflictHttpException('معرّف الطلب غير صالح.');
                }
                $proof = ReviewMaturityAccess::context();
                $initials = DB::table('maturity_workflow_assessments')->where('organization_id', $approach->organization_id)
                    ->where('fixture_id', $proof['fixture_id'])->where('approach_id', $approach->id)
                    ->orderBy('stage_ordinal')->lockForUpdate()->get();
                if ($initials->pluck('stage_ordinal')->all() !== [1, 2, 3, 4, 5]) {
                    throw new ConflictHttpException('هذه النواة لا تدعم إعادة تقييم دورة أولية غير مكتملة.');
                }
                $initial = $initials->firstWhere('stage_id', $stage->id) ?? throw new AuthorizationException;
                $previous = DB::table('maturity_reassessment_attempts')->where('organization_id', $approach->organization_id)
                    ->where('fixture_id', $proof['fixture_id'])->where('approach_id', $approach->id)
                    ->where('stage_id', $stage->id)->where('state', 'approved')->orderByDesc('attempt_number')->lockForUpdate()->first();
                $decision = $previous ? DB::table('maturity_reassessment_decisions')->where('organization_id', $approach->organization_id)
                    ->where('attempt_id', $previous->id)->lockForUpdate()->first() : null;
                if ($previous !== null && $decision === null) {
                    throw new ConflictHttpException('تحتاج سلسلة القرارات إلى مراجعة سلامة.');
                }
                $hash = hash('sha256', json_encode([$approach->public_id, $stage->public_id, $expectedAssessment, $actor->public_id], JSON_THROW_ON_ERROR));
                $prior = DB::table('maturity_reassessment_attempts')->where('organization_id', $approach->organization_id)
                    ->where('author_id', $actor->id)->where('request_key', $requestKey)->lockForUpdate()->first();
                if ($prior !== null) {
                    if ($prior->fixture_id !== $proof['fixture_id'] || ! hash_equals($prior->request_hash, $hash)) {
                        throw new ConflictHttpException('معرّف الطلب مستخدم لمحاولة مختلفة.');
                    }

                    return ['attempt_public_id' => $prior->public_id, 'revision' => $prior->revision];
                }
                if (strcasecmp($expectedAssessment, $decision?->public_id ?? $initial->public_id) !== 0) {
                    throw new ConflictHttpException('تغير آخر تقييم مسجل؛ أعد فتح التاريخ.');
                }
                if (DB::table('maturity_reassessment_attempts')->where('organization_id', $approach->organization_id)
                    ->where('fixture_id', $proof['fixture_id'])->where('approach_id', $approach->id)->where('stage_id', $stage->id)
                    ->where('state', 'open')->lockForUpdate()->first()) {
                    throw new ConflictHttpException('توجد محاولة مفتوحة لهذه المرحلة.');
                }
                $submission = DB::table('maturity_workflow_submissions')->where('organization_id', $approach->organization_id)
                    ->where('fixture_id', $proof['fixture_id'])->where('approach_id', $approach->id)->where('stage_id', $stage->id)
                    ->where('id', $decision?->submission_id ?? $initial->submission_id)->lockForUpdate()->first() ?? throw new AuthorizationException;
                $scope = ReviewMaturityWorkspace::attributes($approach);
                $id = DB::table('maturity_reassessment_attempts')->insertGetId($scope + [
                    'stage_id' => $stage->id, 'initial_assessment_id' => $initial->id, 'previous_attempt_id' => $previous?->id,
                    'attempt_number' => ($previous?->attempt_number ?? 0) + 1, 'author_id' => $actor->id,
                    'configuration_id' => $submission->configuration_id, 'request_key' => $requestKey,
                    'request_hash' => $hash, 'state' => 'open', 'revision' => 1]);
                $attempt = DB::table('maturity_reassessment_attempts')->where('id', $id)->first();
                $this->event($attempt, $actor, 'opened', $requestKey, null, ['request_hash' => $hash]);

                return ['attempt_public_id' => $attempt->public_id, 'revision' => 1];
            });
    }

    /** Organization -> actor/member -> Approach -> all stage roots -> current real Policy -> callback. */
    public function within(string $operation, string $approachId, string $stageId, Closure $callback): mixed
    {
        $proof = ReviewMaturityAccess::context() ?? throw new AuthorizationException;
        $actor = Auth::guard('institution')->user();

        return app(DocumentWorkspaceAccess::class)->withSession($actor, fn (User $current) => app(CreateApproach::class)->withinContext($current,
            function (int $organization, User $locked) use ($proof, $operation, $approachId, $stageId, $callback): mixed {
                $approach = Str::isUlid($approachId) ? Approach::where('organization_id', $organization)
                    ->whereKey($proof['approach_id'])->where('public_id', $approachId)->lockForUpdate()->first() : null;
                if ($approach === null || ! hash_equals($locked->getAuthPassword(), (string) request()->session()->get('institution.password_hash'))) {
                    throw new AuthorizationException;
                }
                $stages = DB::table('maturity_workflow_stages')->where('organization_id', $organization)
                    ->where('fixture_id', $proof['fixture_id'])->where('approach_id', $approach->id)
                    ->orderBy('stage_ordinal')->lockForUpdate()->get();
                $stage = Str::isUlid($stageId) ? $stages->first(fn ($row) => strcasecmp($row->public_id, $stageId) === 0) : null;
                if ($stage === null) {
                    throw new AuthorizationException;
                }
                $access = app(ReviewMaturityAccess::class);
                $access->authorize($locked, $operation, $approach, $stage->id);
                $result = $callback($locked, $approach, $stage);
                $access->authorize($locked, $operation, $approach, $stage->id);
                if (! hash_equals($locked->fresh()->getAuthPassword(), (string) request()->session()->get('institution.password_hash'))) {
                    throw new AuthorizationException;
                }

                return $result;
            }));
    }

    public function attempt(string $publicId): object
    {
        $proof = ReviewMaturityAccess::context() ?? throw new AuthorizationException;
        if (DB::transactionLevel() === 0 || ! Str::isUlid($publicId)) {
            throw new AuthorizationException;
        }

        return DB::table('maturity_reassessment_attempts')->where('organization_id', $proof['organization_id'])
            ->where('fixture_id', $proof['fixture_id'])->where('approach_id', $proof['approach_id'])
            ->where('classification', 'TEST_ONLY')->where('scenario', ReviewMaturityAccess::SCENARIO)
            ->where('public_id', $publicId)->lockForUpdate()->first() ?? throw new AuthorizationException;
    }

    public function coordinates(string $attemptId): array
    {
        $proof = ReviewMaturityAccess::context() ?? throw new AuthorizationException;
        $row = Str::isUlid($attemptId) ? DB::table('maturity_reassessment_attempts')->where('organization_id', $proof['organization_id'])
            ->where('fixture_id', $proof['fixture_id'])->where('approach_id', $proof['approach_id'])->where('public_id', $attemptId)->first() : null;
        if ($row === null) {
            throw new AuthorizationException;
        }

        return [Approach::whereKey($proof['approach_id'])->where('organization_id', $proof['organization_id'])->value('public_id'),
            DB::table('maturity_workflow_stages')->where('organization_id', $proof['organization_id'])->where('id', $row->stage_id)->value('public_id')];
    }

    public function event(object $attempt, User $actor, string $event, string $key, ?int $submission, array $details): void
    {
        if (DB::transactionLevel() === 0 || ! Str::isUlid($key)) {
            throw new LogicException('Reassessment audit must share the origin transaction.');
        }
        DB::table('maturity_reassessment_events')->insert(['public_id' => (string) Str::ulid(),
            'organization_id' => $attempt->organization_id, 'fixture_id' => $attempt->fixture_id,
            'approach_id' => $attempt->approach_id, 'stage_id' => $attempt->stage_id,
            'attempt_id' => $attempt->id, 'actor_id' => $actor->id, 'submission_id' => $submission,
            'request_key' => $key, 'event' => $event, 'details_json' => json_encode((object) $details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => ReviewMaturityAccess::timestamp()]);
    }
}
