<?php

namespace App\Actions\Reviews;

use App\Authorization\ReviewMaturityAccess;
use App\Authorization\TenantContext;
use App\Models\Approach;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RecordReviewMaturityAudit
{
    public function execute(User $actor, Approach $approach, string $event, string $key, ?int $stageId = null,
        ?int $reviewId = null, ?object $assignment = null, ?string $operation = null, ?int $subjectId = null): void
    {
        $proof = ReviewMaturityAccess::context() ?? throw new AuthorizationException;
        if (DB::transactionLevel() === 0 || (int) $actor->organization_id !== $proof['organization_id']
            || ! app(TenantContext::class)->verifiedFor($actor)
            || (int) Auth::guard('institution')->id() !== (int) $actor->id
            || (int) $approach->id !== $proof['approach_id'] || (int) $approach->organization_id !== $proof['organization_id']) {
            throw new AuthorizationException;
        }
        DB::table('review_maturity_audit')->insert(['public_id' => (string) Str::ulid(), 'organization_id' => $proof['organization_id'],
            'fixture_id' => $proof['fixture_id'], 'approach_id' => $approach->id, 'scenario' => ReviewMaturityAccess::SCENARIO,
            'classification' => 'TEST_ONLY', 'created_at' => ReviewMaturityAccess::timestamp(), 'actor_id' => $actor->id,
            'subject_id' => $subjectId, 'stage_id' => $stageId, 'assignment_id' => $assignment?->id, 'review_id' => $reviewId,
            'operation' => $operation, 'event' => $event, 'event_key' => $key]);
    }
}
