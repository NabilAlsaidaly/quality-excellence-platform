<?php

namespace Tests\Support;

use App\Actions\Documents\DocumentFileWorkspace;
use App\Authorization\DocumentWorkspaceAccess;
use App\Domain\Documents\Intake\DocumentInspectionLimits;
use App\Services\Documents\InspectDocumentFile;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Signed selectors never replace current institutional session and actual Policy checks. */
final class ClosureDocumentUploadAuthorization
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(app()->environment('testing') && DocumentWorkspaceAccess::hasFileScope()
            && $request->isMethod('POST') && $request->hasValidRelativeSignature()
            && in_array($request->ip(), ['127.0.0.1', '127.0.0.6'], true)
            && strtolower($request->getHost()) === strtolower((string) parse_url(config('app.url'), PHP_URL_HOST)), 403);
        $actor = Auth::guard('institution')->user();
        abort_unless($actor !== null && $request->query('closure_actor') === $actor->public_id
            && $request->query('closure_fixture') === DocumentWorkspaceAccess::context()['fixture_id'], 403);
        $check = function () use ($request): void {
            $workspace = app(DocumentFileWorkspace::class);
            $permits = array_merge($workspace->permits('upload_real'), $workspace->permits('append_real'));
            abort_unless(collect($permits)->contains('grant_public_id', $request->query('closure_grant')), 403);
        };
        $check();
        $files = $request->file('files');
        abort_unless(is_array($files) && count($files) === 1 && isset($files[0]), 422);
        (new InspectDocumentFile)->execute($files[0], new DocumentInspectionLimits(30000000, 60000000, 100));
        $check();

        return (new ClosureDocumentUploadContext)->handle($request, $next);
    }
}
