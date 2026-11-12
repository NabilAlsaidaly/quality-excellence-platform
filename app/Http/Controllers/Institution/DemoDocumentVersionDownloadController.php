<?php

namespace App\Http\Controllers\Institution;

use App\Actions\Documents\DownloadDemoDocumentVersion;
use App\Authorization\DocumentWorkspaceAccess;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Registered exclusively by the owned TEST_ONLY provider. No normal route exists. */
final class DemoDocumentVersionDownloadController
{
    public function __invoke(string $document, string $version): StreamedResponse
    {
        abort_unless(app()->environment('testing') && DocumentWorkspaceAccess::hasFileScope(), 404);

        return app(DownloadDemoDocumentVersion::class)->execute(Auth::guard('institution')->user(), $document, $version);
    }
}
