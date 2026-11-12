<?php

namespace Tests\Support;

use App\Authorization\DocumentWorkspaceAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Only the explicitly registered TEST_ONLY provider uses this request-local storage context. */
final class ClosureDocumentUploadContext
{
    public function handle(Request $request, Closure $next)
    {
        if (! DocumentWorkspaceAccess::hasFileScope()) {
            return $next($request);
        }
        $actor = Auth::guard('institution')->user();
        abort_unless($actor !== null, 403);
        $old = config('livewire.temporary_file_upload');
        config(['livewire.temporary_file_upload' => array_replace($old ?? [], [
            'disk' => 'local', 'directory' => 'closure-temporary/'.DocumentWorkspaceAccess::context()['fixture_id'].'/'.$actor->public_id,
            'rules' => ['required', 'file', 'max:29297'], 'cleanup' => false,
        ])]);
        try {
            return $next($request);
        } finally {
            config(['livewire.temporary_file_upload' => $old]);
        }
    }
}
