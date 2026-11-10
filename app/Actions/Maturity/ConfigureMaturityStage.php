<?php

namespace App\Actions\Maturity;

use App\Actions\Reviews\ReviewMaturityWorkspace;
use App\Authorization\ReviewMaturityAccess;
use App\Domain\Maturity\ExpectedStageEvidence;
use App\Domain\Maturity\MaturityStage;
use App\Domain\Maturity\RatingBand;
use App\Domain\Maturity\RatingScale;
use App\Domain\Maturity\StageConfigurationSnapshot;
use App\Domain\Maturity\StageQuestion;
use App\Models\Approach;
use App\Models\Organization;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ConfigureMaturityStage
{
    public function stage(string $publicId): object
    {
        $proof = ReviewMaturityAccess::context() ?? throw new AuthorizationException;

        return DB::table('maturity_workflow_stages')->where('organization_id', $proof['organization_id'])->where('fixture_id', $proof['fixture_id'])
            ->where('approach_id', $proof['approach_id'])->where('public_id', $publicId)->first() ?? throw new AuthorizationException;
    }

    public function execute(string $approachId, string $stageId, array $data, string $expected): object
    {
        $stage = $this->stage($stageId);

        return app(ReviewMaturityAccess::class)->within('configure_stage', $approachId, $stage->id,
            function (User $actor, Approach $approach) use ($stage, $data, $expected): object {
                if (array_diff(array_keys($data), ['questions', 'scale']) || ! isset($data['questions'], $data['scale'])) {
                    throw new DomainException('إعدادات المرحلة غير صالحة.');
                }
                $scope = ReviewMaturityWorkspace::attributes($approach);
                $current = DB::table('maturity_workflow_configurations')->where('organization_id', $approach->organization_id)
                    ->where('fixture_id', $scope['fixture_id'])->where('approach_id', $approach->id)->where('stage_id', $stage->id)
                    ->orderByDesc('version_number')->lockForUpdate()->first();
                if (strcasecmp($expected, $current?->public_id ?? 'none') !== 0) {
                    throw new ConflictHttpException('تغيرت إعدادات المرحلة؛ أعد فتحها.');
                }
                $snapshot = $this->configuration($data, $scope['public_id'], Organization::findOrFail($approach->organization_id)->public_id, MaturityStage::from($stage->stage))->snapshot();
                $comparable = $snapshot;
                unset($comparable['public_id']);
                $fingerprint = hash('sha256', json_encode($comparable, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
                if ($current !== null && hash_equals($current->fingerprint, $fingerprint)) {
                    return $current;
                }
                $id = DB::table('maturity_workflow_configurations')->insertGetId($scope + ['stage_id' => $stage->id,
                    'version_number' => ($current?->version_number ?? 0) + 1, 'actor_id' => $actor->id,
                    'configuration_json' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), 'fingerprint' => $fingerprint]);

                return DB::table('maturity_workflow_configurations')->where('id', $id)->first();
            });
    }

    /** Typed codec for actual copied configuration, not an operational scale-placement model. */
    public function configuration(array $data, string $publicId, string $organizationId, MaturityStage $stage): StageConfigurationSnapshot
    {
        if (! is_array($data['questions'] ?? null) || ! array_is_list($data['questions']) || count($data['questions']) > 100
            || ! is_array($data['scale'] ?? null) || ! array_is_list($data['scale']) || count($data['scale']) > 100) {
            throw new DomainException('عدد الأسئلة أو حالات السلم غير مدعوم في العرض المحلي.');
        }
        $questions = [];
        foreach ($data['questions'] as $question) {
            if (! is_array($question) || array_diff(array_keys($question), ['public_id', 'text', 'instructions', 'answer_required', 'expected_evidence', 'active', 'answer_type'])
                || ! is_bool($question['answer_required'] ?? null) || ! is_bool($question['active'] ?? null)
                || ! is_array($question['expected_evidence'] ?? null) || ! array_is_list($question['expected_evidence']) || count($question['expected_evidence']) > 100) {
                throw new DomainException('بيانات السؤال غير صالحة.');
            }
            $expected = [];
            foreach ($question['expected_evidence'] as $item) {
                if (! is_array($item) || array_diff(array_keys($item), ['public_id', 'description', 'required']) || ! is_bool($item['required'] ?? null)) {
                    throw new DomainException('بيانات الشاهد المتوقع غير صالحة.');
                }
                $expected[] = new ExpectedStageEvidence($item['public_id'], $item['description'], $item['required']);
            }
            $questions[] = new StageQuestion($question['public_id'], $question['text'], $question['instructions'] ?? '', $question['answer_required'],
                $expected, $question['active'], $question['answer_type'] ?? 'text');
        }
        $bands = [];
        foreach ($data['scale'] as $band) {
            if (! is_array($band) || array_diff(array_keys($band), ['name', 'description', 'lower_bound', 'upper_bound'])
                || ! is_numeric($band['lower_bound'] ?? null) || ! is_numeric($band['upper_bound'] ?? null)) {
                throw new DomainException('حالة السلم غير صالحة.');
            }
            $bands[] = new RatingBand($band['name'], $band['description'] ?? '', $band['lower_bound'] + 0, $band['upper_bound'] + 0);
        }

        return new StageConfigurationSnapshot($publicId, $organizationId, $stage, $questions, new RatingScale($bands));
    }
}
