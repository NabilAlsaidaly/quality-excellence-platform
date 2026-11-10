<?php

namespace App\Actions\Reviews;

use App\Authorization\ReviewMaturityAccess;
use App\Filament\Institution\Approaches\ApproachAccess;
use App\Models\Approach;
use App\Models\ReviewMaturityReview;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class SaveReviewableApproach
{
    public function execute(string $publicId, string $body, string $expectedRevision, string $requestKey): ?ReviewMaturityReview
    {
        if (! Str::isUlid($requestKey)) {
            throw ValidationException::withMessages(['request_key' => 'معرّف الحفظ غير صالح.']);
        }

        return app(ReviewMaturityAccess::class)->within('edit_content', $publicId, null,
            function (User $actor, Approach $approach) use ($body, $expectedRevision, $requestKey): ?ReviewMaturityReview {
                $proof = ReviewMaturityAccess::context();
                $prior = ReviewMaturityReview::where('organization_id', $approach->organization_id)->where('fixture_id', $proof['fixture_id'])
                    ->where('approach_id', $approach->id)->where('request_key', $requestKey)->lockForUpdate()->first();
                if ($prior !== null) {
                    $revision = DB::table('approach_content_revisions')->where('organization_id', $approach->organization_id)
                        ->where('approach_id', $approach->id)->where('id', $prior->approach_revision_id)->lockForUpdate()->first();
                    // Replays still need the unchanged real J03 Policy, not merely a stored request key.
                    if (! app(ApproachAccess::class)->can('editDraft', $approach->public_id)) {
                        throw new AuthorizationException;
                    }
                    if ($prior->target_kind !== 'approach_content' || (int) $prior->opened_by !== (int) $actor->id || $revision?->body !== $body) {
                        throw new ConflictHttpException('أعيد استخدام معرّف الحفظ لبيانات مختلفة.');
                    }

                    return $prior;
                }
                $before = DB::table('approach_content_revisions')->where('organization_id', $approach->organization_id)->where('approach_id', $approach->id)
                    ->orderByDesc('version_number')->lockForUpdate()->first();
                app(ApproachAccess::class)->saveDraft($approach->public_id, $body, $expectedRevision);
                $after = DB::table('approach_content_revisions')->where('organization_id', $approach->organization_id)->where('approach_id', $approach->id)
                    ->orderByDesc('version_number')->lockForUpdate()->first();
                if ($after === null || $before?->id === $after->id) {
                    return null;
                }

                return app(SynchronizeReview::class)->execute($actor, $approach, 'approach_content', $after, null, $requestKey);
            });
    }
}
