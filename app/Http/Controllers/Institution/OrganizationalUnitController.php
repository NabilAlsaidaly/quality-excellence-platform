<?php

declare(strict_types=1);

namespace App\Http\Controllers\Institution;

use App\Actions\OrganizationalUnits\AuthorizedUnitOperations;
use App\Actions\OrganizationalUnits\InvalidHierarchy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Institution\MoveOrganizationalUnitRequest;
use App\Http\Requests\Institution\StoreOrganizationalUnitRequest;
use App\Http\Requests\Institution\UpdateOrganizationalUnitRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

final class OrganizationalUnitController extends Controller
{
    public function index(
        string $organization,
        AuthorizedUnitOperations $operations,
    ): View {
        $tree = $operations->tree($this->actor(), $organization);

        return view('institution.organization.units.index', [
            'tree' => $tree,
            'organizationPublicId' => $organization,
        ]);
    }

    public function create(
        string $organization,
        AuthorizedUnitOperations $operations,
    ): View {
        $tree = $operations->tree($this->actor(), $organization);

        return view('institution.organization.units.create', [
            'tree' => $tree,
            'organizationPublicId' => $organization,
        ]);
    }

    public function store(
        StoreOrganizationalUnitRequest $request,
        string $organization,
        AuthorizedUnitOperations $operations,
    ): RedirectResponse {
        $data = $request->validated();

        $operations->create(
            $this->actor(),
            $organization,
            $data['name'],
            $data['parent_public_id'] ?? null,
        );

        return redirect()
            ->route('institution.units.index', ['organization' => $organization])
            ->with('status', __('institution_organization.messages.unit_created'));
    }

    public function edit(
        string $organization,
        string $unit,
        AuthorizedUnitOperations $operations,
    ): View {
        $tree = $operations->tree($this->actor(), $organization);
        $unitData = $tree['nodes'][$unit] ?? abort(404);

        $parentPublicId = $unitData['parent_public_id'];

        $currentParentName = $parentPublicId === null
            ? null
            : ($tree['nodes'][$parentPublicId]['name'] ?? null);

        return view('institution.organization.units.edit', [
            'tree' => $tree,
            'unit' => $unitData,
            'currentParentName' => $currentParentName,
            'organizationPublicId' => $organization,
        ]);
    }

    public function update(
        UpdateOrganizationalUnitRequest $request,
        string $organization,
        string $unit,
        AuthorizedUnitOperations $operations,
    ): RedirectResponse {
        $data = $request->validated();

        $operations->rename(
            $this->actor(),
            $organization,
            $unit,
            $data['name'],
        );

        return redirect()
            ->route('institution.units.index', ['organization' => $organization])
            ->with('status', __('institution_organization.messages.unit_renamed'));
    }

    public function move(
        MoveOrganizationalUnitRequest $request,
        string $organization,
        string $unit,
        AuthorizedUnitOperations $operations,
    ): RedirectResponse {
        $data = $request->validated();

        try {
            $operations->move(
                $this->actor(),
                $organization,
                $unit,
                $data['parent_public_id'] ?? null,
            );
        } catch (InvalidHierarchy) {
            return back()->withErrors([
                'parent_public_id' => __('institution_organization.validation.invalid_hierarchy'),
            ]);
        }

        return redirect()
            ->route('institution.units.index', ['organization' => $organization])
            ->with('status', __('institution_organization.messages.unit_moved'));
    }

    private function actor(): ?User
    {
        $actor = Auth::guard('institution')->user();

        return $actor instanceof User ? $actor : null;
    }
}
