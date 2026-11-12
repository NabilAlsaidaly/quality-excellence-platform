<?php

namespace App\Actions\Documents;

use App\Authorization\DocumentWorkspaceAccess;
use App\Authorization\TenantAuthorization;
use App\Models\Document;
use App\Models\DocumentFileOperationAssignment;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Explicit issuer only; finite exact targets, no delegation or creator bootstrap. */
final class ManageDocumentFileOperations
{
    public function issue(?User $issuer, User $recipient, string $operation, string $documentId, ?string $versionId = null): string
    {
        return $this->asIssuer($issuer, [$recipient], function (User $current, array $proof) use ($recipient, $operation, $documentId, $versionId): string {
            $allowed = (int) $recipient->id === $proof['editor_id']
                ? ['view', 'metadata', 'history', 'upload_real', 'append_real', 'download_demo_unscanned']
                : ((int) $recipient->id === $proof['reader_id'] ? ['view', 'history', 'release_real', 'download_real', 'release_demo_unscanned', 'download_demo_unscanned'] : []);
            $versionScoped = in_array($operation, DocumentWorkspaceAccess::FILE_OPERATIONS, true);
            if (! in_array($operation, $allowed, true) || ! Str::isUlid($documentId)
                || ($versionScoped ? ($versionId === null || ! Str::isUlid($versionId)) : $versionId !== null)
                || (str_ends_with($operation, '_demo_unscanned') && ($proof['demo_unscanned_exception'] ?? false) !== true)) {
                throw new AuthorizationException;
            }
            $documentId = strtolower($documentId);
            $versionId = $versionId === null ? null : strtolower($versionId);
            if (DB::table('documents')->where('public_id', $documentId)->where('organization_id', '!=', $proof['organization_id'])->exists()) {
                throw new AuthorizationException;
            }
            $document = Document::where('organization_id', $proof['organization_id'])->where('public_id', $documentId)->lockForUpdate()->first();
            $version = null;
            if ($versionId !== null) {
                $version = $document === null ? null : DB::table('document_versions')->where('organization_id', $proof['organization_id'])
                    ->where('document_id', $document->id)->where('public_id', $versionId)->lockForUpdate()->first();
                if ($version === null && DB::table('document_versions')->where('public_id', $versionId)->exists()) {
                    throw new AuthorizationException;
                }
            }
            $prior = $this->query($proof)->where('grantee_id', $recipient->id)->where('operation', $operation)
                ->where('document_public_id', $documentId)->where('version_public_id', $versionId)->lockForUpdate()->first();
            if ($prior !== null) {
                return $prior->public_id; // Never renew an expired/revoked assignment.
            }
            $start = CarbonImmutable::now('UTC')->startOfSecond();
            $assignment = new DocumentFileOperationAssignment;
            $assignment->forceFill(['organization_id' => $proof['organization_id'], 'fixture_id' => $proof['fixture_id'],
                'created_at' => $start->format('Y-m-d H:i:s'), 'grantee_id' => $recipient->id, 'issuer_id' => $current->id,
                'document_public_id' => $documentId, 'version_public_id' => $versionId, 'document_id' => $document?->id,
                'version_id' => $version?->id, 'operation' => $operation, 'starts_at' => $start->format('Y-m-d H:i:s'),
                'expires_at' => $start->addHours(24)->format('Y-m-d H:i:s')])->save();
            $this->event($proof, $current, (int) $recipient->id, 'granted', 'granted:'.$assignment->public_id, $assignment->id,
                ['operation' => $operation, 'document_public_id' => $documentId, 'version_public_id' => $versionId]);

            return $assignment->public_id;
        });
    }

    public function revoke(?User $issuer, string $publicId): void
    {
        $proof = DocumentWorkspaceAccess::context() ?? throw new AuthorizationException;
        $candidate = Str::isUlid($publicId) ? $this->query($proof)->where('public_id', $publicId)->first() : null;
        $recipient = $candidate ? User::find($candidate->grantee_id) : null;
        if ($recipient === null) {
            throw new AuthorizationException;
        }
        $this->asIssuer($issuer, [$recipient], function (User $current, array $proof) use ($publicId): void {
            $grant = $this->query($proof)->where('public_id', $publicId)->lockForUpdate()->first() ?? throw new AuthorizationException;
            if ($grant->revoked_at !== null) {
                return;
            }
            $this->query($proof)->whereKey($grant->id)->update(['revoked_at' => DocumentWorkspaceAccess::timestamp(), 'revoked_by' => $current->id]);
            $this->event($proof, $current, (int) $grant->grantee_id, 'revoked', 'revoked:'.$grant->public_id, $grant->id, ['operation' => $grant->operation]);
        });
    }

