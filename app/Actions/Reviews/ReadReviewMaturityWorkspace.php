<?php

namespace App\Actions\Reviews;

use App\Actions\Maturity\SaveStageSubmission;
use App\Authorization\DocumentWorkspaceAccess;
use App\Authorization\ReviewMaturityAccess;
use App\Models\Approach;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class ReadReviewMaturityWorkspace
{
    public function listing(string $search = '', string $sort = 'created_at', string $direction = 'desc', int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $publicId = app(ReviewMaturityWorkspace::class)->approachPublicId() ?? throw new AuthorizationException;

        return app(ReviewMaturityAccess::class)->within('read_workflow', $publicId, null, function (User $actor, Approach $approach) use ($search, $sort, $direction, $page, $perPage) {
            if (! mb_check_encoding($search, 'UTF-8') || mb_strlen($search) > 255) {
                throw new \DomainException('معايير البحث غير صالحة.');
            }
            $proof = ReviewMaturityAccess::context();
            $now = ReviewMaturityAccess::timestamp();
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            // Assignment filtering is in SQL BEFORE count/search/sort/page, never collection filtering.
            $records = DB::table('approaches as a')->join('review_maturity_assignments as g', fn ($join) => $join
                ->on('g.organization_id', '=', 'a.organization_id')->on('g.approach_id', '=', 'a.id')
                ->where('g.fixture_id', $proof['fixture_id'])->where('g.scenario', ReviewMaturityAccess::SCENARIO)->where('g.classification', 'TEST_ONLY')
                ->where('g.grantee_id', $actor->id)->where('g.operation', 'read_workflow')->whereNull('g.stage_id')->whereNull('g.revoked_at')
                ->where('g.starts_at', '<=', $now)->where('g.expires_at', '>', $now))
                ->where('a.organization_id', $proof['organization_id'])->where('a.id', $proof['approach_id'])
                ->when($search !== '', fn ($query) => $query->whereRaw("a.name LIKE ? ESCAPE '!'", [$pattern]))
                ->orderBy('a.'.(in_array($sort, ['name', 'created_at'], true) ? $sort : 'created_at'), $direction === 'asc' ? 'asc' : 'desc')
                ->orderBy('a.id')->sharedLock()->paginate(max(1, min(50, $perPage)), ['a.public_id as approach_public_id', 'a.name', 'a.created_at'], 'page', max(1, $page));
            $records->setCollection($records->getCollection()->map(fn ($row) => (array) $row));

            return $records;
        });
    }

    public function show(string $publicId): array
    {
        return app(ReviewMaturityAccess::class)->within('read_workflow', $publicId, null, function (User $actor, Approach $approach): array {
            $proof = ReviewMaturityAccess::context();
            $base = fn (string $table) => DB::table($table)->where('organization_id', $approach->organization_id)->where('fixture_id', $proof['fixture_id'])->where('approach_id', $approach->id);
            $revision = DB::table('approach_content_revisions')->where('organization_id', $approach->organization_id)->where('approach_id', $approach->id)
                ->orderByDesc('version_number')->lockForUpdate()->first();
            $stages = [];
            foreach ($base('maturity_workflow_stages')->orderBy('stage_ordinal')->lockForUpdate()->get() as $stage) {
                $config = $base('maturity_workflow_configurations')->where('stage_id', $stage->id)->orderByDesc('version_number')->lockForUpdate()->first();
                $submission = $base('maturity_workflow_submissions')->where('stage_id', $stage->id)->orderByDesc('version_number')->lockForUpdate()->first();
                $assessment = $base('maturity_workflow_assessments')->where('stage_id', $stage->id)->lockForUpdate()->first();
                $review = $base('review_maturity_reviews')->where('stage_id', $stage->id)->orderByDesc('review_number')->lockForUpdate()->first();
                $submissionData = $submission ? $this->submissionDto($actor, $submission) : null;
                $approvedSubmission = $assessment ? $base('maturity_workflow_submissions')->where('stage_id', $stage->id)->where('id', $assessment->submission_id)->lockForUpdate()->first() : null;
                $stages[] = ['public_id' => $stage->public_id, 'id' => $stage->id, 'stage' => $stage->stage, 'ordinal' => $stage->stage_ordinal,
                    'configuration_token' => $config?->public_id ?? 'none', 'configuration' => $config ? json_decode($config->configuration_json, true, 64, JSON_THROW_ON_ERROR) : null,
                    'submission_token' => $submission?->public_id ?? 'none', 'submission' => $submissionData,
                    'assessment' => $assessment ? json_decode($assessment->snapshot_json, true, 64, JSON_THROW_ON_ERROR) + ['reason' => $assessment->reason, 'notes' => $assessment->notes, 'created_at' => $assessment->created_at,
                        'submission_snapshot' => $approvedSubmission ? $this->submissionDto($actor, $approvedSubmission) : null] : null,
                    'review' => $review ? $this->reviewDto($review) : null];
            }

            return ['approach_public_id' => $approach->public_id, 'name' => $approach->name, 'body' => $revision?->body ?? '',
                'draft_token' => $revision?->public_id ?? 'none', 'stages' => $stages,
                'review' => ($r = $base('review_maturity_reviews')->whereNull('stage_id')->orderByDesc('review_number')->lockForUpdate()->first()) ? $this->reviewDto($r) : null,
                'next_ordinal' => $base('maturity_workflow_assessments')->lockForUpdate()->count() + 1];
        });
    }

    public function reviews(string $publicId, ?int $stageId = null, int $page = 1): LengthAwarePaginator
    {
        return app(ReviewMaturityAccess::class)->within('read_workflow', $publicId, null, function (User $actor, Approach $approach) use ($stageId, $page) {
            $proof = ReviewMaturityAccess::context();
            $records = DB::table('review_maturity_reviews')->where('organization_id', $approach->organization_id)->where('fixture_id', $proof['fixture_id'])
                ->where('approach_id', $approach->id)->where('stage_id', $stageId)->orderByDesc('review_number')->sharedLock()->paginate(20, ['*'], 'page', max(1, $page));
            $records->setCollection($records->getCollection()->map(fn ($row) => $this->reviewDto($row)));

            return $records;
        });
    }

    public function evidenceOptions(string $publicId): array
    {
        return app(ReviewMaturityAccess::class)->within('read_workflow', $publicId, null, function (User $actor) {
            $proof = ReviewMaturityAccess::context();
            $options = [];
            foreach ($proof['synthetic_versions'] ?? [] as $versionId => $receipt) {
                try {
                    [$document, $version] = app(SaveStageSubmission::class)->version($actor, ['version_public_id' => $versionId, 'document_public_id' => $receipt['document_public_id']]);
                    $options[$document->public_id.':'.$version->public_id] = $document->title.' — إصدار '.$version->version_number.' (اصطناعي)';
                } catch (AuthorizationException) { /* Never reveal an inaccessible document/version. */
                }
            }
            if (DocumentWorkspaceAccess::hasFileScope()) {
                $targets = DB::table('document_file_operation_assignments as g')->join('document_versions as v', fn ($j) => $j
                    ->on('v.organization_id', '=', 'g.organization_id')->on('v.document_id', '=', 'g.document_id')->on('v.id', '=', 'g.version_id'))
                    ->where('g.organization_id', $proof['organization_id'])->where('g.fixture_id', $proof['fixture_id'])->where('g.grantee_id', $actor->id)
                    ->whereIn('g.operation', ['download_demo_unscanned', 'download_real'])->whereNull('g.revoked_at')
                    ->where('g.starts_at', '<=', ReviewMaturityAccess::timestamp())->where('g.expires_at', '>', ReviewMaturityAccess::timestamp())
                    ->orderByDesc('v.id')->limit(100)->select('g.document_public_id', 'v.public_id as version_public_id')->get();
                foreach ($targets as $target) {
                    try {
                        [$document, $version] = app(SaveStageSubmission::class)->version($actor, (array) $target);
                        $options[$document->public_id.':'.$version->public_id] = $document->title.' — إصدار '.$version->version_number.' (مرشح؛ يلزم تقييم الكفاية المستقل)';
                    } catch (AuthorizationException) { /* Current denied/expired/unsafe versions are never selectable. */
                    }
                }
            }

            return $options;
        });
    }

    private function reviewDto(object $review): array
    {
        return ['public_id' => $review->public_id, 'state' => $review->state, 'fingerprint' => $review->fingerprint,
            'review_number' => $review->review_number, 'reason' => $review->reason, 'created_at' => $review->created_at,
            'decided_at' => $review->decided_at, 'reviewer_public_id' => $review->reviewer_id ? User::where('organization_id', $review->organization_id)->whereKey($review->reviewer_id)->value('public_id') : null];
    }

    public function submissionDto(User $actor, object $submission): array
    {
        $proof = ReviewMaturityAccess::context() ?? throw new AuthorizationException;
        if (DB::transactionLevel() === 0 || (int) $submission->organization_id !== $proof['organization_id']
            || $submission->fixture_id !== $proof['fixture_id'] || (int) $submission->approach_id !== $proof['approach_id']) {
            throw new AuthorizationException;
        }
        $data = json_decode($submission->content_json, true, 64, JSON_THROW_ON_ERROR);
        foreach ($data['evidence'] as &$reference) {
            try {
                [$document, $version] = app(SaveStageSubmission::class)->version($actor, $reference);
                $reference['display'] = $document->title.' — إصدار '.$version->version_number.' — '.$version->public_id
                    .(isset((ReviewMaturityAccess::context()['synthetic_versions'] ?? [])[strtolower($version->public_id)])
                        ? ' (اصطناعي؛ بلا إتاحة ملف)' : ' (ملف عرض؛ الإتاحة لا تثبت كفايته كشاهد)');
            } catch (AuthorizationException) {
                // Preserve immutable SQL while withholding a now-inaccessible document identity.
                $reference = array_intersect_key($reference, array_flip(['question_public_id', 'expected_evidence_public_id']))
                    + ['display' => 'الشاهد التاريخي غير متاح بصلاحياتك الحالية.'];
            }
        }
        unset($reference);

        return $data;
    }
}
