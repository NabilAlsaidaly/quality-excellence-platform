<?php

namespace App\Services\Documents;

use App\Authorization\DocumentWorkspaceAccess;
use App\Models\Document;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Truthful local exception; never substitutes for a clean scan or institutional approval. */
final class DemoDocumentAvailability
{
    public function assertUnscanned(object $intake): void
    {
        $proof = DocumentWorkspaceAccess::context();
        if (! DocumentWorkspaceAccess::hasFileScope() || ($proof['demo_unscanned_exception'] ?? false) !== true
            || DB::transactionLevel() === 0 || (int) $intake->organization_id !== $proof['organization_id']
            || $intake->fixture_id !== $proof['fixture_id'] || $intake->scenario !== 'DOCUMENT_LOCAL_001' || $intake->classification !== 'TEST_ONLY'
            || $intake->state !== 'pending' || $intake->scan_result !== null || (int) $intake->generation !== 1
            || DB::table('document_intake_events')->where('organization_id', $intake->organization_id)->where('fixture_id', $intake->fixture_id)
                ->where('intake_id', $intake->id)->where('event', '!=', 'registered')->where('event', '!=', 'downloaded')->lockForUpdate()->exists()) {
            throw new AuthorizationException('هذه النسخة غير مؤهلة للاستثناء المحلي.');
        }
        $version = DB::table('document_versions')->where('organization_id', $intake->organization_id)->where('document_id', $intake->document_id)
            ->where('id', $intake->version_id)->lockForUpdate()->first() ?? throw new AuthorizationException;
        $known = $proof['demo_file_receipts'][$version->sha256] ?? null;
        if (! is_array($known) || ($known['byte_size'] ?? null) !== $version->byte_size
            || ($known['media_type'] ?? null) !== $intake->inspected_media_type) {
            // A human decision cannot turn arbitrary unscanned bytes into an
            // approved synthetic sample. The request cannot supply this proof.
            throw new AuthorizationException('الاستثناء محصور في عينات العرض الاصطناعية المعدّة مسبقًا.');
        }
    }

    public function assertAvailable(User $actor, Document $document, object $intake, string $versionPublicId): object
    {
        $this->assertUnscanned($intake);
        $access = app(DocumentWorkspaceAccess::class);
        $access->authorize($actor, 'download_demo_unscanned', $document, $versionPublicId);
        $decision = DB::table('document_demo_availability_decisions')->where('organization_id', $intake->organization_id)
            ->where('fixture_id', $intake->fixture_id)->where('document_id', $document->id)->where('version_id', $intake->version_id)
            ->where('intake_id', $intake->id)->where('state', 'available_demo_unscanned')->lockForUpdate()->first() ?? throw new AuthorizationException;
        $proof = DocumentWorkspaceAccess::context();
        if ((int) $decision->reviewer_id !== $proof['reader_id'] || (int) $decision->reviewer_id === (int) $intake->actor_id
            || (int) $decision->reviewer_id === (int) $document->created_by
            || ! DB::table('document_file_operation_assignments')->where('id', $decision->authority_id)->where('organization_id', $document->organization_id)
                ->where('fixture_id', $proof['fixture_id'])->where('grantee_id', $decision->reviewer_id)->where('operation', 'release_demo_unscanned')
                ->where('document_id', $document->id)->where('version_id', $intake->version_id)->whereNull('revoked_at')
                ->where('starts_at', '<=', DocumentWorkspaceAccess::timestamp())->where('expires_at', '>', DocumentWorkspaceAccess::timestamp())->lockForUpdate()->exists()) {
            throw new AuthorizationException;
        }

        return $decision;
    }
}
