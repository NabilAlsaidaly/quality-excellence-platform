<?php

namespace App\Http\Controllers\Institution;

use App\Actions\Approaches\ChangeApproachOwner;
use App\Actions\Approaches\CreateApproach;
use App\Actions\Approaches\ListApproaches;
use App\Actions\Approaches\UpdateApproach;
use App\Authorization\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Institution\ChangeApproachOwnerRequest;
use App\Http\Requests\Institution\StoreApproachRequest;
use App\Http\Requests\Institution\UpdateApproachRequest;
use App\Models\Approach;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Unregistered adapter. No operational routes or production Policy are installed. */
final class ApproachController extends Controller
{
    /** A future route MUST also use web + InstitutionSession middleware. */
    public static function authorizeRequest(Request $request, string $ability): User
    {
        $actor = Auth::guard('institution')->user();
        abort_unless($actor instanceof User && $actor->principal_type === 'institution'
            && app(TenantContext::class)->verifiedFor($actor), 403, 'الوصول غير مسموح.');
        foreach (['organization', 'organization_id', 'actor_id'] as $key) {
            abort_if($request->query->has($key), 422, 'معايير الطلب غير صالحة.');
        }
        // Use Gate/Policy on every request. Membership is never the grant.
        // Translate a business denial to HTTP 403 without triggering session logout.
        abort_unless(Gate::forUser($actor)->allows($ability, [Approach::class, $request->route('approach')]), 403, 'الوصول غير مسموح.');

        return $actor;
    }

    public static function destination(Request $request, string $page, bool $resource = true): string
    {
        return route('institution.approaches.'.$page, ['organization' => $request->route('organization')]
            + ($resource ? ['approach' => $request->route('approach')] : []), false);
    }

    public function index(Request $request, ListApproaches $reads)
    {
        $actor = self::authorizeRequest($request, 'viewAny');
        $data = Validator::make($request->query(), ['search' => 'nullable|string|max:255', 'sort' => 'nullable|in:name,created_at',
            'direction' => 'nullable|in:asc,desc', 'page' => 'nullable|integer|min:1|max:1000000'])->validate();
        $approaches = $reads->execute($actor, $data['search'] ?? '', $data['sort'] ?? 'created_at', $data['direction'] ?? 'desc', (int) ($data['page'] ?? 1));
        $approaches->setCollection($approaches->getCollection()->map(fn ($item) => $item + [
            'can_view' => Gate::forUser($actor)->allows('view', [Approach::class, $item['public_id']]),
        ]));

        return view('institution.approaches.index', $this->presentation($request, $actor) + [
            'approaches' => $approaches,
            'search' => $data['search'] ?? '', 'sort' => $data['sort'] ?? 'created_at', 'direction' => $data['direction'] ?? 'desc',
        ]);
    }

    public function create(Request $request, ListApproaches $reads)
    {
        $actor = self::authorizeRequest($request, 'create');

        return view('institution.approaches.create', $this->presentation($request, $actor) + [
            'owners' => $reads->ownerOptions($actor, $this->page($request)),
        ]);
    }

    public function store(StoreApproachRequest $request, CreateApproach $create)
    {
        $actor = self::authorizeRequest($request, 'create');
        try {
            $approach = $create->execute($actor, $request->safe()->all());
        } catch (ValidationException $error) {
            throw $error->redirectTo(self::destination($request, 'create', false));
        }

        return redirect(route('institution.approaches.show', ['organization' => $request->route('organization'), 'approach' => $approach->public_id], false))
            ->with('status', __('institution_approaches.saved', [], 'ar'));
    }

    public function show(Request $request, ListApproaches $reads)
    {
        $actor = self::authorizeRequest($request, 'view');
        $data = $this->presentation($request, $actor);

        return view('institution.approaches.show', $data + [
            'approach' => $reads->show($actor, $request->route('approach')),
            'changes' => $data['can']['viewAuditHistory'] ? $reads->changeHistory($actor, $request->route('approach'), $this->page($request)) : null,
        ]);
    }

    public function edit(Request $request, ListApproaches $reads)
    {
        $actor = self::authorizeRequest($request, 'updateMetadata');

        return view('institution.approaches.edit', $this->presentation($request, $actor) + ['approach' => $reads->show($actor, $request->route('approach'))]);
    }

    public function update(UpdateApproachRequest $request, UpdateApproach $update)
    {
        $actor = self::authorizeRequest($request, 'updateMetadata');
        try {
            $update->execute($actor, $request->route('approach'), $request->safe()->except('edit_token'), $request->validated('edit_token'));
        } catch (ValidationException $error) {
            throw $error->redirectTo(self::destination($request, 'edit'));
        }

        return redirect(self::destination($request, 'show'))->with('status', __('institution_approaches.saved', [], 'ar'));
    }

    public function owner(Request $request, ListApproaches $reads)
    {
        $actor = self::authorizeRequest($request, 'changeOwner');

        return view('institution.approaches.owner', $this->presentation($request, $actor) + [
            'approach' => $reads->show($actor, $request->route('approach')), 'owners' => $reads->ownerOptions($actor, $this->page($request)),
        ]);
    }

    public function transfer(ChangeApproachOwnerRequest $request, ChangeApproachOwner $transfer)
    {
        $actor = self::authorizeRequest($request, 'changeOwner');
        $transfer->execute($actor, $request->route('approach'), $request->validated('owning_unit_public_id'), $request->validated('edit_token'));

        return redirect(self::destination($request, 'show'))->with('status', __('institution_approaches.saved', [], 'ar'));
    }

    private function presentation(Request $request, User $actor): array
    {
        $can = [];
        foreach (['viewAny', 'view', 'create', 'updateMetadata', 'changeOwner', 'editDraft', 'viewDraftHistory', 'viewAuditHistory'] as $ability) {
            $can[$ability] = Gate::forUser($actor)->allows($ability, [Approach::class, $request->route('approach')]);
        }

        return ['operational' => true, 'organization' => $request->route('organization'), 'resource' => $request->route('approach'), 'can' => $can];
    }

    private function page(Request $request): int
    {
        $data = Validator::make($request->query(), ['page' => 'sometimes|integer|min:1|max:1000000'])->validate();

        return (int) ($data['page'] ?? 1);
    }
}
