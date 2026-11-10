<?php

namespace App\Actions\Maturity;

use App\Actions\Reviews\ReviewMaturityWorkspace;
use App\Actions\Reviews\SynchronizeReview;
use App\Authorization\DocumentWorkspaceAccess;
use App\Authorization\ReviewMaturityAccess;
use App\Domain\Maturity\MaturityStage;
use App\Domain\Maturity\StageEvidenceReference;
use App\Domain\Maturity\StageSubmission;
use App\Models\Approach;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\ReviewMaturityReview;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class SaveStageSubmission
{
    public function execute(string $approachId, string $stageId, array $answers, array $references,
        string $expectedSubmission, string $expectedConfiguration, string $requestKey): ?ReviewMaturityReview
    {
        $stage = app(ConfigureMaturityStage::class)->stage($stageId);

        return app(ReviewMaturityAccess::class)->within('answer_stage', $approachId, $stage->id,
            function (User $actor, Approach $approach) use ($stage, $answers, $references, $expectedSubmission, $expectedConfiguration, $requestKey): ?ReviewMaturityReview {
                $this->assertInitialStage($approach, $stage);
                if (! Str::isUlid($requestKey)) {
                    throw new DomainException('معرّف الحفظ غير صالح.');
                }
                $scope = ReviewMaturityWorkspace::attributes($approach);
                $config = DB::table('maturity_workflow_configurations')->where('organization_id', $approach->organization_id)->where('fixture_id', $scope['fixture_id'])
                    ->where('approach_id', $approach->id)->where('stage_id', $stage->id)->orderByDesc('version_number')->lockForUpdate()->first();
                if ($config === null || strcasecmp($config->public_id, $expectedConfiguration) !== 0) {
                    throw new ConflictHttpException('تغيرت إعدادات الأسئلة؛ أعد فتح المرحلة.');
                }
                $configuration = app(ConfigureMaturityStage::class)->configuration(json_decode($config->configuration_json, true, 64, JSON_THROW_ON_ERROR),
                    $config->public_id, Organization::findOrFail($approach->organization_id)->public_id, MaturityStage::from($stage->stage));
                if (! array_is_list($references) || count($references) > 1000) {
                    throw new DomainException('مراجع الشواهد غير صالحة.');
                }
                $evidence = [];
                foreach ($references as $row) {
                    if (! is_array($row) || array_diff(array_keys($row), ['question_public_id', 'expected_evidence_public_id', 'document_public_id', 'version_public_id'])) {
                        throw new DomainException('مرجع الشاهد غير صالح.');
                    }
                    $evidence[] = new StageEvidenceReference($configuration->organizationPublicId, $row['question_public_id'],
                        $row['expected_evidence_public_id'], $row['document_public_id'], $row['version_public_id']);
                }
                $submission = new StageSubmission($scope['public_id'], $configuration->organizationPublicId, $approach->public_id,
                    $stage->public_id, $configuration, $answers, $evidence, $this->contributors($approach, $stage->id, $actor->id));
                $this->verifyEvidence($actor, $submission);
                $prior = ReviewMaturityReview::where('organization_id', $approach->organization_id)->where('fixture_id', $scope['fixture_id'])
                    ->where('approach_id', $approach->id)->where('request_key', $requestKey)->lockForUpdate()->first();
                if ($prior !== null) {
                    if ($prior->target_kind !== 'maturity_submission' || (int) $prior->stage_id !== (int) $stage->id
                        || (int) $prior->opened_by !== (int) $actor->id || ! hash_equals($prior->fingerprint, $submission->fingerprint())) {
                        throw new ConflictHttpException('معرّف الحفظ مستخدم لبيانات أخرى.');
                    }

                    return $prior;
                }
                $current = DB::table('maturity_workflow_submissions')->where('organization_id', $approach->organization_id)->where('fixture_id', $scope['fixture_id'])
                    ->where('approach_id', $approach->id)->where('stage_id', $stage->id)->orderByDesc('version_number')->lockForUpdate()->first();
                if (strcasecmp($expectedSubmission, $current?->public_id ?? 'none') !== 0) {
                    throw new ConflictHttpException('ظهرت إجابات أحدث؛ أعد فتح المرحلة.');
                }
                if ($current !== null && hash_equals($current->fingerprint, $submission->fingerprint())) {
                    return null;
                }
                $id = DB::table('maturity_workflow_submissions')->insertGetId($scope + ['stage_id' => $stage->id, 'configuration_id' => $config->id,
                    'version_number' => ($current?->version_number ?? 0) + 1, 'actor_id' => $actor->id,
                    'content_json' => json_encode($submission->snapshot(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), 'fingerprint' => $submission->fingerprint()]);
                foreach ($submission->snapshot()['evidence'] as $reference) {
                    [$document, $version] = $this->version($actor, $reference);
                    DB::table('maturity_workflow_evidence')->insert(ReviewMaturityWorkspace::attributes($approach) + ['stage_id' => $stage->id, 'submission_id' => $id,
                        'question_public_id' => $reference['question_public_id'], 'expected_evidence_public_id' => $reference['expected_evidence_public_id'],
                        'document_id' => $document->id, 'document_version_id' => $version->id]);
                }

                return app(SynchronizeReview::class)->execute($actor, $approach, 'maturity_submission', DB::table('maturity_workflow_submissions')->where('id', $id)->first(), $stage->id, $requestKey);
            });
    }

    public function assertInitialStage(Approach $approach, object $stage): void
    {
        $proof = ReviewMaturityAccess::context() ?? throw new AuthorizationException;
        $history = DB::table('maturity_workflow_assessments')->where('organization_id', $approach->organization_id)->where('fixture_id', $proof['fixture_id'])
            ->where('approach_id', $approach->id)->orderBy('stage_ordinal')->lockForUpdate()->get();
        if ($stage->stage_ordinal !== $history->count() + 1) {
            throw new ConflictHttpException('هذه الرحلة للدورة الأولية؛ لا تتجاوز المرحلة الحالية ولا تستبدل تقييمًا تاريخيًا.');
        }
        foreach ($history as $index => $assessment) {
            if ($assessment->stage_ordinal !== $index + 1) {
                throw new ConflictHttpException('سلسلة التقييمات غير مكتملة.');
            }
        }
    }

    public function hydrate(Approach $approach, object $row): StageSubmission
    {
        $proof = ReviewMaturityAccess::context() ?? throw new AuthorizationException;
        if ((int) $row->organization_id !== (int) $approach->organization_id || $row->fixture_id !== $proof['fixture_id'] || (int) $row->approach_id !== (int) $approach->id) {
            throw new AuthorizationException;
        }
        $stage = DB::table('maturity_workflow_stages')->where('organization_id', $approach->organization_id)->where('fixture_id', $proof['fixture_id'])
            ->where('approach_id', $approach->id)->where('id', $row->stage_id)->lockForUpdate()->first() ?? throw new AuthorizationException;
        $config = DB::table('maturity_workflow_configurations')->where('organization_id', $approach->organization_id)->where('fixture_id', $proof['fixture_id'])
            ->where('approach_id', $approach->id)->where('stage_id', $stage->id)->where('id', $row->configuration_id)->lockForUpdate()->first() ?? throw new AuthorizationException;
        $data = json_decode($row->content_json, true, 64, JSON_THROW_ON_ERROR);
        $configuration = app(ConfigureMaturityStage::class)->configuration(json_decode($config->configuration_json, true, 64, JSON_THROW_ON_ERROR),
            $config->public_id, Organization::findOrFail($approach->organization_id)->public_id, MaturityStage::from($stage->stage));
        $refs = array_map(fn ($ref) => new StageEvidenceReference($ref['organization_public_id'], $ref['question_public_id'], $ref['expected_evidence_public_id'],
            $ref['document_public_id'], $ref['version_public_id']), $data['evidence']);
        $value = new StageSubmission($row->public_id, $configuration->organizationPublicId, $approach->public_id, $stage->public_id, $configuration,
            array_column($data['answers'], 'answer', 'question_public_id'), $refs, $this->contributors($approach, $stage->id, $row->actor_id));
        // MySQL's native JSON normalizes object-key order; array order and typed codec remain authoritative.
        if (! hash_equals($row->fingerprint, $value->fingerprint()) || $data != $value->snapshot()) {
            throw new ConflictHttpException('لقطة الإجابات لا تطابق إصدار الإعدادات المحفوظ.');
        }

        return $value;
    }

    private function contributors(Approach $approach, int $stageId, int $currentActor): array
    {
        $proof = ReviewMaturityAccess::context() ?? throw new AuthorizationException;
        $ids = DB::table('maturity_workflow_submissions')->where('organization_id', $approach->organization_id)->where('fixture_id', $proof['fixture_id'])
            ->where('approach_id', $approach->id)->where('stage_id', $stageId)->orderBy('id')->lockForUpdate()->pluck('actor_id')->push($currentActor)->push($approach->created_by)->unique();

        return User::where('organization_id', $approach->organization_id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->pluck('public_id')->all();
    }

    public function verifyEvidence(User $actor, StageSubmission $submission): void
    {
        foreach ($submission->snapshot()['evidence'] as $reference) {
            $this->version($actor, $reference);
        }
    }

    /** Actual DocumentPolicy + exact owned synthetic version bytes, never a clean/scanner assertion. */
    public function version(User $actor, array $reference): array
    {
        $proof = ReviewMaturityAccess::context() ?? throw new AuthorizationException;
        $receipt = $proof['synthetic_versions'][strtolower($reference['version_public_id'])] ?? null;
        $document = Document::where('organization_id', $proof['organization_id'])->where('public_id', $reference['document_public_id'])->lockForUpdate()->first();
        if ($document === null || $receipt === null || strcasecmp($receipt['document_public_id'], $document->public_id) !== 0
            || (int) $document->created_by !== $proof['author_id']) {
            throw new AuthorizationException;
        }
        app(DocumentWorkspaceAccess::class)->authorize($actor, 'view', $document);
        app(DocumentWorkspaceAccess::class)->authorize($actor, 'history', $document);
        $version = DocumentVersion::where('organization_id', $proof['organization_id'])->where('document_id', $document->id)
            ->where('public_id', $reference['version_public_id'])->lockForUpdate()->first() ?? throw new AuthorizationException;
        if ((int) $version->actor_id !== $proof['author_id'] || $version->storage_disk !== 'local' || ! hash_equals($receipt['sha256'], $version->sha256)
            || $receipt['byte_size'] !== $version->byte_size || ! Storage::disk('local')->exists($version->storage_key)) {
            throw new AuthorizationException;
        }
        $bytes = Storage::disk('local')->get($version->storage_key);
        if (! preg_match('/\ADOCUMENT_LOCAL_001 harmless synthetic fixture [0-9A-HJKMNP-TV-Z]{26}\z/', $bytes)
            || strlen($bytes) !== $version->byte_size || ! hash_equals($version->sha256, hash('sha256', $bytes))) {
            throw new AuthorizationException;
        }

        return [$document, $version];
    }
}
