<?php

namespace App\Filament\Institution\Approaches;

use App\Actions\Approaches\ChangeApproachOwner;
use App\Actions\Approaches\CreateApproach;
use App\Actions\Approaches\ListApproaches;
use App\Actions\Approaches\SaveApproachContentRevision;
use App\Actions\Approaches\UpdateApproach;
use App\Authorization\TenantContext;
use App\Models\Approach;
use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Policies\ApproachPolicy;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Presentation application adapter; existing Actions remain domain source of truth. */
final class ApproachAccess
{
    public function canEnter(?User $actor): bool
    {
        return $actor !== null && $actor->principal_type === 'institution'
            && (int) Auth::guard('institution')->id() === (int) $actor->getKey()
            && $this->can('list');
    }

    private function within(string $operation, ?string $publicId, Closure $callback): mixed
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new AuthorizationException;
        }
        $session = app('session.store');
        $guard = Auth::guard('institution');
        $cached = $guard->user();
        $actor = $cached ? $guard->getProvider()->retrieveById($cached->getAuthIdentifier()) : null;
        $organization = $session->get('institution.organization');
        if ($actor?->principal_type !== 'institution' || ! is_string($organization) || ! Str::isUlid($organization)
            || $session->get('identity.active_guard') !== 'institution'
            || ! hash_equals($actor->getAuthPassword(), (string) $session->get('institution.password_hash'))) {
            throw new AuthorizationException;
        }

        return app(TenantContext::class)->run($actor, $organization, fn () => app(CreateApproach::class)->withinContext($actor,
            function (int $organizationId, User $current) use ($operation, $publicId, $callback, $actor): mixed {
                // Login freshness checked before waiting for the lock can age.
                // The existing boundary supplies the current locked User row.
                if (! hash_equals($current->getAuthPassword(), $actor->getAuthPassword())) {
                    throw new AuthorizationException;
                }
                $resource = null;
                if ($publicId !== null) {
                    $resource = Str::isUlid($publicId) ? Approach::query()->where('organization_id', $organizationId)
                        ->where('public_id', $publicId)->lockForUpdate()->first() : null;
                    if ($resource === null) {
                        throw (new ModelNotFoundException)->setModel(Approach::class);
                    }
                }
                if (! app(ApproachPolicy::class)->allowsOperation($current, $operation, $resource)) {
                    throw new AuthorizationException;
                }

                return $callback($current, $resource);
            }));
    }

    public function can(string $operation, ?string $publicId = null): bool
    {
        try {
            return $this->within($operation, $publicId, fn () => true);
        } catch (AuthorizationException|ModelNotFoundException) {
            return false;
        }
    }

    public function listing(string $search = '', string $sort = 'created_at', string $direction = 'desc', int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        // list is its own organization-wide metadata grant in synthetic tests;
        // it never grants detail, draft, audit or mutations for any resource.
        return $this->within('list', null, function (User $actor) use ($search, $sort, $direction, $page, $perPage): LengthAwarePaginator {
            $records = app(ListApproaches::class)->execute($actor, $search, $sort, $direction, $page, $perPage);
            $viewable = app(ApproachPolicy::class)->viewablePublicIds($actor, $records->getCollection()->pluck('public_id')->all());
            $records->setCollection($records->getCollection()->map(fn (array $record) => $record + ['can_view' => in_array($record['public_id'], $viewable, true)]));

            return $records;
        });
    }

    /** Bounded server-side selector; mutation rules stay in existing domain Actions. */
    public function searchOwners(string $operation, ?string $publicId = null, string $search = '', ?string $selected = null): array
    {
        if (! in_array($operation, ['create', 'changeOwner'], true)) {
            throw new AuthorizationException;
        }

        return $this->within($operation, $publicId, function () use ($search, $selected): array {
            if (! mb_check_encoding($search, 'UTF-8') || mb_strlen($search) > 255 || ($selected !== null && ! Str::isUlid($selected))) {
                throw ValidationException::withMessages(['owning_unit_public_id' => 'معايير البحث غير صالحة.']);
            }
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';

            return OrganizationalUnit::query()->where('organization_id', app(TenantContext::class)->organizationId())
                ->when($selected !== null, fn ($query) => $query->where('public_id', $selected))
                ->when($search !== '', fn ($query) => $query->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]))
                ->orderBy('name')->orderBy('id')->limit(20)->pluck('name', 'public_id')->all();
        });
    }

    public function show(string $publicId): array
    {
        return $this->within('view', $publicId, fn (User $actor) => app(ListApproaches::class)->show($actor, $publicId));
    }

    public function create(array $data): string
    {
        return $this->within('create', null, fn (User $actor) => app(CreateApproach::class)->execute($actor, $data)->public_id);
    }

    public function update(string $publicId, array $data, string $token): void
    {
        $this->within('updateMetadata', $publicId, fn (User $actor) => app(UpdateApproach::class)->execute($actor, $publicId, $data, $token));
    }

    public function changeOwner(string $publicId, string $owner, string $token): void
    {
        $this->within('changeOwner', $publicId, fn (User $actor) => app(ChangeApproachOwner::class)->execute($actor, $publicId, $owner, $token));
    }

    public function ownerOptions(string $operation, ?string $publicId = null, int $page = 1): array
    {
        if (! in_array($operation, ['create', 'changeOwner'], true)) {
            throw new AuthorizationException;
        }

        return $this->within($operation, $publicId, fn (User $actor) => app(ListApproaches::class)->ownerOptions($actor, $page)->getCollection()->pluck('name', 'public_id')->all());
    }

    public function history(string $publicId, string $type, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        if (! in_array($type, ['draft', 'audit'], true)) {
            throw new AuthorizationException;
        }

        return $this->within($type === 'draft' ? 'viewDraftHistory' : 'viewAuditHistory', $publicId,
            fn (User $actor) => $type === 'draft' ? app(ListApproaches::class)->draftHistory($actor, $publicId, $page, $perPage)
                : app(ListApproaches::class)->changeHistory($actor, $publicId, $page, $perPage));
    }

    public function draftToken(string $publicId): string
    {
        return $this->within('editDraft', $publicId, fn (User $actor, Approach $resource) => $this->latestDraftToken($resource));
    }

    public function draftForm(string $publicId): array
    {
        return $this->within('editDraft', $publicId, function (User $actor, Approach $resource) use ($publicId): array {
            $token = $this->latestDraftToken($resource);
            $body = app(ApproachPolicy::class)->allowsOperation($actor, 'viewDraftHistory', $resource)
                ? (app(ListApproaches::class)->draftHistory($actor, $publicId, perPage: 1)->first()['body'] ?? '') : '';

            return ['draft_token' => $token, 'body' => $body];
        });
    }

    public function saveDraft(string $publicId, string $body, string $expectedRevision): void
    {
        $this->within('editDraft', $publicId, function (User $actor, Approach $resource) use ($publicId, $body, $expectedRevision): void {
            if ($expectedRevision !== 'none' && ! Str::isUlid($expectedRevision)) {
                throw ValidationException::withMessages(['draft_token' => __('approach_workspace.reopen')]);
            }
            if (! hash_equals($this->latestDraftToken($resource), $expectedRevision)) {
                throw new ConflictHttpException(__('approach_workspace.conflict'));
            }
            app(SaveApproachContentRevision::class)->execute($actor, $publicId, $body);
        });
    }

    private function latestDraftToken(Approach $resource): string
    {
        return $resource->revisions()->orderByDesc('version_number')->lockForUpdate()->first()?->public_id ?? 'none';
    }
}
