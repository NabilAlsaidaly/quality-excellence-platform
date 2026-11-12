<?php

namespace App\Actions\Documents;

use App\Authorization\DocumentWorkspaceAccess;
use App\Domain\Documents\Intake\DocumentInspectionLimits;
use App\Models\Document;
use App\Models\Organization;
use App\Models\User;
use App\Services\Documents\DemoDocumentAvailability;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Native presentation bridge; byte validation, authorization and persistence stay in Actions. */
final class DocumentFileWorkspace
{
    public function __construct(private DocumentWorkspaceAccess $access) {}

    public function intakeBoundary(): RegisterDocumentIntake
    {
        return new RegisterDocumentIntake(Storage::disk('local'), new DocumentInspectionLimits(30000000, 60000000, 100));
    }

    /** Only explicit unused permits; this never issues or renews a grant. */
    public function permits(string $operation, ?string $documentId = null): array
    {
        if (! DocumentWorkspaceAccess::hasFileScope() || ! in_array($operation, ['upload_real', 'append_real'], true)) {
            return [];
        }
        try {
            return $this->access->withSession($this->actor(), fn (User $actor) => app(CreateDocumentWithVersion::class)->withinContext($actor,
                function (Organization $organization, User $current) use ($operation, $documentId): array {
                    $proof = DocumentWorkspaceAccess::context();
                    $query = DB::table('document_file_operation_assignments')->where('organization_id', $organization->id)
                        ->where('fixture_id', $proof['fixture_id'])->where('scenario', DocumentWorkspaceAccess::SCENARIO)->where('classification', 'TEST_ONLY')
                        ->where('grantee_id', $current->id)->where('operation', $operation)->whereNull('version_id')->whereNull('revoked_at')
                        ->where('starts_at', '<=', DocumentWorkspaceAccess::timestamp())->where('expires_at', '>', DocumentWorkspaceAccess::timestamp());
                    if ($documentId !== null) {
                        if (! Str::isUlid($documentId)) {
                            throw new AuthorizationException;
                        }
                        $query->where('document_public_id', $documentId);
                    }
                    $result = [];
                    foreach ($query->orderBy('id')->lockForUpdate()->get() as $permit) {
                        $document = Document::where('organization_id', $organization->id)->where('public_id', $permit->document_public_id)->lockForUpdate()->first();
                        if ($document === null && $operation === 'upload_real') {
                            $document = new Document;
                            $document->forceFill(['public_id' => $permit->document_public_id, 'organization_id' => $organization->id]);
                        }
                        if ($document === null) {
                            continue;
                        }
                        $this->access->authorize($current, $operation, $document, $permit->version_public_id, $permit->public_id);
                        $result[] = ['grant_public_id' => $permit->public_id, 'document_public_id' => $permit->document_public_id,
                            'version_public_id' => $permit->version_public_id];
                    }

                    return $result;
                }));
        } catch (AuthorizationException) {
            return [];
        }
    }

    public function upload(UploadedFile $file, array $metadata, string $requestKey, string $grantId, string $expectedVersion = 'none'): array
    {
        $actor = $this->actor();
        $operation = 'upload_real';
        try {
            return $this->access->withSession($actor, function (User $current) use ($file, $metadata, $requestKey, $grantId, $expectedVersion, &$operation): array {
                $proof = DocumentWorkspaceAccess::context();
                if (! DocumentWorkspaceAccess::hasFileScope() || ! Str::isUlid($grantId)) {
                    throw new AuthorizationException;
                }
                $permit = DB::table('document_file_operation_assignments')->where('organization_id', $proof['organization_id'])
                    ->where('fixture_id', $proof['fixture_id'])->where('grantee_id', $current->id)->where('public_id', $grantId)
                    ->whereIn('operation', ['upload_real', 'append_real'])->first() ?? throw new AuthorizationException;
                $operation = $permit->operation;

                return $this->intakeBoundary()->execute($current, $metadata, $file, $requestKey, $permit->document_public_id, $expectedVersion, $grantId);
            });
        } catch (AuthorizationException $error) {
            app(ManageDocumentFileOperations::class)->recordDenied($actor, $operation, $grantId);
            throw $error;
        }
    }

