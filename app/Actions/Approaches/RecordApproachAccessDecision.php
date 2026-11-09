<?php

namespace App\Actions\Approaches;

use App\Authorization\ApproachAssignmentAccess;
use App\Authorization\TenantContext;
use App\Models\Approach;
use App\Models\ApproachAssignmentChange;
use App\Models\User;
use Illuminate\Support\Str;

/** Actual denied execution only; never invoked by Policy/UI visibility hints. */
final class RecordApproachAccessDecision
{
    public function execute(User $actor, string $operation, ?string $publicId = null): void
    {
        $proof = ApproachAssignmentAccess::context();
        if ($proof === null || ! in_array($operation, ApproachAssignmentAccess::OPERATIONS, true)
            || (int) $actor->organization_id !== $proof['organization_id']) {
            return;
        }
        $context = app(TenantContext::class);
        if (! $context->run($actor, $proof['organization_id'], fn () => $context->verifiedFor($actor))) {
            return;
        }
        $context->run($actor, $proof['organization_id'], fn () => app(CreateApproach::class)->withinContext($actor,
            function () use ($actor, $operation, $publicId, $proof): void {
                $resource = $publicId === null ? null : Approach::where('organization_id', $proof['organization_id'])
                    ->where('public_id', $publicId)->lockForUpdate()->first();
                $query = app(ApproachAssignmentAccess::class)->query($actor, $operation);
                $resource === null ? $query->whereNull('approach_id') : $query->where('approach_id', $resource->id);
                foreach ($query->orderBy('id')->lockForUpdate()->get() as $row) {
                    if ($row->expires_at <= ApproachAssignmentAccess::timestamp()) {
                        $key = $row->public_id.':expiration_observed';
                        if (! ApproachAssignmentChange::where('organization_id', $proof['organization_id'])
                            ->where('fixture_id', $proof['fixture_id'])->where('event_key', $key)->exists()) {
                            $this->event($proof, $actor, $operation, 'expiration_observed', $key, $resource?->id, $row->id);
                        }
                    }
                }
                $this->event($proof, $actor, $operation, 'access_denied', (string) Str::ulid(), $resource?->id);
            }));
    }

    private function event(array $proof, User $actor, string $operation, string $event, string $key, ?int $resource, ?int $assignment = null): void
    {
        $row = new ApproachAssignmentChange;
        $row->forceFill(['organization_id' => $proof['organization_id'], 'fixture_id' => $proof['fixture_id'],
            'actor_id' => $actor->id, 'subject_id' => $actor->id, 'approach_id' => $resource, 'assignment_id' => $assignment,
            'operation' => $operation, 'event' => $event, 'event_key' => $key, 'created_at' => ApproachAssignmentAccess::timestamp()]);
        $row->save();
    }
}
