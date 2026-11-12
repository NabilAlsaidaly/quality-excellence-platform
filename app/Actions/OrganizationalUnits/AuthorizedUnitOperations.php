<?php

declare(strict_types=1);

namespace App\Actions\OrganizationalUnits;

use App\Actions\Organizations\OrganizationManagement;
use App\Authorization\TenantContext;
use App\Models\Organization;
use App\Models\User;
use App\Support\Institution\OrganizationalUnitTreePresenter;
use Closure;
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
        return $this->runAuthorized($actor, $organizationPublicId, 'organization.units.view',
            fn (array $authorized) => $this->organizations->metadata($authorized['actor'], $authorized['organization']->public_id));
    }

    /**
     * Read and present the current institution's organizational-unit tree.
     *
     * @return array{roots: list<string>, nodes: array<string, array>}
     */
    public function tree(?User $actor, string $organizationPublicId): array
    {
        return $this->runAuthorized($actor, $organizationPublicId, 'organization.units.view',
            fn (array $authorized) => $this->presenter->present($this->units->tree($authorized['actor'])));
    }

    public function create(
        ?User $actor,
        string $organizationPublicId,
        string $name,
        ?string $parentPublicId = null,
    ): void {
        $this->runAuthorized($actor, $organizationPublicId, 'organization.units.manage',
            fn (array $authorized) => $this->units->create($authorized['actor'], $name, $parentPublicId));
    }

    public function rename(
        ?User $actor,
        string $organizationPublicId,
        string $unitPublicId,
        string $name,
    ): void {
        $this->runAuthorized($actor, $organizationPublicId, 'organization.units.manage',
            fn (array $authorized) => $this->units->rename($authorized['actor'], $unitPublicId, $name));
    }

    public function move(
        ?User $actor,
        string $organizationPublicId,
        string $unitPublicId,
        ?string $parentPublicId,
    ): void {
        $this->runAuthorized($actor, $organizationPublicId, 'organization.units.manage',
            fn (array $authorized) => $this->units->move($authorized['actor'], $unitPublicId, $parentPublicId));
    }

    private function runAuthorized(?User $actor, string $organizationPublicId, string $capability, Closure $operation): mixed
    {
        $guard = Auth::guard('institution');
        $session = $guard->getSession();
        $password = $session->get('institution.password_hash');
        if ($actor === null || $guard->id() !== $actor->getAuthIdentifier()
            || $session->get('identity.active_guard') !== 'institution'
            || ! is_string($password) || $session->get('institution.organization') !== $organizationPublicId) {
            throw new AuthorizationException;
        }

        return $this->units->withinContext($actor, function (int $organizationId, User $currentActor) use ($organizationPublicId, $capability, $operation, $password): mixed {
            if ($currentActor->principal_type !== 'institution' || (int) $currentActor->organization_id !== $organizationId
                || ! hash_equals($password, $currentActor->getAuthPassword())) {
                throw new AuthorizationException;
            }

            // Decisive Gate check occurs after lock waits, on this same PDO,
            // held through the original domain mutation, audit and commit.
            return $operation($this->authorize($currentActor, $organizationPublicId, $capability));
        });
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

        // Supplied only by the fresh, locked mutation callback above.
        $trustedActor = $actor;

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