    public function latestVersion(string $documentId): string
    {
        return $this->access->within($this->actor(), 'history', $documentId, fn (User $actor, Document $document) => DB::table('document_versions')
            ->where('organization_id', $document->organization_id)->where('document_id', $document->id)->orderByDesc('version_number')
            ->lockForUpdate()->value('public_id') ?? 'none');
    }

    public function canVersion(string $operation, string $documentId, string $versionId): bool
    {
        if (! in_array($operation, ['release_demo_unscanned', 'download_demo_unscanned'], true)) {
            return false;
        }
        try {
            return $this->intakeBoundary()->within($this->actor(), $operation, $documentId,
                function (User $actor, Document $document, Organization $organization, array $proof) use ($operation, $versionId): bool {
                    $intake = DB::table('document_intakes as i')->join('document_versions as v', fn ($j) => $j->on('v.organization_id', '=', 'i.organization_id')->on('v.document_id', '=', 'i.document_id')->on('v.id', '=', 'i.version_id'))
                        ->where('i.organization_id', $organization->id)->where('i.fixture_id', $proof['fixture_id'])->where('i.document_id', $document->id)
                        ->where('v.public_id', $versionId)->select('i.*')->lockForUpdate()->first() ?? throw new AuthorizationException;
                    $availability = app(DemoDocumentAvailability::class);
                    if ($operation === 'download_demo_unscanned') {
                        $availability->assertAvailable($actor, $document, $intake, $versionId);
                    } else {
                        $availability->assertUnscanned($intake);
                        if ((int) $intake->actor_id === (int) $actor->id || (int) $document->created_by === (int) $actor->id
                            || DB::table('document_demo_availability_decisions')->where('organization_id', $organization->id)
                                ->where('fixture_id', $proof['fixture_id'])->where('intake_id', $intake->id)->exists()) {
                            return false;
                        }
                    }

                    return true;
                }, $versionId);
        } catch (AuthorizationException) {
            return false;
        }
    }

    public function decide(string $intakeId, bool $available, string $requestKey, string $reason): array
    {
        return app(DecideDemoDocumentAvailability::class)->execute($this->actor(), $intakeId, $available, $requestKey, $reason);
    }

    public function versions(string $documentId, int $page = 1): LengthAwarePaginator
    {
        return $this->access->within($this->actor(), 'history', $documentId, function (User $actor, Document $document) use ($page): LengthAwarePaginator {
            $proof = DocumentWorkspaceAccess::context();
            $rows = DB::table('document_versions as version')->leftJoin('document_intakes as intake', fn ($join) => $join
                ->on('intake.organization_id', '=', 'version.organization_id')->on('intake.document_id', '=', 'version.document_id')->on('intake.version_id', '=', 'version.id')
                ->where('intake.fixture_id', $proof['fixture_id']))
                ->leftJoin('users as actor', fn ($join) => $join->on('actor.id', '=', 'version.actor_id')->on('actor.organization_id', '=', 'version.organization_id'))
                ->leftJoin('document_demo_availability_decisions as availability', fn ($join) => $join->on('availability.organization_id', '=', 'intake.organization_id')
                    ->on('availability.fixture_id', '=', 'intake.fixture_id')->on('availability.intake_id', '=', 'intake.id')->on('availability.version_id', '=', 'version.id'))
                ->where('version.organization_id', $document->organization_id)->where('version.document_id', $document->id)->orderByDesc('version.version_number')
                ->sharedLock()->paginate(20, ['version.public_id', 'version.version_number', 'version.original_filename', 'version.byte_size', 'version.detected_media_type',
                    'version.created_at', 'actor.name as actor_name', 'intake.state', 'intake.public_id as intake_public_id', 'availability.state as demo_state'], 'page', max(1, $page));
            $rows->setCollection($rows->getCollection()->mapWithKeys(fn ($row) => [$row->public_id => (array) $row]));

            return $rows;
        });
    }

    private function actor(): ?User
    {
        $actor = Auth::guard('institution')->user();

        return $actor instanceof User ? $actor : null;
    }
}
