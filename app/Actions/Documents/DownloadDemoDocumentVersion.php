<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\User;
use App\Services\Documents\DemoDocumentAvailability;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class DownloadDemoDocumentVersion
{
    public function execute(?User $actor, string $documentId, string $versionId): StreamedResponse
    {
        $boundary = app(DocumentFileWorkspace::class)->intakeBoundary();
        $stream = null;
        try {
            $metadata = $boundary->within($actor, 'download_demo_unscanned', $documentId,
                function (User $current, Document $document, $organization, array $proof) use ($boundary, $versionId, &$stream): array {
                    if (! Str::isUlid($versionId)) {
                        throw new AuthorizationException;
                    }
                    $version = DB::table('document_versions')->where('organization_id', $document->organization_id)
                        ->where('document_id', $document->id)->where('public_id', $versionId)->lockForUpdate()->first() ?? throw new AuthorizationException;
                    $intake = DB::table('document_intakes')->where('organization_id', $document->organization_id)->where('fixture_id', $proof['fixture_id'])
                        ->where('document_id', $document->id)->where('version_id', $version->id)->lockForUpdate()->first() ?? throw new AuthorizationException;
                    app(DemoDocumentAvailability::class)->assertAvailable($current, $document, $intake, $versionId);
                    if (! mb_check_encoding($version->original_filename, 'UTF-8') || trim($version->original_filename) === '' || mb_strlen($version->original_filename) > 255
                        || preg_match('/[\/\\\\\x00-\x1F\x7F]/u', $version->original_filename)) {
                        throw new AuthorizationException;
                    }
                    $stream = $boundary->openVerified($boundary->receipt($intake));
                    $boundary->event($intake, $current, 'downloaded', (string) Str::ulid(), ['mode' => 'available_demo_unscanned',
                        'sha256' => $version->sha256, 'byte_size' => $version->byte_size, 'phase' => 'authorized_stream_opened']);

                    return ['name' => $version->original_filename, 'type' => $intake->inspected_media_type, 'bytes' => $version->byte_size];
                }, $versionId);

            return response()->streamDownload(function () use ($stream): void {
                try {
                    fpassthru($stream);
                } finally {
                    fclose($stream);
                }
            }, $metadata['name'], ['Content-Type' => $metadata['type'], 'Content-Length' => (string) $metadata['bytes'], 'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff', 'X-Qep-Demo-Availability' => 'unscanned-not-security-verified']);
        } catch (Throwable $error) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if ($error instanceof AuthorizationException) {
                app(ManageDocumentFileOperations::class)->recordDenied($actor, 'download_demo_unscanned', documentId: $documentId, versionId: $versionId);
            }
            throw $error;
        }
    }
}
