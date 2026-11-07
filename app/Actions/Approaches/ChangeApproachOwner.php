<?php

namespace App\Actions\Approaches;

use App\Models\Approach;
use App\Models\OrganizationalUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** @internal Owner transfer has no role, workflow or approval side effects. */
final class ChangeApproachOwner
{
    public function __construct(private UpdateApproach $updates) {}

    public function execute(?User $actor, string $publicId, string $ownerPublicId, ?string $expectedToken = null): array
    {
        return $this->updates->withinApproach($actor, $publicId, $expectedToken, function (Approach $approach, User $currentActor) use ($ownerPublicId): void {
            $unit = Str::isUlid($ownerPublicId) ? OrganizationalUnit::query()->where('organization_id', $approach->organization_id)
                ->where('public_id', $ownerPublicId)->lockForUpdate()->first() : null;
            if ($unit === null) {
                throw (new ModelNotFoundException)->setModel(OrganizationalUnit::class);
            }
            if ((int) $unit->id === (int) $approach->owning_unit_id) {
                return;
            }
            $previous = $approach->owningUnit;
            DB::table('approaches')->where('organization_id', $approach->organization_id)->where('id', $approach->id)
                ->update(['owning_unit_id' => $unit->id, 'updated_at' => $approach->freshTimestampString()]);
            $approach->refresh();
            $this->updates->record($approach, $currentActor, 'owner_changed',
                ['public_id' => $previous->public_id, 'name' => $previous->name],
                ['public_id' => $unit->public_id, 'name' => $unit->name], $previous->id, $unit->id);
        });
    }
}
