<?php

namespace App\Actions\Documents;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Append only: identical bytes get an independent version and private key. */
final class AppendDocumentVersion
{
    public function __construct(private CreateDocumentWithVersion $boundary) {}

    public function execute(?User $actor, string $publicId, array $data, mixed $stream): array
    {
        $this->boundary->assertStandaloneWrite();
        $organizationId = $this->boundary->withinContext($actor, function (Organization $organization) use ($publicId): string {
            ListDocuments::find($organization->id, $publicId);

            return $organization->public_id;
        });
        $this->boundary->validateMetadata($data, false);
        $receipt = $this->boundary->writeBlob($organizationId, $publicId, strtolower((string) Str::ulid()), $stream);

        return $this->boundary->completeWrite($actor, $receipt, function (Organization $organization, User $currentActor) use ($publicId, $data, $receipt): array {
            $document = ListDocuments::find($organization->id, $publicId);
            $last = $document->versions()->orderByDesc('version_number')->lockForUpdate()->first();
            if ($last !== null && $last->version_number >= 4294967295) {
                throw ValidationException::withMessages(['document' => 'بلغ ترقيم الإصدارات الحد التقني.']);
            }

            return $this->boundary->persistVersion($document, $currentActor, ($last?->version_number ?? 0) + 1, $data['original_filename'], $receipt);
        });
    }
}
