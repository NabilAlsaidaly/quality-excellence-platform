<?php

namespace App\Actions\Documents;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Reconcile committed, owned intake leases only; unbound/ambiguous orphan bytes are retained for review. */
final class ReconcileDocumentIntake
{
    public function __construct(private RegisterDocumentIntake $boundary) {}

    public function execute(?User $actor, string $intakeId, int $expectedGeneration, string $requestKey): array
    {
        $documentId = $this->boundary->documentForIntake($intakeId);
        $operation = DB::transaction(function () use ($intakeId): string {
            $intake = $this->boundary->intake($intakeId);
            $number = DB::table('document_versions')->where('organization_id', $intake->organization_id)
                ->where('document_id', $intake->document_id)->where('id', $intake->version_id)->value('version_number');

            return $number === 1 ? 'upload_real' : 'append_real';
        });

        return $this->boundary->within($actor, $operation, $documentId,
            function (User $current) use ($intakeId, $expectedGeneration, $requestKey): array {
                $intake = $this->boundary->intake($intakeId);
                if ((int) $intake->actor_id !== (int) $current->id || ! Str::isUlid($requestKey)) {
                    throw new AuthorizationException;
                }
                $prior = DB::table('document_intake_events')->where('organization_id', $intake->organization_id)
                    ->where('intake_id', $intake->id)->where('request_key', $requestKey)->lockForUpdate()->first();
                if ($prior !== null) {
                    $details = json_decode($prior->details_json, true, 64, JSON_THROW_ON_ERROR);
                    if ($prior->event !== 'reconciled' || (int) $prior->actor_id !== (int) $current->id
                        || ($details['expected_generation'] ?? null) !== $expectedGeneration) {
                        throw new ConflictHttpException('معرّف التعافي مستخدم لمحاولة مختلفة.');
                    }

                    return ['state' => $intake->state, 'generation' => $intake->generation];
                }
                if ($intake->generation !== $expectedGeneration || $expectedGeneration >= 4294967295) {
                    throw new ConflictHttpException('تغيرت محاولة المعالجة.');
                }
                if ($intake->state === 'scanning') {
                    if ($intake->lease_expires_at > RegisterDocumentIntake::timestamp()) {
                        throw new ConflictHttpException('لا تزال المعالجة مملوكة لعامل نشط.');
                    }
                    if (DB::table('document_intakes')->where('id', $intake->id)->where('generation', $expectedGeneration)
                        ->update(['state' => 'failed', 'scan_result' => 'unavailable', 'generation' => $expectedGeneration + 1,
                            'lease_hash' => null, 'lease_expires_at' => null, 'updated_at' => RegisterDocumentIntake::timestamp()]) !== 1) {
                        throw new ConflictHttpException('تغيرت محاولة المعالجة أثناء التعافي.');
                    }
                    $intake = DB::table('document_intakes')->where('id', $intake->id)->first();
                }
                // Never adopt/delete a possibly committed file or silently reopen a release decision.
                fclose($this->boundary->openVerified($this->boundary->receipt($intake)));
                $this->boundary->event($intake, $current, 'reconciled', $requestKey, ['expected_generation' => $expectedGeneration]);

                return ['state' => $intake->state, 'generation' => $intake->generation];
            });
    }
}
