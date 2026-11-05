<?php

namespace App\Authorization;

use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Spatie\Permission\Models\Permission;

/**
 * @internal Trusted application infrastructure only: no UI, route or command.
 * A future caller MUST first enforce an owner-approved management policy.
 * Membership/context alone does not authorize a user to administer roles.
 */
final class RoleManagement
{
    public function __construct(private TenantContext $context, private CapabilityRegistry $capabilities) {}

    public function list(): Collection
    {
        $this->requireContext();

        return Role::query()->where('organization_id', $this->context->organizationId())->orderBy('id')->get();
    }

    public function create(string $name): Role
    {
        return $this->mutate('role.created', null, null, function () use ($name): Role {
            return Role::create(['name' => $this->validName($name), 'guard_name' => 'web',
                'organization_id' => $this->context->organizationId()]);
        });
    }

    public function rename(Role $role, string $name): Role
    {
        return $this->mutate('role.renamed', $role, null, function (Role $current) use ($name): Role {
            $current->name = $this->validName($name);
            $current->save();

            return $current;
        });
    }

    /** @param list<string> $capabilities */
    public function syncPermissions(Role $role, array $capabilities): void
    {
        foreach ($capabilities as $capability) {
            $this->capabilities->requireRecognized($capability);
        }
        $this->mutate('role.permissions_changed', $role, null, function (Role $current) use ($capabilities): void {
            $permissions = array_map(fn (string $name) => Permission::findOrCreate($name, 'web'), array_unique($capabilities));
            $current->syncPermissions($permissions);
        });
    }

    public function assign(Role $role, User $member): void
    {
        $this->mutate('role.assigned', $role, $member, function (Role $current) use ($member): void {
            $this->verifiedMember($member)->assignRole($current);
        });
    }

    public function revoke(Role $role, User $member): void
    {
        $this->mutate('role.revoked', $role, $member, function (Role $current) use ($member): void {
            $this->verifiedMember($member)->removeRole($current);
        });
    }

    public function delete(Role $role): void
    {
        $this->mutate('role.deleted', $role, null, function (Role $current): void {
            if (DB::table('model_has_roles')->where('role_id', $current->id)->exists()) {
                throw new LogicException('Assigned roles require explicit revocation before deletion.');
            }
            $current->permissions()->detach();
            $current->delete();
        });
    }

    private function mutate(string $event, ?Role $role, ?User $member, Closure $operation): mixed
    {
        return DB::transaction(function () use ($event, $role, $member, $operation): mixed {
            $this->requireContext();
            // Serialize actor-membership removal with this internal mutation.
            if (! OrganizationMembership::query()->where('user_id', $this->context->actorId())
                ->where('organization_id', $this->context->organizationId())->lockForUpdate()->first()) {
                throw new AuthorizationException;
            }
            $current = $role === null ? null : Role::query()
                ->where('organization_id', $this->context->organizationId())
                ->where('guard_name', 'web')->whereKey($role->getKey())->lockForUpdate()->first();
            if ($role !== null && $current === null) {
                throw new AuthorizationException;
            }
            $result = $operation($current);
            DB::table('authorization_changes')->insert([
                'organization_id' => $this->context->organizationId(), 'actor_id' => $this->context->actorId(),
                'event' => $event, 'role_id' => $current?->id ?? ($result instanceof Role ? $result->id : null),
                'subject_user_id' => $member?->getKey(), 'created_at' => now(),
            ]);
            $member?->unsetRelation('roles')->unsetRelation('permissions');

            return $result;
        });
    }

    private function requireContext(): void
    {
        $actor = User::query()->find($this->context->actorId());
        if (! $this->context->verifiedFor($actor)) {
            throw new AuthorizationException;
        }
    }

    private function verifiedMember(User $member): User
    {
        if (! $member->exists || ! OrganizationMembership::query()->where('user_id', $member->getKey())
            ->where('organization_id', $this->context->organizationId())->lockForUpdate()->first()) {
            throw new AuthorizationException;
        }

        return User::query()->findOrFail($member->getKey());
    }

    private function validName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 125) {
            throw new InvalidArgumentException('Role names must contain 1 to 125 characters.');
        }

        return $name;
    }
}
