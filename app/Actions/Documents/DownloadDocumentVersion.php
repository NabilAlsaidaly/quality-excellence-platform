<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/** No route invokes this Action until separately approved Policy/grant/route integration. */
final class DownloadDocumentVersion
{
    public function __construct(private RegisterDocumentIntake $boundary) {}

    public function execute(?User $actor, string $documentId, string $versionId): StreamedResponse
    {
        $stream = null;
        try {
            $metadata = $this->boundary->within($actor, 'download_real', $documentId,
                function (User $current, Document $document, Organization $organization, array $proof) use ($versionId, &$stream): array {
                    if (! Str::isUlid($versionId)) {
                        throw new AuthorizationException;
                    }
                    $version = DB::table('document_versions')->where('organization_id', $document->organization_id)
                        ->where('document_id', $document->id)->where('public_id', $versionId)->lockForUpdate()->first() ?? throw new AuthorizationException;
                    $intake = DB::table('document_intakes')->where('organization_id', $document->organization_id)
                        ->where('fixture_id', $proof['fixture_id'])->where('document_id', $document->id)->where('version_id', $version->id)->lockForUpdate()->first();
                    if ($intake === null || $intake->state !== 'released' || $intake->released_at === null
                        || $intake->release_actor_id === null || $intake->release_authority_ref === null) {
                        throw new AuthorizationException;
                    }
                    RegisterDocumentIntake::assertCleanScan($intake);
                    if (! mb_check_encoding($version->original_filename, 'UTF-8') || trim($version->original_filename) === '' || mb_strlen($version->original_filename) > 255
                        || preg_match('/[\/\\\\\x00-\x1F\x7F]/u', $version->original_filename)
                        || ! in_array($intake->inspected_media_type, ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], true)) {
                        throw new AuthorizationException;
                    }
                    $stream = $this->boundary->openVerified($this->boundary->receipt($intake));
                    $this->boundary->event($intake, $current, 'downloaded', (string) Str::ulid(), ['sha256' => $version->sha256, 'phase' => 'authorized_stream_opened']);

                    return ['filename' => $version->original_filename, 'type' => $intake->inspected_media_type];
                });

            return response()->streamDownload(function () use ($stream): void {
                try {
                    fpassthru($stream);
                } finally {
                    fclose($stream);
                }
            }, $metadata['filename'], ['Content-Type' => $metadata['type'], 'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff']);
        } catch (Throwable $error) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            throw $error;
        }
    }
}
