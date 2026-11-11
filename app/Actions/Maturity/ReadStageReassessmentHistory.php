<?php

namespace App\Actions\Maturity;

use App\Authorization\ReviewMaturityAccess;
use App\Models\Approach;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ReadStageReassessmentHistory
{
    public function __construct(private OpenStageReassessment $boundary) {}

    public function execute(string $approachId, string $stageId, int $page = 1, int $perPage = 20): array
    {
        return $this->boundary->within('history_reassessment', $approachId, $stageId,
            function (User $actor, Approach $approach, object $stage) use ($page, $perPage): array {
                $proof = ReviewMaturityAccess::context() ?? throw new AuthorizationException;
                $initial = DB::table('maturity_workflow_assessments')->where('organization_id', $approach->organization_id)
                    ->where('fixture_id', $proof['fixture_id'])->where('approach_id', $approach->id)->where('stage_id', $stage->id)
                    ->first(['public_id', 'snapshot_json', 'reason', 'created_at']) ?? throw new AuthorizationException;
                $history = DB::table('maturity_reassessment_attempts as a')->leftJoin('maturity_reassessment_decisions as d', fn ($join) => $join
                    ->on('d.organization_id', '=', 'a.organization_id')->on('d.fixture_id', '=', 'a.fixture_id')
                    ->on('d.approach_id', '=', 'a.approach_id')->on('d.stage_id', '=', 'a.stage_id')->on('d.attempt_id', '=', 'a.id'))
                    ->where('a.organization_id', $approach->organization_id)->where('a.fixture_id', $proof['fixture_id'])
                    ->where('a.approach_id', $approach->id)->where('a.stage_id', $stage->id)->where('a.classification', 'TEST_ONLY')
                    ->orderByDesc('a.attempt_number')->paginate(max(1, min(100, $perPage)),
                        ['a.public_id', 'a.state', 'a.revision', 'a.attempt_number', 'a.created_at', 'd.public_id as decision_public_id',
                            'd.selected_band_index', 'd.snapshot_json', 'd.reason'], 'page', max(1, $page));

                return ['initial' => $initial, 'recorded_history' => $history, 'effective_state_available' => false];
            });
    }
}