    private function asIssuer(?User $issuer, array $subjects, Closure $operation): mixed
    {
        return app(DocumentWorkspaceAccess::class)->withSession($issuer, fn (User $fresh) => app(TenantAuthorization::class)->withinMutation($fresh,
            function (Organization $organization, User $current) use ($operation): mixed {
                $proof = DocumentWorkspaceAccess::context() ?? throw new AuthorizationException;
                if (! DocumentWorkspaceAccess::hasFileScope() || (int) $organization->id !== $proof['organization_id'] || (int) $current->id !== $proof['issuer_id']
                    || ! hash_equals($current->getAuthPassword(), (string) request()->session()->get('institution.password_hash'))) {
                    throw new AuthorizationException;
                }

                return $operation($current, $proof);
            }, $subjects));
    }

    public function recordDenied(?User $actor, string $operation, ?string $grantId = null, ?string $documentId = null, ?string $versionId = null): void
    {
        try {
            app(DocumentWorkspaceAccess::class)->withSession($actor, fn (User $fresh) => app(TenantAuthorization::class)->withinMutation($fresh,
                function (Organization $organization, User $current) use ($operation, $grantId, $documentId, $versionId): void {
                    $proof = DocumentWorkspaceAccess::context() ?? throw new AuthorizationException;
                    if (! DocumentWorkspaceAccess::hasFileScope() || ! in_array($operation, DocumentWorkspaceAccess::FILE_OPERATIONS, true)) {
                        return;
                    }
                    $grant = $grantId !== null && Str::isUlid($grantId) ? $this->query($proof)->where('public_id', $grantId)
                        ->where('grantee_id', $current->id)->where('operation', $operation)->lockForUpdate()->first() : null;
                    if ($grant === null && $documentId !== null && $versionId !== null && Str::isUlid($documentId) && Str::isUlid($versionId)) {
                        $grant = $this->query($proof)->where('document_public_id', $documentId)->where('version_public_id', $versionId)
                            ->where('grantee_id', $current->id)->where('operation', $operation)->lockForUpdate()->first();
                    }
                    if ($grant !== null && $grant->revoked_at === null && $grant->expires_at <= DocumentWorkspaceAccess::timestamp()
                        && ! DB::table('document_file_operation_changes')->where('organization_id', $organization->id)->where('fixture_id', $proof['fixture_id'])
                            ->where('event_key', 'expired:'.$grant->public_id)->exists()) {
                        $this->event($proof, $current, (int) $current->id, 'expiration_observed', 'expired:'.$grant->public_id, $grant->id, ['operation' => $operation]);
                    }
                    $this->event($proof, $current, (int) $current->id, 'access_denied', (string) Str::ulid(), $grant?->id, ['operation' => $operation]);
                }));
        } catch (AuthorizationException) {
            // An unverified identity cannot supply a tenant audit actor.
        }
    }

    private function query(array $proof): Builder
    {
        return DocumentFileOperationAssignment::where('organization_id', $proof['organization_id'])->where('fixture_id', $proof['fixture_id'])
            ->where('scenario', DocumentWorkspaceAccess::SCENARIO)->where('classification', 'TEST_ONLY');
    }

    private function event(array $proof, User $actor, int $subject, string $event, string $key, ?int $grantId, array $details): void
    {
        DB::table('document_file_operation_changes')->insert(['public_id' => (string) Str::ulid(),
            'organization_id' => $proof['organization_id'], 'fixture_id' => $proof['fixture_id'], 'created_at' => DocumentWorkspaceAccess::timestamp(),
            'assignment_id' => $grantId, 'actor_id' => $actor->id, 'subject_id' => $subject, 'event' => $event, 'event_key' => $key,
            'details_json' => json_encode((object) $details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
    }
}
