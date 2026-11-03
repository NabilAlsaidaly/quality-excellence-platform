<?php

namespace App\Actions\Organizations;

use App\Authorization\TenantContext;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @internal Member-scoped metadata infrastructure, not administrative authority.
 * No public caller until its owner-approved resource Policy is implemented.
 */
final class OrganizationManagement
{
    public function __construct(private TenantContext $context) {}

    /** @return array{public_id: string, name: string} */
    public function metadata(?User $actor, string $publicId): array
    {
        if ($actor === null || ! $actor->exists || $this->context->organizationId() === null
            || $this->context->actorId() !== (int) $actor->getKey()) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $publicId): array {
            // Read ONLY the trusted context key, never a global foreign-ID lookup.
            // Shared current reads coordinate with rename/revocation and remain
            // current even inside an older MySQL REPEATABLE READ snapshot.
            $organization = Organization::query()->whereKey($this->context->organizationId())
                ->sharedLock()->first(['id', 'public_id', 'name']);
            $user = User::query()->whereKey($actor->getKey())->sharedLock()->first(['id']);
            $membership = OrganizationMembership::query()->where('user_id', $actor->getKey())
                ->where('organization_id', $this->context->organizationId())->sharedLock()->first(['id']);
            if ($organization === null || $user === null || $membership === null || ! $this->context->verifiedFor($actor)) {
                throw new AuthorizationException;
            }
            if (! Str::isUlid($publicId) || strcasecmp($organization->public_id, $publicId) !== 0) {
                throw (new ModelNotFoundException)->setModel(Organization::class);
            }

            return ['public_id' => $organization->public_id, 'name' => $organization->name];
        });
    }
}
