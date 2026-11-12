<?php

namespace App\Actions\Documents;

use App\Authorization\DocumentWorkspaceAccess;
use App\Models\Document;
use App\Models\User;
use App\Services\Documents\DemoDocumentAvailability;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class DecideDemoDocumentAvailability
{
    public function execute(?User $actor, string $intakeId, bool $available, string $requestKey, string $reason): array
    {
        $boundary = app(DocumentFileWorkspace::class)->intakeBoundary();
        $documentId = $boundary->documentForIntake($intakeId);
        $proof = DocumentWorkspaceAccess::context() ?? throw new AuthorizationException;
        $versionId = DB::table('document_intakes as i')->join('document_versions as v', fn ($j) => $j->on('v.organization_id', '=', 'i.organization_id')->on('v.document_id', '=', 'i.document_id')->on('v.id', '=', 'i.version_id'))
            ->where('i.organization_id', $proof['organization_id'])->where('i.fixture_id', $proof['fixture_id'])->where('i.public_id', $intakeId)->value('v.public_id') ?? throw new AuthorizationException;
        try {
            return $boundary->within($actor, 'release_demo_unscanned', $documentId, function (User $current, Document $document) use ($boundary, $intakeId, $versionId, $available, $requestKey, $reason): array {
                $intake = $boundary->intake($intakeId);
                if (! Str::isUlid($requestKey) || (int) $intake->actor_id === (int) $current->id || (int) $document->created_by === (int) $current->id) {
                    throw new AuthorizationException;
                }
                if (! mb_check_encoding($reason, 'UTF-8') || trim($reason) === '' || mb_strlen($reason) > 2000) {
                    throw ValidationException::withMessages(['reason' => 'اذكر سبب القرار بما لا يتجاوز 2000 حرف.']);
                }
                app(DemoDocumentAvailability::class)->assertUnscanned($intake);
                fclose($boundary->openVerified($boundary->receipt($intake)));
                $state = $available ? 'available_demo_unscanned' : 'denied_demo_unscanned';
                $prior = DB::table('document_demo_availability_decisions')->where('organization_id', $intake->organization_id)
                    ->where('fixture_id', $intake->fixture_id)->where('document_id', $document->id)->where('version_id', $intake->version_id)->lockForUpdate()->first();
                if ($prior !== null) {
                    if ($prior->request_key !== $requestKey || $prior->state !== $state || (int) $prior->reviewer_id !== (int) $current->id || $prior->reason !== $reason) {
                        throw new ConflictHttpException('سبق تسجيل قرار مستقل لهذه النسخة.');
                    }

                    return ['state' => $prior->state, 'decision_public_id' => $prior->public_id];
                }
                $grant = app(DocumentWorkspaceAccess::class)->current($current, 'release_demo_unscanned', $document, $versionId) ?? throw new AuthorizationException;
                $id = (string) Str::ulid();
                DB::table('document_demo_availability_decisions')->insert(['public_id' => $id, 'organization_id' => $intake->organization_id,
                    'fixture_id' => $intake->fixture_id, 'document_id' => $document->id, 'version_id' => $intake->version_id, 'intake_id' => $intake->id,
                    'reviewer_id' => $current->id, 'authority_id' => $grant->id, 'state' => $state, 'request_key' => $requestKey,
                    'reason' => $reason, 'created_at' => RegisterDocumentIntake::timestamp()]);

                return ['state' => $state, 'decision_public_id' => $id];
            }, $versionId);
        } catch (AuthorizationException $error) {
            app(ManageDocumentFileOperations::class)->recordDenied($actor, 'release_demo_unscanned', documentId: $documentId, versionId: $versionId);
            throw $error;
        }
    }
}
