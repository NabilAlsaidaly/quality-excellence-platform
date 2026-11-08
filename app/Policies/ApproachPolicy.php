<?php

namespace App\Policies;

use App\Authorization\TenantAuthorization;
use App\Authorization\TenantContext;
use App\Models\Approach;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;

/** Candidate only. No approved D002 assignment store or operational grant exists. */
class ApproachPolicy
{
    public const OPERATIONS = ['list', 'create', 'view', 'updateMetadata', 'changeOwner', 'editDraft', 'viewDraftHistory', 'viewAuditHistory'];

    public function before(User $actor, string $ability): ?bool
    {
        return in_array($ability, ['viewAny', 'create', 'view', 'update'], true) ? null : false;
    }

    final public function allowsOperation(User $actor, string $operation, ?Approach $resource = null): bool
    {
        $context = app(TenantContext::class);
        $current = $this->verifiedActor($actor);
        if (! in_array($operation, self::OPERATIONS, true) || $current === null) {
            return false;
        }
        if ($resource !== null && (! $resource->exists || ! Approach::query()->whereKey($resource->getKey())
            ->where('organization_id', $context->organizationId())->where('public_id', $resource->public_id)->exists())) {
            return false;
        }
        if (! in_array($operation, ['list', 'create'], true) && $resource === null) {
            return false;
        }

        return $this->hasAssignment($current, $operation, $resource);
    }

    /** UI hints only; every detail/mutation must still authorize at execution. */
    final public function viewablePublicIds(User $actor, array $publicIds): array
    {
        if (count($publicIds) > 100 || array_filter($publicIds, fn ($id) => ! is_string($id) || ! Str::isUlid($id)) !== []) {
            return [];
        }
        $current = $this->verifiedActor($actor);
        if ($current === null || $publicIds === []) {
            return [];
        }

        return Approach::query()->where('organization_id', app(TenantContext::class)->organizationId())->whereIn('public_id', $publicIds)
            ->get()->filter(fn (Approach $resource) => $this->hasAssignment($current, 'view', $resource))->pluck('public_id')->all();
    }

    private function verifiedActor(User $actor): ?User
    {
        $context = app(TenantContext::class);
        $current = User::query()->find($actor->getKey());
        if (! $context->verifiedFor($actor) || $current?->principal_type !== 'institution'
            || (int) $current->organization_id !== $context->organizationId()) {
            return null;
        }
        $organization = Organization::query()->find($context->organizationId());

        // Intentionally unregistered. Tests register this structural predicate only
        // in isolated fixtures, then independently simulate per-resource assignments.
        return $organization !== null && app(TenantAuthorization::class)->allows($actor, 'approaches.access', $organization) ? $current : null;
    }

    protected function hasAssignment(User $actor, string $operation, ?Approach $resource): bool
    {
        return false;
    }

    public function viewAny(User $actor): bool
    {
        return $this->allowsOperation($actor, 'list');
    }

    public function create(User $actor): bool
    {
        return $this->allowsOperation($actor, 'create');
    }

    public function view(User $actor, Approach|string $resource): bool
    {
        // Existing inert HTTP adapters pass class + public ULID to Gate. They
        // remain denied; never turn an unscoped string into a trusted model.
        return $resource instanceof Approach && $this->allowsOperation($actor, 'view', $resource);
    }

    public function update(User $actor, Approach|string $resource): bool
    {
        return $resource instanceof Approach && $this->allowsOperation($actor, 'updateMetadata', $resource);
    }

    public function delete(User $actor, Approach $resource): bool
    {
        return false;
    }

    public function approve(User $actor, Approach $resource): bool
    {
        // Review engine and independent reviewer assignments are outside Issue44.
        return false;
    }
}
