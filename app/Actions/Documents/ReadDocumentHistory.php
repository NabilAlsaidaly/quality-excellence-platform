<?php

namespace App\Actions\Documents;

use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\User;
use App\Services\Documents\PrivateDocumentBlobStore;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ReadDocumentHistory
{
    public function __construct(private CreateDocumentWithVersion $boundary, private PrivateDocumentBlobStore $blobs) {}

    public function execute(?User $actor, string $publicId, string $direction = 'desc', int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        return $this->boundary->withinContext($actor, function (Organization $organization) use ($publicId, $direction, $page, $perPage): LengthAwarePaginator {
            if (! in_array($direction, ['asc', 'desc'], true) || $page < 1 || $page > 1000000 || $perPage < 1 || $perPage > 20) {
                throw ValidationException::withMessages(['page' => 'معايير تصفح الإصدارات غير صالحة.']);
            }
            $document = ListDocuments::find($organization->id, $publicId);
            $history = $document->versions()->orderBy('version_number', $direction)->paginate($perPage, ['*'], 'page', $page)->withPath('');
            $history->setCollection($history->getCollection()->map(fn (DocumentVersion $version) => [
                'document_public_id' => $document->public_id, 'version_public_id' => $version->public_id,
                'version_number' => $version->version_number, 'created_at' => $version->created_at->toIso8601String(),
            ]));

            return $history;
        });
    }

    /** Explicit internal integrity probe. No bytes, path or download response is returned. */
    public function verify(?User $actor, string $documentId, string $versionId): array
    {
        $receipt = $this->boundary->withinContext($actor, function (Organization $organization) use ($documentId, $versionId): array {
            $document = ListDocuments::find($organization->id, $documentId);
            $version = Str::isUlid($versionId) ? $document->versions()->where('public_id', $versionId)->lockForUpdate()->first() : null;
            if ($version === null) {
                throw (new ModelNotFoundException)->setModel(DocumentVersion::class);
            }

            return ['storage_disk' => $version->storage_disk, 'storage_key' => $version->storage_key,
                'sha256' => $version->sha256, 'byte_size' => $version->byte_size];
        });
        $integrity = $this->blobs->verify($receipt);
        // Re-check revocation after I/O without keeping a long SQL lock around file reads.
        $this->boundary->withinContext($actor, fn (Organization $organization) => ListDocuments::find($organization->id, $documentId));

        return $integrity;
    }
}
