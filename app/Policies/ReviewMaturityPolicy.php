<?php

namespace App\Policies;

use App\Authorization\ReviewMaturityAccess;
use App\Models\Approach;
use App\Models\User;

final class ReviewMaturityPolicy
{
    public function workflowOperation(User $actor, string $operation, Approach $approach, ?int $stageId = null): bool
    {
        return app(ReviewMaturityAccess::class)->allows($actor, $operation, $approach, $stageId);
    }
}
