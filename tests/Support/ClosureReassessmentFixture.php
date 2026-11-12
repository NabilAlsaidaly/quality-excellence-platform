<?php

namespace Tests\Support;

use App\Actions\Maturity\ManageStageReassessmentOperations;
use App\Authorization\ApproachAssignmentAccess;
use App\Authorization\DocumentWorkspaceAccess;
use App\Authorization\ReviewMaturityAccess;
use Illuminate\Support\Facades\DB;

/** Called only during the newly owned closure fixture setup, never a runtime grant. */
final class ClosureReassessmentFixture
{
    public function issue(array $f, array $documentProof): array
    {
        $proof = $f['workflow_proof'] + ['reassessment_operation_scope' => 'CLOSURE_REASSESSMENT_LOCAL_001'];
        ApproachAssignmentAccess::runFixture(request(), $f['proof'], fn () => DocumentWorkspaceAccess::runFixture(request(), $documentProof,
            fn () => ReviewMaturityAccess::runFixture(request(), $proof, function () use ($f, $proof): void {
                $manager = app(ManageStageReassessmentOperations::class);
                foreach (DB::table('maturity_workflow_stages')->where('organization_id', $proof['organization_id'])
                    ->where('fixture_id', $proof['fixture_id'])->where('approach_id', $proof['approach_id'])->orderBy('stage_ordinal')->get() as $stage) {
                    foreach ([['author', ['open_reassessment', 'save_reassessment', 'history_reassessment']], ['reviewer', ['review_reassessment', 'history_reassessment']]] as [$name, $operations]) {
                        foreach ($operations as $operation) {
                            $manager->issue($f[$name]->public_id, $operation, $stage->public_id);
                        }
                    }
                }
            })));

        return $proof;
    }
}
