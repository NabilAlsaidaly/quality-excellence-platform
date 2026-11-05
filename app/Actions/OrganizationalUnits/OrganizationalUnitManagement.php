<?php

namespace App\Actions\OrganizationalUnits;

use App\Authorization\TenantContext;
use App\Models\Organization;
use App\Models\OrganizationalUnit;
use App\Models\OrganizationMembership;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * @internal No application-user entry point. Future callers must first enforce
 * an owner-approved management Policy; membership is only structural eligibility.
 */
final class OrganizationalUnitManagement
{
    public function __construct(private TenantContext $context) {}

    public function create(?User $actor, string $name, ?string $parentPublicId = null): OrganizationalUnit
    {
        return $this->withinContext($actor, function () use ($name, $parentPublicId): OrganizationalUnit {
            $parent = $parentPublicId === null ? null : $this->findUnit($parentPublicId);
            $this->validateAncestry($parent?->id);
            $unit = new OrganizationalUnit(['name' => $this->validName($name)]);
            $unit->organization_id = $this->context->organizationId();
            $unit->parent_id = $parent?->id;
            $unit->save();
            $this->record($unit, 'created', null, $unit->parent_id, null, $unit->name);

            return $unit;
        });
    }

    public function rename(?User $actor, string $unitPublicId, string $name): OrganizationalUnit
    {
        return $this->withinContext($actor, function () use ($unitPublicId, $name): OrganizationalUnit {
            $unit = $this->findUnit($unitPublicId);
            $this->validateAncestry($unit->parent_id, $unit->id);
            $name = $this->validName($name);
            if ($name === $unit->name) {
                return $unit;
            }
            $previous = $unit->name;
            $unit->name = $name;
            $unit->save();
            $this->record($unit, 'renamed', $unit->parent_id, $unit->parent_id, $previous, $name);

            return $unit;
        });
    }

    public function move(?User $actor, string $unitPublicId, ?string $parentPublicId): OrganizationalUnit
    {
        return $this->withinContext($actor, function () use ($unitPublicId, $parentPublicId): OrganizationalUnit {
            $unit = $this->findUnit($unitPublicId);
            // A root transition/no-op must not silently repair existing corruption.
            $this->validateAncestry($unit->parent_id, $unit->id);
            $parent = $parentPublicId === null ? null : $this->findUnit($parentPublicId);
            $this->validateAncestry($parent?->id, $unit->id);
            if ($unit->parent_id === $parent?->id) {
                return $unit;
            }
            $previous = $unit->parent_id;
            $unit->parent_id = $parent?->id;
            $unit->save();
            $this->record($unit, 'reparented', $previous, $unit->parent_id);

            return $unit;
        });
    }

    /**
     * Internal read model: public-ULID keyed nodes and ordered references.
     * The id field is internal only; a future public serializer must omit it.
     *
     * @return array{roots: list<string>, nodes: array<string, array>}
     */
    public function tree(?User $actor): array
    {
        return $this->withinContext($actor, function (): array {
            // One units query, current even inside an older REPEATABLE READ snapshot.
            $units = OrganizationalUnit::query()->where('organization_id', $this->context->organizationId())
                ->orderBy('id')->lockForUpdate()->get(['id', 'public_id', 'parent_id', 'name'])->keyBy('id');
            $complete = [];
            foreach ($units as $unit) {
                $path = [];
                $cursor = $unit->id;
                while ($cursor !== null && ! isset($complete[$cursor])) {
                    if (isset($path[$cursor]) || ! isset($units[$cursor])) {
                        throw new InvalidHierarchy;
                    }
                    $path[$cursor] = true;
                    $cursor = $units[$cursor]->parent_id;
                }
                foreach ($path as $id => $_) {
                    $complete[$id] = true;
                }
            }

            $roots = [];
            $nodes = [];
            foreach ($units as $unit) {
                $nodes[$unit->public_id] = [
                    'id' => $unit->id, 'public_id' => $unit->public_id, 'name' => $unit->name,
                    'parent_public_id' => $unit->parent_id === null ? null : $units[$unit->parent_id]->public_id,
                    'children' => [],
                ];
            }
            foreach ($units as $unit) {
                if ($unit->parent_id === null) {
                    $roots[] = $unit->public_id;
                } else {
                    $nodes[$units[$unit->parent_id]->public_id]['children'][] = $unit->public_id;
                }
            }

            return ['roots' => $roots, 'nodes' => $nodes];
        });
    }

    private function withinContext(?User $actor, Closure $operation): mixed
    {
        if ($actor === null || ! $actor->exists || $this->context->organizationId() === null
            || $this->context->actorId() !== (int) $actor->getKey()) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $operation): mixed {
            // Every supported operation uses this common lock order. Never make
            // ancestry decisions from an earlier ordinary MVCC snapshot.
            $organization = Organization::query()->whereKey($this->context->organizationId())->lockForUpdate()->first();
            $user = User::query()->whereKey($actor->getKey())->lockForUpdate()->first();
            $membership = OrganizationMembership::query()->where('user_id', $actor->getKey())
                ->where('organization_id', $this->context->organizationId())->lockForUpdate()->first();
            if ($organization === null || $user === null || $membership === null || ! $this->context->verifiedFor($actor)) {
                throw new AuthorizationException;
            }

            return $operation();
        });
    }

    private function findUnit(string $publicId): OrganizationalUnit
    {
        $unit = Str::isUlid($publicId) ? OrganizationalUnit::query()
            ->where('organization_id', $this->context->organizationId())->where('public_id', $publicId)
            ->lockForUpdate()->first() : null;
        if ($unit === null) {
            // Identical response for foreign, missing and malformed identifiers.
            throw (new ModelNotFoundException)->setModel(OrganizationalUnit::class);
        }

        return $unit;
    }

    private function validateAncestry(?int $parentId, ?int $movingId = null): void
    {
        $visited = [];
        while ($parentId !== null) {
            if ($parentId === $movingId || isset($visited[$parentId])) {
                throw new InvalidHierarchy;
            }
            $visited[$parentId] = true;
            $parent = OrganizationalUnit::query()->where('organization_id', $this->context->organizationId())
                ->whereKey($parentId)->lockForUpdate()->first(['id', 'parent_id']);
            if ($parent === null) {
                throw new InvalidHierarchy;
            }
            $parentId = $parent->parent_id;
        }
    }

    private function validName(string $name): string
    {
        $name = trim($name);
        if (preg_match('/[^\\p{Z}\\s]/u', $name) !== 1 || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['name' => 'A name of 1 to 255 characters is required.']);
        }

        return $name;
    }

    private function record(
        OrganizationalUnit $unit,
        string $event,
        ?int $previousParent,
        ?int $newParent,
        ?string $previousName = null,
        ?string $newName = null,
    ): void {
        DB::table('organizational_unit_changes')->insert([
            'organization_id' => $this->context->organizationId(), 'actor_id' => $this->context->actorId(),
            'unit_id' => $unit->id, 'event' => $event,
            'previous_parent_id' => $previousParent, 'new_parent_id' => $newParent,
            'previous_name' => $previousName, 'new_name' => $newName, 'created_at' => now(),
        ]);
    }
}
