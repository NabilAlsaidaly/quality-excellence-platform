<?php

namespace App\Http\Controllers\Institution;

use App\Actions\Approaches\ListApproaches;
use App\Actions\Approaches\SaveApproachContentRevision;
use App\Http\Controllers\Controller;
use App\Http\Requests\Institution\StoreApproachContentRevisionRequest;
use App\Models\Approach;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Unregistered draft adapter, with independent read/write Policy checks. */
final class ApproachContentController extends Controller
{
    public function show(Request $request, ListApproaches $reads)
    {
        $actor = ApproachController::authorizeRequest($request, 'viewDraftHistory');
        $data = Validator::make($request->query(), ['page' => 'sometimes|integer|min:1|max:1000000'])->validate();

        return view('institution.approaches.content', [
            'operational' => true, 'organization' => $request->route('organization'), 'resource' => $request->route('approach'),
            'canEdit' => Gate::forUser($actor)->allows('editDraft', [Approach::class, $request->route('approach')]),
            'revisions' => $reads->draftHistory($actor, $request->route('approach'), (int) ($data['page'] ?? 1)),
        ]);
    }

    public function store(StoreApproachContentRevisionRequest $request, SaveApproachContentRevision $save)
    {
        $actor = ApproachController::authorizeRequest($request, 'editDraft');
        try {
            $save->execute($actor, $request->route('approach'), $request->validated('body'));
        } catch (ValidationException $error) {
            throw $error->redirectTo(ApproachController::destination($request, 'content'));
        }

        return redirect(ApproachController::destination($request, 'content'))->with('status', __('institution_approaches.draft_saved', [], 'ar'));
    }
}
