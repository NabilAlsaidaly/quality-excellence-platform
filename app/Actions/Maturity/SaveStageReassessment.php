<?php

namespace App\Actions\Maturity;

use App\Actions\Documents\AuthorizedDocumentEvidence;
use App\Actions\Documents\RegisterDocumentIntake;
use App\Actions\Notifications\ReviewMaturityNotifications;
use App\Actions\Reviews\ReviewMaturityWorkspace;
use App\Authorization\DocumentWorkspaceAccess;
use App\Authorization\ReviewMaturityAccess;
use App\Domain\Documents\Intake\InspectedDocument;
use App\Domain\Documents\Intake\QuarantinedDocument;
use App\Domain\Maturity\MaturityStage;
use App\Domain\Maturity\StageEvidenceReference;
use App\Domain\Maturity\StageSubmission;
use App\Domain\Reviews\ReviewSnapshot;
use App\Models\Approach;
use App\Models\Document;
use App\Models\Organization;
use App\Models\ReviewMaturityReview;
use App\Models\User;
use App\Services\Documents\InspectDocumentFile;
use App\Services\Documents\QuarantinedDocumentStore;
use DateTimeImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class SaveStageReassessment
{
    public function __construct(private OpenStageReassessment $boundary) {}

    public function execute(string $attemptId, array $answers, array $references, int $expectedRevision, string $requestKey): array
    {
        [$approachId, $stageId] = $this->boundary->coordinates($attemptId);

        return $this->boundary->within('save_reassessment', $approachId, $stageId,
            function (User $actor, Approach $approach, object $stage) use ($attemptId, $answers, $references, $expectedRevision, $requestKey): array {
                $attempt = $this->boundary->attempt($attemptId);
                if ((int) $attempt->author_id !== (int) $actor->id || ! Str::isUlid($requestKey)) {
                    throw new AuthorizationException;
                }
                $requestHash = hash('sha256', json_encode([$answers, $references, $expectedRevision, $actor->public_id], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                $prior = DB::table('maturity_reassessment_events')->where('organization_id', $attempt->organization_id)
                    ->where('attempt_id', $attempt->id)->where('request_key', $requestKey)->lockForUpdate()->first();
                if ($prior !== null) {
                    $details = json_decode($prior->details_json, true, 64, JSON_THROW_ON_ERROR);
                    if ($prior->event !== 'saved' || (int) $prior->actor_id !== (int) $actor->id
                        || ! hash_equals($details['request_hash'] ?? '', $requestHash)) {
                        throw new ConflictHttpException('معرّف الحفظ مستخدم لبيانات مختلفة.');
                    }

                    return ['review_public_id' => $details['review_public_id'], 'revision' => $details['revision']];
                }
                if ($attempt->state !== 'open' || $attempt->revision !== $expectedRevision || $expectedRevision >= 4294967295) {
                    throw new ConflictHttpException('تغيرت المحاولة؛ أعد فتحها.');
                }
                if (! array_is_list($references) || count($references) > 1000) {
                    throw new DomainException('مراجع الشواهد غير صالحة.');
                }
                $configurationRow = $this->configurationRow($attempt);
                $configuration = app(ConfigureMaturityStage::class)->configuration(json_decode($configurationRow->configuration_json, true, 64, JSON_THROW_ON_ERROR),
                    $configurationRow->public_id, Organization::findOrFail($attempt->organization_id)->public_id, MaturityStage::from($stage->stage));
                $evidence = [];
                foreach ($references as $reference) {
                    if (! is_array($reference) || array_diff(array_keys($reference), ['question_public_id', 'expected_evidence_public_id', 'document_public_id', 'version_public_id'])) {
                        throw new DomainException('مرجع الشاهد غير صالح.');
                    }
                    $evidence[] = new StageEvidenceReference($configuration->organizationPublicId, $reference['question_public_id'],
                        $reference['expected_evidence_public_id'], $reference['document_public_id'], $reference['version_public_id']);
                }
                $scope = ReviewMaturityWorkspace::attributes($approach);
                $value = new StageSubmission($scope['public_id'], $configuration->organizationPublicId, $approach->public_id,
                    $stage->public_id, $configuration, $answers, $evidence, $this->contributors($approach, $stage->id, $actor->id));
                $this->verifyEvidence($actor, $value);
                $latest = DB::table('maturity_workflow_submissions')->where('organization_id', $approach->organization_id)
                    ->where('fixture_id', $attempt->fixture_id)->where('approach_id', $approach->id)->where('stage_id', $stage->id)
                    ->orderByDesc('version_number')->lockForUpdate()->first();
                if (($latest?->version_number ?? 0) >= 4294967295) {
                    throw new ConflictHttpException('تجاوز عدد الإصدارات الحد التقني.');
                }
                $submissionId = DB::table('maturity_workflow_submissions')->insertGetId($scope + ['stage_id' => $stage->id,
                    'configuration_id' => $configurationRow->id, 'version_number' => ($latest?->version_number ?? 0) + 1,
                    'actor_id' => $actor->id, 'content_json' => json_encode($value->snapshot(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                    'fingerprint' => $value->fingerprint()]);
                foreach ($value->snapshot()['evidence'] as $reference) {
                    [$document, $version] = $this->version($actor, $reference);
                    DB::table('maturity_workflow_evidence')->insert(ReviewMaturityWorkspace::attributes($approach) + ['stage_id' => $stage->id,
                        'submission_id' => $submissionId, 'question_public_id' => $reference['question_public_id'],
                        'expected_evidence_public_id' => $reference['expected_evidence_public_id'], 'document_id' => $document->id, 'document_version_id' => $version->id]);
                }
                $previous = DB::table('review_maturity_reviews')->where('organization_id', $approach->organization_id)
                    ->where('fixture_id', $attempt->fixture_id)->where('approach_id', $approach->id)->where('stage_id', $stage->id)
                    ->orderByDesc('review_number')->lockForUpdate()->first();
                if ($previous?->state === 'pending') {
                    $owned = DB::table('maturity_reassessment_events')->where('organization_id', $attempt->organization_id)
                        ->where('attempt_id', $attempt->id)->where('event', 'saved')->where('submission_id', $previous->submission_id)->lockForUpdate()->first();
                    if ($owned === null) {
                        throw new ConflictHttpException('المراجعة الحالية لا تنتمي إلى المحاولة.');
                    }
                    if (DB::table('review_maturity_reviews')->where('id', $previous->id)->where('state', 'pending')
                        ->update(['state' => 'superseded', 'decided_at' => ReviewMaturityAccess::timestamp()]) !== 1) {
                        throw new ConflictHttpException('تغيرت المراجعة أثناء الحفظ.');
                    }
                }
                if (($previous?->review_number ?? 0) >= 4294967295) {
                    throw new ConflictHttpException('تجاوز عدد المراجعات الحد التقني.');
                }
                // A NEW attempt/save must not reuse the initial review merely because answers are equal.
                $reviewScope = ReviewMaturityWorkspace::attributes($approach);
                $review = ReviewSnapshot::open($reviewScope['public_id'], $value->reviewTarget(),
                    new DateTimeImmutable($reviewScope['created_at'].' UTC'), $previous?->public_id);
                DB::table('review_maturity_reviews')->insert($reviewScope + ['stage_id' => $stage->id, 'target_kind' => 'maturity_submission',
                    'submission_id' => $submissionId, 'review_number' => ($previous?->review_number ?? 0) + 1,
                    'fingerprint' => $review->target->fingerprint, 'opened_by' => $actor->id, 'request_key' => $requestKey,
                    'state' => 'pending', 'supersedes_id' => $previous?->id]);
                if (DB::table('maturity_reassessment_attempts')->where('id', $attempt->id)->where('state', 'open')
                    ->where('revision', $expectedRevision)->update(['revision' => $expectedRevision + 1]) !== 1) {
                    throw new ConflictHttpException('سبق حفظ تحديث آخر.');
                }
                $this->boundary->event($attempt, $actor, 'saved', $requestKey, $submissionId,
                    ['request_hash' => $requestHash, 'review_public_id' => $review->publicId, 'revision' => $expectedRevision + 1]);
                if (ReviewMaturityAccess::hasReassessmentScope()) {
                    app(ReviewMaturityNotifications::class)->deliver($actor, $approach,
                        ReviewMaturityReview::where('organization_id', $attempt->organization_id)->where('public_id', $review->publicId)->firstOrFail(), 'review_opened', $attempt);
                }

                return ['review_public_id' => $review->publicId, 'revision' => $expectedRevision + 1];
            });
    }

    public function configurationRow(object $attempt): object
    {
        $proof = ReviewMaturityAccess::context() ?? throw new AuthorizationException;
        if (DB::transactionLevel() === 0 || (int) $attempt->organization_id !== $proof['organization_id']
            || $attempt->fixture_id !== $proof['fixture_id'] || (int) $attempt->approach_id !== $proof['approach_id']) {
            throw new AuthorizationException;
        }
        $sourceId = $attempt->previous_attempt_id === null
            ? DB::table('maturity_workflow_assessments')->where('organization_id', $attempt->organization_id)->where('id', $attempt->initial_assessment_id)->value('submission_id')
            : DB::table('maturity_reassessment_attempts')->where('organization_id', $attempt->organization_id)->where('id', $attempt->previous_attempt_id)
                ->where('state', 'approved')->value('approved_submission_id');
        $source = DB::table('maturity_workflow_submissions')->where('organization_id', $attempt->organization_id)
            ->where('fixture_id', $attempt->fixture_id)->where('approach_id', $attempt->approach_id)->where('stage_id', $attempt->stage_id)
            ->where('id', $sourceId)->lockForUpdate()->first();
        if ($source === null || (int) $source->configuration_id !== (int) $attempt->configuration_id) {
            throw new ConflictHttpException('لا يطابق الإعداد لقطة آخر تقييم معتمد.');
        }

        return DB::table('maturity_workflow_configurations')->where('organization_id', $attempt->organization_id)
            ->where('fixture_id', $attempt->fixture_id)->where('approach_id', $attempt->approach_id)->where('stage_id', $attempt->stage_id)
            ->where('id', $attempt->configuration_id)->lockForUpdate()->first() ?? throw new AuthorizationException;
    }

    public function hydrate(Approach $approach, object $attempt, object $row): StageSubmission
    {
        $configurationRow = $this->configurationRow($attempt);
        if ((int) $row->organization_id !== (int) $attempt->organization_id || $row->fixture_id !== $attempt->fixture_id
            || (int) $row->approach_id !== (int) $attempt->approach_id || (int) $row->stage_id !== (int) $attempt->stage_id
            || (int) $row->configuration_id !== (int) $attempt->configuration_id) {
            throw new AuthorizationException;
        }
        $stage = DB::table('maturity_workflow_stages')->where('organization_id', $attempt->organization_id)->where('id', $attempt->stage_id)->first();
        $organization = Organization::findOrFail($attempt->organization_id);
        $configuration = app(ConfigureMaturityStage::class)->configuration(json_decode($configurationRow->configuration_json, true, 64, JSON_THROW_ON_ERROR),
            $configurationRow->public_id, $organization->public_id, MaturityStage::from($stage->stage));
        $data = json_decode($row->content_json, true, 64, JSON_THROW_ON_ERROR);
        $refs = array_map(fn ($ref) => new StageEvidenceReference($ref['organization_public_id'], $ref['question_public_id'],
            $ref['expected_evidence_public_id'], $ref['document_public_id'], $ref['version_public_id']), $data['evidence']);
        $value = new StageSubmission($row->public_id, $organization->public_id, $approach->public_id, $stage->public_id,
            $configuration, array_column($data['answers'], 'answer', 'question_public_id'), $refs, $this->contributors($approach, $stage->id, $row->actor_id));
        if ($data != $value->snapshot() || ! hash_equals($row->fingerprint, $value->fingerprint())) {
            throw new ConflictHttpException('تغيرت اللقطة المحفوظة أو سند إعدادها.');
        }

        return $value;
    }

    public function verifyEvidence(User $actor, StageSubmission $submission): void
    {
        foreach ($submission->snapshot()['evidence'] as $reference) {
            $this->version($actor, $reference);
        }
    }

    private function version(User $actor, array $reference): array
    {
        if (DocumentWorkspaceAccess::hasFileScope()) {
            return app(AuthorizedDocumentEvidence::class)->execute($actor, $reference);
        }
        $proof = ReviewMaturityAccess::context() ?? throw new AuthorizationException;
        $document = Document::where('organization_id', $proof['organization_id'])->where('public_id', $reference['document_public_id'])->lockForUpdate()->first()
            ?? throw new AuthorizationException;
        app(DocumentWorkspaceAccess::class)->authorize($actor, 'download_real', $document);
        $version = DB::table('document_versions')->where('organization_id', $proof['organization_id'])->where('document_id', $document->id)
            ->where('public_id', $reference['version_public_id'])->lockForUpdate()->first() ?? throw new AuthorizationException;
        $intake = DB::table('document_intakes')->where('organization_id', $proof['organization_id'])->where('fixture_id', $proof['fixture_id'])
            ->where('document_id', $document->id)->where('version_id', $version->id)->where('state', 'released')->lockForUpdate()->first();
        if ($intake === null || $intake->scan_result !== 'clean' || $intake->release_actor_id === null) {
            throw new AuthorizationException;
        }
        RegisterDocumentIntake::assertCleanScan($intake);
        $store = new QuarantinedDocumentStore(Storage::disk('local'), new InspectDocumentFile);
        $receipt = new QuarantinedDocument(strtolower(Organization::findOrFail($intake->organization_id)->public_id), strtolower($intake->public_id), $version->storage_key,
            new InspectedDocument($version->original_filename, $intake->inspected_media_type, $version->byte_size, $version->sha256));
        fclose($store->openForScan($receipt));

        return [$document, $version];
    }

    private function contributors(Approach $approach, int $stage, int $actor): array
    {
        $proof = ReviewMaturityAccess::context() ?? throw new AuthorizationException;
        $ids = DB::table('maturity_workflow_submissions')->where('organization_id', $approach->organization_id)->where('fixture_id', $proof['fixture_id'])
            ->where('approach_id', $approach->id)->where('stage_id', $stage)->orderBy('id')->lockForUpdate()->pluck('actor_id')->push($actor)->push($approach->created_by)->unique();

        return User::where('organization_id', $approach->organization_id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->pluck('public_id')->all();
    }
}
