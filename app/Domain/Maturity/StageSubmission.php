<?php

namespace App\Domain\Maturity;

use App\Domain\Reviews\ReviewTarget;
use App\Domain\Reviews\ReviewTargetKind;
use DomainException;
use Illuminate\Support\Str;

/**
 * Immutable reviewable answers/configuration, before a reviewer selects a rating.
 * Does not persist, authenticate, authorize document access, assess adequacy or unlock stages.
 */
final readonly class StageSubmission
{
    private string $snapshotJson;

    private ReviewTarget $target;

    /** @param array<string,string> $answers @param list<StageEvidenceReference> $evidence */
    public function __construct(public string $publicId, public string $organizationPublicId, public string $approachPublicId,
        public string $stageTargetPublicId, private StageConfigurationSnapshot $configuration, array $answers, array $evidence, array $contributors)
    {
        if (! Str::isUlid($approachPublicId) || strcasecmp($organizationPublicId, $configuration->organizationPublicId) !== 0 || ! array_is_list($evidence)) {
            throw new DomainException('سياق لقطة المرحلة أو شواهدها غير صالح.');
        }
        $questions = [];
        foreach ($configuration->questions as $question) {
            if ($question->active) {
                $questions[strtolower($question->publicId)] = $question;
            }
        }
        $normalized = [];
        foreach ($answers as $id => $answer) {
            $key = is_string($id) ? strtolower($id) : '';
            if (! isset($questions[$key]) || array_key_exists($key, $normalized) || ! is_string($answer)
                || ! mb_check_encoding($answer, 'UTF-8') || mb_strlen($answer) > 1000000) {
                throw new DomainException('إجابة السؤال غير صالحة أو لا تنتمي إلى الإعداد الحالي.');
            }
            $normalized[$key] = $answer;
        }
        $orderedAnswers = [];
        foreach ($questions as $id => $question) {
            if (array_key_exists($id, $normalized)) {
                $orderedAnswers[] = ['question_public_id' => $id, 'answer' => $normalized[$id]];
            }
        }
        $references = [];
        foreach ($evidence as $reference) {
            if (! $reference instanceof StageEvidenceReference || strcasecmp($organizationPublicId, $reference->organizationPublicId) !== 0) {
                throw new DomainException('لا يجوز إدخال شاهد لمؤسسة أخرى.');
            }
            $item = $reference->snapshot();
            $question = $questions[$item['question_public_id']] ?? null;
            $expectedIds = $question ? array_map(fn ($expected) => strtolower($expected->publicId), $question->expectedEvidence) : [];
            $key = implode(':', [$item['question_public_id'], $item['expected_evidence_public_id'], $item['document_public_id'], $item['version_public_id']]);
            if (! in_array($item['expected_evidence_public_id'], $expectedIds, true) || isset($references[$key])) {
                throw new DomainException('مرجع شاهد غير متوقع أو مكرر.');
            }
            $references[$key] = $item;
        }
        ksort($references);
        $this->snapshotJson = json_encode(['organization_public_id' => strtolower($organizationPublicId),
            'approach_public_id' => strtolower($approachPublicId), 'stage_target_public_id' => strtolower($stageTargetPublicId),
            'stage' => $configuration->stage->value, 'configuration' => $configuration->snapshot(),
            'answers' => $orderedAnswers, 'evidence' => array_values($references)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $this->target = new ReviewTarget($organizationPublicId, ReviewTargetKind::MaturitySubmission, $stageTargetPublicId, $publicId,
            $contributors, $this->fingerprint());
    }

    public function snapshot(): array
    {
        return json_decode($this->snapshotJson, true, 64, JSON_THROW_ON_ERROR);
    }

    public function fingerprint(): string
    {
        return hash('sha256', $this->snapshotJson);
    }

    public function stage(): MaturityStage
    {
        return $this->configuration->stage;
    }

    public function reviewTarget(): ReviewTarget
    {
        return $this->target;
    }

    /** Structural required inputs only. A Policy/SQL/human review must still verify actual evidence. */
    public function assertComplete(): void
    {
        $snapshot = $this->snapshot();
        $answers = array_column($snapshot['answers'], 'answer', 'question_public_id');
        $active = 0;
        foreach ($this->configuration->questions as $question) {
            if (! $question->active) {
                continue;
            }
            $active++;
            $id = strtolower($question->publicId);
            if ($question->answerRequired && preg_match('/[^\p{Z}\s]/u', $answers[$id] ?? '') !== 1) {
                throw new DomainException('أكمل الإجابات الإلزامية قبل الاعتماد.');
            }
            foreach ($question->expectedEvidence as $expected) {
                if ($expected->required && ! array_filter($snapshot['evidence'], fn ($reference) => $reference['question_public_id'] === $id
                    && $reference['expected_evidence_public_id'] === strtolower($expected->publicId))) {
                    throw new DomainException('اربط الشواهد المطلوبة لكل سؤال قبل الاعتماد.');
                }
            }
        }
        if ($active === 0) {
            throw new DomainException('يلزم سؤال فعال واحد على الأقل قبل اعتماد المرحلة.');
        }
    }
}
