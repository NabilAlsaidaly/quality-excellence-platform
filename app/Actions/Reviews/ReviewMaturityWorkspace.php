<?php

namespace App\Actions\Reviews;

use App\Authorization\ReviewMaturityAccess;
use App\Models\Approach;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Small application facade for the explicitly registered private demonstration panel. */
final class ReviewMaturityWorkspace
{
    public static function attributes(Approach $approach): array
    {
        $proof = ReviewMaturityAccess::context() ?? throw new AuthorizationException;
        if (DB::transactionLevel() === 0 || (int) $approach->id !== $proof['approach_id']
            || (int) $approach->organization_id !== $proof['organization_id']) {
            throw new AuthorizationException;
        }

        return ['public_id' => (string) Str::ulid(), 'organization_id' => $proof['organization_id'],
            'fixture_id' => $proof['fixture_id'], 'approach_id' => $approach->id,
            'scenario' => ReviewMaturityAccess::SCENARIO, 'classification' => 'TEST_ONLY',
            'created_at' => ReviewMaturityAccess::timestamp()];
    }

    public function approachPublicId(): ?string
    {
        $proof = ReviewMaturityAccess::context();

        return $proof ? Approach::where('organization_id', $proof['organization_id'])->whereKey($proof['approach_id'])->value('public_id') : null;
    }

    public function canEnter(): bool
    {
        $publicId = $this->approachPublicId();

        return $publicId !== null && app(ReviewMaturityAccess::class)->can('read_workflow', $publicId);
    }
}
