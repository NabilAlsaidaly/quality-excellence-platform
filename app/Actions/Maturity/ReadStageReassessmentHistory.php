<?php

namespace App\Actions\Maturity;

use App\Actions\Reviews\ReadReviewMaturityWorkspace;
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
                    ->first() ?? throw new AuthorizationException;
                $history = DB::table('maturity_reassessment_attempts as a')->leftJoin('maturity_reassessment_decisions as d', fn ($join) => $join
                    ->on('d.organization_id', '=', 'a.organization_id')->on('d.fixture_id', '=', 'a.fixture_id')
                    ->on('d.approach_id', '=', 'a.approach_id')->on('d.stage_id', '=', 'a.stage_id')->on('d.attempt_id', '=', 'a.id'))
                    ->where('a.organization_id', $approach->organization_id)->where('a.fixture_id', $proof['fixture_id'])
                    ->where('a.approach_id', $approach->id)->where('a.stage_id', $stage->id)->where('a.classification', 'TEST_ONLY')
                    ->orderByDesc('a.attempt_number')->paginate(max(1, min(100, $perPage)),
                        ['a.public_id', 'a.state', 'a.revision', 'a.attempt_number', 'a.created_at', 'd.public_id as decision_public_id',
                            'd.selected_band_index', 'd.snapshot_json', 'd.reason'], 'page', max(1, $page));

                if (! ReviewMaturityAccess::hasReassessmentScope()) {
                    return ['initial' => $initial, 'recorded_history' => $history, 'effective_state_available' => false];
                }
                $base = fn (string $table) => DB::table($table)->where('organization_id', $approach->organization_id)
                    ->where('fixture_id', $proof['fixture_id'])->where('approach_id', $approach->id)->where('stage_id', $stage->id);
                $active = $base('maturity_reassessment_attempts')->where('state', 'open')->lockForUpdate()->first();
                $activeDto = null;
                if ($active !== null) {
                    $configuration = app(SaveStageReassessment::class)->configurationRow($active);
                    $saved = $base('maturity_reassessment_events')->where('attempt_id', $active->id)->where('event', 'saved')->orderByDesc('id')->lockForUpdate()->first();
                    $submission = $saved ? $base('maturity_workflow_submissions')->where('id', $saved->submission_id)->lockForUpdate()->first() : null;
                    $review = $submission ? $base('review_maturity_reviews')->where('submission_id', $submission->id)->orderByDesc('review_number')->lockForUpdate()->first() : null;
                    $activeDto = ['public_id' => $active->public_id, 'revision' => $active->revision, 'attempt_number' => $active->attempt_number,
                        'configuration' => json_decode($configuration->configuration_json, true, 64, JSON_THROW_ON_ERROR),
                        'configuration_public_id' => $configuration->public_id,
                        'submission' => $submission ? app(ReadReviewMaturityWorkspace::class)->submissionDto($actor, $submission) : null,
                        'review' => $review ? ['public_id' => $review->public_id, 'fingerprint' => $review->fingerprint, 'state' => $review->state, 'reason' => $review->reason] : null];
                }
                $effective = app(EffectiveMaturityState::class)->load($approach)['stages'][$stage->stage];
                $history->setCollection($history->getCollection()->map(function ($row) use ($base, $actor): array {
                    $attempt = $base('maturity_reassessment_attempts')->where('public_id', $row->public_id)->lockForUpdate()->first();
                    $saved = $base('maturity_reassessment_events')->where('attempt_id', $attempt->id)->where('event', 'saved')->orderByDesc('id')->lockForUpdate()->first();
                    $submission = $saved ? $base('maturity_workflow_submissions')->where('id', $saved->submission_id)->lockForUpdate()->first() : null;

                    return (array) $row + ['snapshot' => $row->snapshot_json ? json_decode($row->snapshot_json, true, 64, JSON_THROW_ON_ERROR) : null,
                        'submission' => $submission ? app(ReadReviewMaturityWorkspace::class)->submissionDto($actor, $submission) : null];
                }));

                return ['initial' => $initial, 'recorded_history' => $history, 'effective_state_available' => true,
                    'effective' => $effective, 'active_attempt' => $activeDto];
            });
    }
}
