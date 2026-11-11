<?php

namespace App\Actions\Documents;

use App\Authorization\DocumentWorkspaceAccess;
use App\Domain\Documents\Intake\DocumentInspectionLimits;
use App\Domain\Documents\Intake\InspectedDocument;
use App\Domain\Documents\Intake\QuarantinedDocument;
use App\Models\Document;
use App\Models\Organization;
use App\Models\User;
use App\Services\Documents\InspectDocumentFile;
use App\Services\Documents\QuarantinedDocumentStore;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/** Unregistered persistence boundary. Existing synthetic operations NEVER authorize these operations. */
final class RegisterDocumentIntake
{
    private QuarantinedDocumentStore $quarantine;

    private array $owned = [];

    public function __construct(private FilesystemAdapter $disk, private DocumentInspectionLimits $limits)
    {
        $this->quarantine = new QuarantinedDocumentStore($disk, new InspectDocumentFile);
    }

    public function execute(?User $actor, array $metadata, UploadedFile $file, string $requestKey,
        ?string $documentPublicId = null, string $expectedVersion = 'none'): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Real-file intake owns its SQL/file compensation boundary.');
        }
        $operation = $documentPublicId === null ? 'upload_real' : 'append_real';
        $organization = $this->within($actor, $operation, $documentPublicId, fn (User $current, ?Document $document, Organization $organization) => $organization->public_id);
        if (! Str::isUlid($requestKey)) {
            throw new ConflictHttpException('معرّف محاولة الرفع غير صالح.');
        }
        $creator = app(CreateDocumentWithVersion::class);
        if (array_diff(array_keys($metadata), $documentPublicId === null ? ['title', 'description'] : []) !== []) {
            throw ValidationException::withMessages(['document' => 'حقول الوثيقة غير مسموح بها.']);
        }
        $creator->validateMetadata($metadata + ['original_filename' => $file->getClientOriginalName()], $documentPublicId === null);
        $this->assertStorage();
        $receipt = $this->quarantine->store($organization, $file, $this->limits);
        $this->owned[$receipt->storageKey] = true;
        $fingerprint = hash('sha256', json_encode([$metadata, $documentPublicId, $expectedVersion,
            $receipt->inspection->originalFilename, $receipt->inspection->byteSize, $receipt->inspection->sha256], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        try {
            $result = $this->within($actor, $operation, $documentPublicId,
                function (User $current, ?Document $document, Organization $organization, array $proof) use ($metadata, $expectedVersion, $requestKey, $receipt, $fingerprint, $creator, $operation): array {
                    $prior = DB::table('document_intakes')->where('organization_id', $organization->id)
                        ->where('actor_id', $current->id)->where('request_key', $requestKey)->lockForUpdate()->first();
                    if ($prior !== null) {
                        if ($prior->fixture_id !== $proof['fixture_id'] || ! hash_equals($prior->request_hash, $fingerprint)) {
                            throw new ConflictHttpException('معرّف المحاولة مستخدم لملف أو بيانات مختلفة.');
                        }

                        return ['intake_public_id' => $prior->public_id, 'created' => false];
                    }
                    if ($document === null) {
                        if ($expectedVersion !== 'none') {
                            throw new ConflictHttpException('مرجع الإصدار السابق غير صالح.');
                        }
                        $document = new Document;
                        $document->forceFill(['public_id' => (string) Str::ulid(), 'organization_id' => $organization->id,
                            'created_by' => $current->id, 'title' => $metadata['title'], 'description' => $metadata['description']])->save();
                    }
                    $latest = DB::table('document_versions')->where('organization_id', $organization->id)
                        ->where('document_id', $document->id)->orderByDesc('version_number')->lockForUpdate()->first();
                    if (strcasecmp($expectedVersion, $latest?->public_id ?? 'none') !== 0 || ($latest?->version_number ?? 0) >= 4294967295) {
                        throw new ConflictHttpException('ظهر إصدار أحدث؛ أعد فتح الوثيقة.');
                    }
                    $version = $creator->persistVersion($document, $current, ($latest?->version_number ?? 0) + 1,
                        $receipt->inspection->originalFilename, ['version_public_id' => (string) Str::ulid(),
                            'storage_key' => $receipt->storageKey, 'sha256' => $receipt->inspection->sha256,
                            'byte_size' => $receipt->inspection->byteSize, 'detected_media_type' => $receipt->inspection->mediaType]);
                    $versionId = DB::table('document_versions')->where('public_id', $version['version_public_id'])->value('id');
                    $id = DB::table('document_intakes')->insertGetId(['public_id' => $receipt->intakePublicId,
                        'organization_id' => $organization->id, 'fixture_id' => $proof['fixture_id'], 'document_id' => $document->id,
                        'version_id' => $versionId, 'actor_id' => $current->id, 'request_key' => $requestKey,
                        'request_hash' => $fingerprint, 'state' => 'pending', 'generation' => 1,
                        'inspected_media_type' => $receipt->inspection->mediaType,
                        'limits_json' => json_encode(['maximum_bytes' => $this->limits->maximumBytes,
                            'maximum_expanded_bytes' => $this->limits->maximumExpandedBytes,
                            'maximum_archive_entries' => $this->limits->maximumArchiveEntries], JSON_THROW_ON_ERROR),
                        'created_at' => self::timestamp(), 'updated_at' => self::timestamp()]);
                    $intake = DB::table('document_intakes')->where('id', $id)->first();
                    $this->event($intake, $current, 'registered', $requestKey, ['sha256' => $receipt->inspection->sha256, 'byte_size' => $receipt->inspection->byteSize]);
                    app(DocumentWorkspaceAccess::class)->authorize($current, $operation, $document);

                    return ['intake_public_id' => $intake->public_id, 'document_public_id' => $document->public_id,
                        'version_public_id' => $version['version_public_id'], 'created' => true];
                });
        } catch (Throwable $error) {
            $this->compensate($receipt, $error);
            throw $error;
        }
        if (! $result['created']) {
            $this->compensate($receipt);
        } else {
            unset($this->owned[$receipt->storageKey]);
        }

        return $result;
    }

    /** Real session + current locks + actual unchanged Policy before and after each transaction. */
    public function within(?User $actor, string $operation, ?string $documentId, Closure $callback): mixed
    {
        $access = app(DocumentWorkspaceAccess::class);

        return $access->withSession($actor, fn (User $current) => app(CreateDocumentWithVersion::class)->withinContext($current,
            function (Organization $organization, User $locked) use ($access, $operation, $documentId, $callback): mixed {
                $proof = DocumentWorkspaceAccess::context() ?? throw new AuthorizationException;
                $document = null;
                if ($documentId !== null) {
                    $document = Str::isUlid($documentId) ? Document::where('organization_id', $organization->id)
                        ->where('public_id', $documentId)->lockForUpdate()->first() : null;
                    if ($document === null) {
                        throw new AuthorizationException;
                    }
                }
                if (! hash_equals($locked->getAuthPassword(), (string) request()->session()->get('institution.password_hash'))) {
                    throw new AuthorizationException;
                }
                $access->authorize($locked, $operation, $document);
                $result = $callback($locked, $document, $organization, $proof);
                $access->authorize($locked, $operation, $document);
                if (! hash_equals($locked->fresh()->getAuthPassword(), (string) request()->session()->get('institution.password_hash'))) {
                    throw new AuthorizationException;
                }

                return $result;
            }));
    }

    public function intake(string $publicId): object
    {
        $proof = DocumentWorkspaceAccess::context() ?? throw new AuthorizationException;
        if (! Str::isUlid($publicId) || DB::transactionLevel() === 0) {
            throw new AuthorizationException;
        }

        return DB::table('document_intakes')->where('organization_id', $proof['organization_id'])
            ->where('fixture_id', $proof['fixture_id'])->where('scenario', 'DOCUMENT_LOCAL_001')->where('classification', 'TEST_ONLY')
            ->where('public_id', $publicId)->lockForUpdate()->first() ?? throw new AuthorizationException;
    }

    public function documentForIntake(string $publicId): string
    {
        $proof = DocumentWorkspaceAccess::context() ?? throw new AuthorizationException;
        $id = DB::table('document_intakes')->where('organization_id', $proof['organization_id'])->where('fixture_id', $proof['fixture_id'])
            ->where('public_id', $publicId)->value('document_id');

        return Document::where('organization_id', $proof['organization_id'])->whereKey($id)->value('public_id') ?? throw new AuthorizationException;
    }

    public function event(object $intake, ?User $actor, string $event, string $requestKey, array $details): void
    {
        if (DB::transactionLevel() === 0 || ! Str::isUlid($requestKey)) {
            throw new LogicException('Intake audit must share its origin transaction.');
        }
        DB::table('document_intake_events')->insert(['public_id' => (string) Str::ulid(), 'organization_id' => $intake->organization_id,
            'fixture_id' => $intake->fixture_id, 'intake_id' => $intake->id, 'actor_id' => $actor?->id,
            'generation' => $intake->generation, 'request_key' => $requestKey, 'event' => $event,
            'details_json' => json_encode((object) $details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => self::timestamp()]);
    }

    public function receipt(object $intake): QuarantinedDocument
    {
        $version = DB::table('document_versions')->where('organization_id', $intake->organization_id)
            ->where('document_id', $intake->document_id)->where('id', $intake->version_id)->first() ?? throw new RuntimeException('Missing immutable intake version.');
        if ($version->storage_disk !== 'local' || $version->byte_size < 1 || $version->byte_size > 30000000) {
            throw new RuntimeException('Invalid persisted intake version.');
        }
        $organization = Organization::findOrFail($intake->organization_id);

        return new QuarantinedDocument(strtolower($organization->public_id), strtolower($intake->public_id), $version->storage_key,
            new InspectedDocument($version->original_filename, $intake->inspected_media_type, $version->byte_size, $version->sha256));
    }

    public function openVerified(QuarantinedDocument $receipt): mixed
    {
        $this->assertStorage();

        return $this->quarantine->openForScan($receipt);
    }

    /** Stored clean evidence is not release authority. Caller still needs current actual Policy. */
    public static function assertCleanScan(object $intake): void
    {
        $proof = DocumentWorkspaceAccess::context() ?? throw new AuthorizationException;
        if (DB::transactionLevel() === 0 || (int) $intake->organization_id !== $proof['organization_id']
            || $intake->fixture_id !== $proof['fixture_id'] || ! in_array($intake->state, ['clean', 'released'], true)
            || $intake->scan_result !== 'clean') {
            throw new AuthorizationException;
        }
        $version = DB::table('document_versions')->where('organization_id', $intake->organization_id)
            ->where('document_id', $intake->document_id)->where('id', $intake->version_id)->first() ?? throw new AuthorizationException;
        $event = DB::table('document_intake_events')->where('organization_id', $intake->organization_id)
            ->where('fixture_id', $intake->fixture_id)->where('intake_id', $intake->id)
            ->where('generation', $intake->generation)->where('event', 'scan_clean')->orderByDesc('id')->lockForUpdate()->first();
        $details = $event ? json_decode($event->details_json, true, 64, JSON_THROW_ON_ERROR) : [];
        if (! hash_equals($version->sha256, $details['sha256'] ?? '') || ($details['byte_size'] ?? null) !== $version->byte_size
            || ! is_string($details['engine_version'] ?? null) || $details['engine_version'] === ''
            || ! is_int($details['database_version'] ?? null) || $details['database_version'] < 1
            || ! is_string($details['database_timestamp'] ?? null) || $details['database_timestamp'] === '') {
            throw new AuthorizationException;
        }
    }

    private function assertStorage(): void
    {
        $actual = realpath($this->disk->path(''));
        $configured = realpath(Storage::disk('local')->path(''));
        if ($actual === false || $actual !== $configured) {
            throw new LogicException('The approved local disk and quarantine root must be identical.');
        }
    }

    private function compensate(QuarantinedDocument $receipt, ?Throwable $original = null): void
    {
        try {
            if (! isset($this->owned[$receipt->storageKey])) {
                throw new LogicException('Refuse unowned quarantine compensation.');
            }
            if (DB::table('document_versions')->where('storage_key', $receipt->storageKey)->exists()) {
                unset($this->owned[$receipt->storageKey]);

                return;
            }
            foreach ([$receipt->storageKey.'.json', $receipt->storageKey] as $key) {
                if (! $this->disk->delete($key)) {
                    throw new RuntimeException('Owned intake requires private reconciliation.');
                }
            }
            unset($this->owned[$receipt->storageKey]);
        } catch (Throwable $inspection) {
            report(new RuntimeException('Retain intake bytes until the SQL outcome is known.', previous: $inspection));
            if ($original === null) {
                throw $inspection;
            }
        }
    }

    public static function timestamp(): string
    {
        return now('UTC')->format('Y-m-d H:i:s');
    }
}
