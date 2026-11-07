<?php

namespace App\Actions\Approaches;

use App\Models\Approach;
use App\Models\ApproachChange;
use App\Models\OrganizationalUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** @internal Trusted read model. Mount only behind an approved scoped Policy. */
final class ListApproaches
{
    public function __construct(private CreateApproach $boundary, private UpdateApproach $updates) {}

    public function execute(?User $actor, string $search = '', string $sort = 'created_at', string $direction = 'desc', int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        return $this->boundary->withinContext($actor, function (int $organizationId) use ($search, $sort, $direction, $page, $perPage): LengthAwarePaginator {
            if (! in_array($sort, ['name', 'created_at'], true) || ! in_array($direction, ['asc', 'desc'], true)
                || $page < 1 || $page > 1000000 || $perPage < 1 || $perPage > 100 || mb_strlen($search) > 255
                || ! mb_check_encoding($search, 'UTF-8')) {
                throw ValidationException::withMessages(['search' => 'معايير البحث أو التصفح غير صالحة.']);
            }
            // ! escapes LIKE metacharacters identically on both supported engines.
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $result = Approach::query()->where('organization_id', $organizationId)
                ->with(['owningUnit' => fn ($query) => $query->where('organization_id', $organizationId)])
                ->when($search !== '', fn ($query) => $query->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]))
                ->orderBy($sort, $direction)->orderBy('id', $direction)->paginate($perPage, ['*'], 'page', $page);
            // No request URL/query is trusted or copied into pagination links.
            $result->withPath('');
            $result->setCollection($result->getCollection()->map(fn ($approach) => $this->metadata($approach)));

            return $result;
        });
    }

    public function show(?User $actor, string $publicId): array
    {
        return $this->boundary->withinContext($actor, function (int $organizationId) use ($publicId): array {
            $approach = $this->find($organizationId, $publicId);

            return self::metadata($approach) + ['edit_token' => $this->updates->editToken($approach)];
        });
    }

    /** @internal Draft-reader assignment Policy is required before any future HTTP caller. */
    public function draftHistory(?User $actor, string $publicId, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        return $this->boundary->withinContext($actor, function (int $organizationId) use ($publicId, $page, $perPage): LengthAwarePaginator {
            if ($page < 1 || $page > 1000000 || $perPage < 1 || $perPage > 20) {
                throw ValidationException::withMessages(['page' => 'معايير تصفح الإصدارات غير صالحة.']);
            }
            $approach = $this->find($organizationId, $publicId);
            $history = $approach->revisions()->with('actor:id,public_id')->orderByDesc('version_number')
                ->paginate($perPage, ['*'], 'page', $page)->withPath('');
            $history->setCollection($history->getCollection()->map(fn ($revision) => [
                'public_id' => $revision->public_id, 'version_number' => $revision->version_number,
                'body' => $revision->body, 'created_at' => $revision->created_at->toIso8601String(),
                // Legacy accounts have no public ULID; retain their actual FK internally.
                'author_public_id' => $revision->actor->public_id,
            ]));

            return $history;
        });
    }

    private function find(int $organizationId, string $publicId): Approach
    {
        $approach = Str::isUlid($publicId) ? Approach::query()->where('organization_id', $organizationId)
            ->where('public_id', $publicId)->with(['owningUnit' => fn ($query) => $query->where('organization_id', $organizationId)->lockForUpdate()])
            ->lockForUpdate()->first() : null;
        if ($approach === null) {
            throw (new ModelNotFoundException)->setModel(Approach::class);
        }

        return $approach;
    }

    public static function metadata(Approach $approach): array
    {
        return ['public_id' => $approach->public_id, 'name' => $approach->name,
            'description' => $approach->description, 'purpose' => $approach->purpose, 'scope' => $approach->scope,
            'objectives' => $approach->objectives, 'review_frequency' => $approach->review_frequency,
            'owning_unit' => ['public_id' => $approach->owningUnit->public_id, 'name' => $approach->owningUnit->name],
            'created_at' => $approach->created_at->toIso8601String()];
    }

    /** @internal Requires a distinct audit-history read grant at an HTTP boundary. */
    public function changeHistory(?User $actor, string $publicId, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        return $this->boundary->withinContext($actor, function (int $organizationId) use ($publicId, $page, $perPage): LengthAwarePaginator {
            if ($page < 1 || $page > 1000000 || $perPage < 1 || $perPage > 20) {
                throw ValidationException::withMessages(['page' => 'معايير تصفح السجل غير صالحة.']);
            }
            $approach = $this->find($organizationId, $publicId);
            $history = ApproachChange::query()->where('organization_id', $organizationId)->where('approach_id', $approach->id)
                ->with('actor:id,public_id')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page)->withPath('');
            $history->setCollection($history->getCollection()->map(function (ApproachChange $change): array {
                $fields = match ($change->event) {
                    'metadata_updated' => UpdateApproach::FIELDS,
                    'owner_changed' => ['public_id', 'name'],
                    default => throw new \RuntimeException('Unrecognized Approach audit event.'),
                };
                $states = [];
                foreach (['before_state', 'after_state'] as $state) {
                    $source = $change->getAttribute($state);
                    if (! is_array($source) || array_diff($fields, array_keys($source)) !== [] || array_diff(array_keys($source), $fields) !== []) {
                        throw new \RuntimeException('Invalid Approach audit state.');
                    }
                    $states[$state] = [];
                    foreach ($fields as $field) {
                        if (! is_string($source[$field]) && $source[$field] !== null) {
                            throw new \RuntimeException('Invalid Approach audit value.');
                        }
                        $states[$state][$field] = $source[$field];
                    }
                }

                return ['public_id' => $change->public_id, 'event' => $change->event,
                    'actor_public_id' => $change->actor->public_id, 'created_at' => $change->created_at->toIso8601String()] + $states;
            }));

            return $history;
        });
    }

    /** Scoped, bounded selector; no IDs are accepted as authorization authority. */
    public function ownerOptions(?User $actor, int $page = 1): LengthAwarePaginator
    {
        return $this->boundary->withinContext($actor, function (int $organizationId) use ($page): LengthAwarePaginator {
            if ($page < 1 || $page > 1000000) {
                throw ValidationException::withMessages(['page' => 'معايير التصفح غير صالحة.']);
            }
            $units = OrganizationalUnit::query()->where('organization_id', $organizationId)->orderBy('name')->orderBy('id')
                ->paginate(100, ['public_id', 'name'], 'page', $page)->withPath('');
            $units->setCollection($units->getCollection()->map(fn ($unit) => ['public_id' => $unit->public_id, 'name' => $unit->name]));

            return $units;
        });
    }
}
