<?php

namespace App\Actions\Documents;

use App\Authorization\DocumentWorkspaceAccess;
use App\Models\Document;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ReleaseDocumentIntake
{
    public function __construct(private RegisterDocumentIntake $boundary) {}

    public function execute(?User $actor, string $intakeId, bool $release, string $requestKey): array
    {
        $documentId = $this->boundary->documentForIntake($intakeId);

        return $this->boundary->within($actor, 'release_real', $documentId,
            function (User $current, Document $document) use ($intakeId, $release, $requestKey): array {
                $intake = $this->boundary->intake($intakeId);
                if (! Str::isUlid($requestKey) || (int) $current->id === (int) $intake->actor_id || (int) $current->id === (int) $document->created_by) {
                    throw new AuthorizationException;
                }
                $prior = DB::table('document_intake_events')->where('organization_id', $intake->organization_id)
                    ->where('intake_id', $intake->id)->where('request_key', $requestKey)->lockForUpdate()->first();
                $state = $release ? 'released' : 'release_denied';
                if ($prior !== null) {
                    if ($prior->event !== $state || (int) $prior->actor_id !== (int) $current->id) {
                        throw new ConflictHttpException('معرّف القرار مستخدم لقرار آخر.');
                    }

                    return ['state' => $state];
                }
                if ($intake->state !== 'clean' || $intake->scan_result !== 'clean') {
                    throw new ConflictHttpException('يلزم فحص نظيف موثّق قبل قرار الإتاحة.');
                }
                RegisterDocumentIntake::assertCleanScan($intake);
                fclose($this->boundary->openVerified($this->boundary->receipt($intake)));
                $grant = app(DocumentWorkspaceAccess::class)->current($current, 'release_real', $document) ?? throw new AuthorizationException;
                if (DB::table('document_intakes')->where('id', $intake->id)->where('state', 'clean')->where('generation', $intake->generation)
                    ->update(['state' => $state, 'released_at' => $release ? RegisterDocumentIntake::timestamp() : null,
                        'release_actor_id' => $release ? $current->id : null, 'release_authority_ref' => $release ? $grant->public_id : null,
                        'updated_at' => RegisterDocumentIntake::timestamp()]) !== 1) {
                    throw new ConflictHttpException('سبق حسم حالة الإتاحة.');
                }
                $this->boundary->event($intake, $current, $state, $requestKey, ['assignment_public_id' => $grant->public_id]);

                return ['state' => $state];
            });
    }
}
