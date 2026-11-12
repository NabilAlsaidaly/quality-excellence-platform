<?php

namespace App\Actions\Documents;

use App\Authorization\DocumentWorkspaceAccess;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\Documents\DemoDocumentAvailability;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Exact accessible immutable bytes are a candidate, never an adequacy/approval decision. */
final class AuthorizedDocumentEvidence
{
    public function execute(User $actor, array $reference): array
    {
        $proof = DocumentWorkspaceAccess::context() ?? throw new AuthorizationException;
        $document = Document::where('organization_id', $proof['organization_id'])->where('public_id', $reference['document_public_id'])->lockForUpdate()->first() ?? throw new AuthorizationException;
        $access = app(DocumentWorkspaceAccess::class);
        $access->authorize($actor, 'view', $document);
        $access->authorize($actor, 'history', $document);
        $version = DocumentVersion::where('organization_id', $document->organization_id)->where('document_id', $document->id)
            ->where('public_id', $reference['version_public_id'])->lockForUpdate()->first() ?? throw new AuthorizationException;
        $intake = DB::table('document_intakes')->where('organization_id', $document->organization_id)->where('fixture_id', $proof['fixture_id'])
            ->where('document_id', $document->id)->where('version_id', $version->id)->lockForUpdate()->first() ?? throw new AuthorizationException;
        if ($intake->state === 'released') {
            $access->authorize($actor, 'download_real', $document, $version->public_id);
            if ($intake->release_actor_id === null || $intake->released_at === null || $intake->release_authority_ref === null) {
                throw new AuthorizationException;
            }
            RegisterDocumentIntake::assertCleanScan($intake);
        } else {
            app(DemoDocumentAvailability::class)->assertAvailable($actor, $document, $intake, $version->public_id);
        }
        $boundary = app(DocumentFileWorkspace::class)->intakeBoundary();
        fclose($boundary->openVerified($boundary->receipt($intake)));

        return [$document, $version];
    }
}
