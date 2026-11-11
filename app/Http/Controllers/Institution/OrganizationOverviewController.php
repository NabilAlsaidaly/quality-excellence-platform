<?php

declare(strict_types=1);

namespace App\Http\Controllers\Institution;

use App\Actions\OrganizationalUnits\AuthorizedUnitOperations;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

final class OrganizationOverviewController extends Controller
{
    public function index(
        string $organization,
        AuthorizedUnitOperations $operations,
    ): View {
        $actor = Auth::guard('institution')->user();

        $metadata = $operations->readMetadata($actor, $organization);

        return view('institution.organization.overview', [
            'metadata' => $metadata,
        ]);
    }
}
