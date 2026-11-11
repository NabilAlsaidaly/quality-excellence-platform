<?php

declare(strict_types=1);

namespace App\Actions\OrganizationalUnits;

use App\Actions\Organizations\OrganizationManagement;
use App\Authorization\TenantContext;
use App\Models\Organization;
use App\Models\User;
use App\Support\Institution\OrganizationalUnitTreePresenter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class AuthorizedUnitOperations
{
    public function __construct(
        private TenantContext $context,
        private OrganizationManagement $organizations,
        private OrganizationalUnitManagement $units,
        private OrganizationalUnitTreePresenter $presenter,
    ) {}

    /**
     * Read the current institution's authorized metadata.
     *
     * @return array{public_id: string, name: string}
     */
    public function readMetadata(?User $actor, string $organizationPublicId): array
    {
        $authorized = $this->authorize(
            $actor,
            $organizationPublicId,
            'organization.units.view',
        );

        return $this->organizations->metadata(
            $authorized['actor'],
            $authorized['organization']->public_id,
        );
    }

    /**
     * Read and present the current institution's organizational-unit tree.
     *
     * @return array{roots: list<string>, nodes: array<string, array>}
     */
    public function tree(?User $actor, string $organizationPublicId): array
    {
        $authorized = $this->authorize(
            $actor,
            $organizationPublicId,
            'organization.units.view',
        );

        $tree = $this->units->tree($authorized['actor']);

        return $this->presenter->present($tree);
    }

    public function create(
        ?User $actor,
        string $organizationPublicId,
        string $name,
        ?string $parentPublicId = null,
    ): void {
        $authorized = $this->authorize(
            $actor,
            $organizationPublicId,
            'organization.units.manage',
        );

        $this->units->create(
            $authorized['actor'],
            $name,
            $parentPublicId,
        );
    }

    public function rename(
        ?User $actor,
        string $organizationPublicId,
        string $unitPublicId,
        string $name,
    ): void {
        $authorized = $this->authorize(
            $actor,
            $organizationPublicId,
            'organization.units.manage',
        );

        $this->units->rename(
            $authorized['actor'],
            $unitPublicId,
            $name,
        );
    }

    public function move(
        ?User $actor,
        string $organizationPublicId,
        string $unitPublicId,
        ?string $parentPublicId,
    ): void {
        $authorized = $this->authorize(
            $actor,
            $organizationPublicId,
            'organization.units.manage',
        );

        $this->units->move(
            $authorized['actor'],
            $unitPublicId,
            $parentPublicId,
        );
    }

    /**
     * Verify the trusted institution actor and tenant before authorizing
     * an operation against the current organization.
     *
     * @return array{actor: User, organization: Organization}
     */
    private function authorize(
        ?User $actor,
        string $organizationPublicId,
        string $capability,
    ): array {
        $guardActor = Auth::guard('institution')->user();
        $organizationId = $this->context->organizationId();

        if (
            $actor === null
            || ! $actor->exists
            || ! ($guardActor instanceof User)
            || $organizationId === null
            || (int) $guardActor->getAuthIdentifier() !== (int) $actor->getKey()
            || $this->context->actorId() !== (int) $actor->getKey()
        ) {
            throw new AuthorizationException;
        }

        // Reload the actor instead of trusting previously loaded relations.
        $trustedActor = User::query()->find($actor->getKey());

        if (
            $trustedActor === null
            || $trustedActor->principal_type !== 'institution'
            || (int) $trustedActor->organization_id !== (int) $organizationId
            || ! $this->context->verifiedFor($trustedActor)
        ) {
            throw new AuthorizationException;
        }

        // The organization is resolved only from the verified tenant context.
        $organization = Organization::query()->find($organizationId);

        if ($organization === null) {
            throw new AuthorizationException;
        }

        // A browser-supplied identifier may confirm the current tenant,
        // but it must never select the tenant used for authorization.
        if (
            ! Str::isUlid($organizationPublicId)
            || strcasecmp($organization->public_id, $organizationPublicId) !== 0
        ) {
            throw (new ModelNotFoundException)->setModel(Organization::class);
        }

        // Existing Gate integration delegates to TenantAuthorization.
        // An absent or unrecognized capability remains denied by default.
        abort_unless(
            Gate::forUser($trustedActor)->allows(
                'tenant-capability',
                [$capability, $organization],
            ),
            403,
        );

        return [
            'actor' => $trustedActor,
            'organization' => $organization,
        ];
    }
}
