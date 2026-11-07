<?php

namespace App\Actions\Approaches;

use App\Models\Approach;
use App\Models\ApproachContentRevision;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** @internal Trusted draft persistence; no submission, publication or user grant. */
final class SaveApproachContentRevision
{
    public function __construct(private CreateApproach $boundary) {}

    public function execute(?User $actor, string $approachPublicId, string $body): ApproachContentRevision
    {
        return $this->boundary->withinContext($actor, function (int $organizationId, User $currentActor) use ($approachPublicId, $body): ApproachContentRevision {
            $approach = Str::isUlid($approachPublicId) ? Approach::query()->where('organization_id', $organizationId)
                ->where('public_id', $approachPublicId)->lockForUpdate()->first() : null;
            if ($approach === null) {
                throw (new ModelNotFoundException)->setModel(Approach::class);
            }
            if (preg_match('/[^\p{Z}\s]/u', $body) !== 1 || mb_strlen($body) > 1000000) {
                throw ValidationException::withMessages(['body' => 'أدخل محتوى نصيًا غير فارغ ضمن الحد التقني المسموح.']);
            }
            $latest = $approach->revisions()->orderByDesc('version_number')->lockForUpdate()->first();
            if ($latest !== null && $latest->body === $body) {
                return $latest;
            }
            $version = ($latest?->version_number ?? 0) + 1;
            if ($version > 4294967295) {
                throw ValidationException::withMessages(['body' => 'تعذر تخصيص رقم إصدار جديد.']);
            }
            $revision = new ApproachContentRevision;
            $revision->forceFill(['organization_id' => $organizationId, 'approach_id' => $approach->id,
                'actor_id' => $currentActor->getKey(), 'version_number' => $version, 'body' => $body]);
            $revision->save();

            return $revision;
        });
    }
}
