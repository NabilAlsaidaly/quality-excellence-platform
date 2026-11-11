<?php

namespace App\Actions\Documents;

use App\Authorization\TenantContext;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Documents\PrivateDocumentBlobStore;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Throwable;

/** Internal synthetic fixture operation; membership/context are prerequisites, NOT grants. */
final class CreateDocumentWithVersion
{
    public function __construct(private TenantContext $context, private PrivateDocumentBlobStore $blobs) {}

    public function execute(?User $actor, array $data, mixed $stream, ?Closure $afterPersist = null): array
    {
        $this->assertStandaloneWrite();
        $scope = $this->withinContext($actor, fn (Organization $organization) => $organization->public_id);
        $this->validateMetadata($data, true);
        $documentId = strtolower((string) Str::ulid());
        $versionId = strtolower((string) Str::ulid());
        $receipt = $this->writeBlob($scope, $documentId, $versionId, $stream);

        return $this->completeWrite($actor, $receipt, function (Organization $organization, User $currentActor) use ($data, $documentId, $receipt, $afterPersist): array {
            $document = new Document;
            $document->forceFill(['public_id' => $documentId, 'organization_id' => $organization->id, 'created_by' => $currentActor->id,
                'title' => $data['title'], 'description' => $data['description']]);
            $document->save();

            $result = $this->persistVersion($document, $currentActor, 1, $data['original_filename'], $receipt);
            $afterPersist?->__invoke($currentActor, $document, $result);

            return $result;
        });
    }

    public function assertStandaloneWrite(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Synthetic document writes are disabled outside local/testing.');
        }
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Document byte writes require a standalone transaction; outer rollback cannot own files.');
        }
    }

    /** Keep attempt ownership and compensation on this boundary's same store instance. */
    public function writeBlob(string $organization, string $document, string $version, mixed $stream): array
    {
        return $this->blobs->write($organization, $document, $version, $stream);
    }

    public function validateMetadata(array $data, bool $creating): void
    {
        $rules = ['original_filename' => ['required', 'string', 'max:255']];
        if ($creating) {
            $rules += ['title' => ['required', 'string', 'max:255'], 'description' => ['required', 'string', 'max:65535']];
        }
        if (array_diff(array_keys($data), array_keys($rules)) !== []) {
            throw ValidationException::withMessages(['document' => 'تحتوي البيانات على حقول غير مسموح بها.']);
        }
        Validator::make($data, $rules, ['required' => 'هذا الحقل مطلوب.', 'string' => 'أدخل نصًا صالحًا.', 'max' => 'تجاوز النص الحد التقني المسموح.'])->validate();
        foreach ($data as $field => $value) {
            if (! mb_check_encoding($value, 'UTF-8') || preg_match('/[^\p{Z}\s]/u', $value) !== 1
                || ($field === 'original_filename' && preg_match('/[\/\\\\\x00-\x1F\x7F]/u', $value))) {
                throw ValidationException::withMessages([$field => 'أدخل نصًا صالحًا دون مسار ملف.']);
            }
        }
    }

    /** Documents-only current-read boundary; does not broaden Approach or shared authorization. */
    public function withinContext(?User $actor, Closure $operation): mixed
    {
        if ($actor === null || ! $actor->exists || $this->context->organizationId() === null
            || $this->context->actorId() !== (int) $actor->getKey()) {
            throw new AuthorizationException;
        }
        $organizationId = $this->context->organizationId();
        $actorId = $this->context->actorId();

        return DB::transaction(function () use ($organizationId, $actorId, $operation): mixed {
            $organization = Organization::query()->whereKey($organizationId)->lockForUpdate()->first();
            $currentActor = User::query()->whereKey($actorId)->lockForUpdate()->first();
            $membership = OrganizationMembership::query()->where('organization_id', $organizationId)->where('user_id', $actorId)->lockForUpdate()->first();
            if ($organization === null || $currentActor === null || $membership === null
                || ! ($currentActor->principal_type === 'legacy' || ($currentActor->principal_type === 'institution'
                    && (int) $currentActor->organization_id === $organizationId))) {
                throw new AuthorizationException;
            }

            return $operation($organization, $currentActor);
        }, 1);
    }

    /** Complete a verified attempt, compensating only when SQL absence is certain. */
    public function completeWrite(?User $actor, array $receipt, Closure $operation): array
    {
        try {
            $result = $this->withinContext($actor, function (Organization $organization, User $currentActor) use ($receipt, $operation): array {
                if ($organization->public_id !== $receipt['organization_public_id']) {
                    throw new AuthorizationException;
                }

                return $operation($organization, $currentActor);
            });
        } catch (Throwable $original) {
            try {
                $persisted = DB::table('document_versions')->where('public_id', $receipt['version_public_id'])->exists();
            } catch (Throwable $inspection) {
                // Ambiguous COMMIT/disconnect: retaining bytes is safer than deleting a committed version.
                report(new RuntimeException('Document transaction outcome requires private operator inspection.', previous: $inspection));
                $this->blobs->retain($receipt);
                throw $original;
            }
            if ($persisted) {
                $this->blobs->retain($receipt);
                throw $original;
            }
            $this->blobs->compensate($receipt, $original);
        }
        $this->blobs->retain($receipt);

        return $result;
    }

    public function persistVersion(Document $document, User $actor, int $number, string $filename, array $receipt): array
    {
        $version = new DocumentVersion;
        $version->forceFill(['public_id' => $receipt['version_public_id'], 'organization_id' => $document->organization_id,
            'document_id' => $document->id, 'version_number' => $number, 'actor_id' => $actor->id,
            'storage_disk' => 'local', 'storage_key' => $receipt['storage_key'], 'sha256' => $receipt['sha256'],
            'byte_size' => $receipt['byte_size'], 'original_filename' => $filename,
            'detected_media_type' => $receipt['detected_media_type'] ?? null]);
        $version->save();

        return ['document_public_id' => $document->public_id, 'version_public_id' => $version->public_id,
            'title' => $document->title, 'description' => $document->description, 'version_number' => $version->version_number,
            'created_at' => $version->created_at->toIso8601String()];
    }
}
