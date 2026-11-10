<?php

namespace App\Domain\Maturity;

use App\Domain\Reviews\ReviewSnapshot;
use App\Domain\Reviews\ReviewState;
use App\Domain\Reviews\ReviewTargetKind;
use DomainException;
use Illuminate\Support\Str;
use JsonException;

/**
 * Immutable domain snapshot; it neither authenticates a reviewer nor persists an approval.
 * A future application transaction must validate current Policy, required questions/evidence
 * and the persisted target/revision binding before consuming this domain value.
 */
final readonly class ApprovedStageAssessment
{
    private string $snapshotJson;

    public function __construct(
        public string $approachPublicId,
        public MaturityStage $stage,
        RatingScale $scale,
        int $selectedBandIndex,
        array $answers,
        public ReviewSnapshot $review,
        ?StageSubmission $submission = null,
    ) {
        $json = self::encode($approachPublicId, $stage, $scale, $selectedBandIndex, $answers);
        if ($submission !== null) {
            $submission->assertComplete();
            $target = $submission->reviewTarget();
            $rated = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            $payload = $submission->snapshot();
            if ($review->state !== ReviewState::Approved || ! $review->target->sameTarget($target)
                || strcasecmp($review->target->revisionPublicId, $target->revisionPublicId) !== 0
                || ! hash_equals($review->target->fingerprint, $submission->fingerprint())
                || $review->target->contributorPublicIds !== $target->contributorPublicIds
                || strcasecmp($approachPublicId, $submission->approachPublicId) !== 0 || $stage !== $submission->stage()
                || $rated['scale'] !== $payload['configuration']['scale'] || $answers !== $payload['answers']) {
                throw new DomainException('لا يطابق التقييم الإجابات والإعدادات والإصدار الذي راجعه المراجع.');
            }
            $this->snapshotJson = $json;

            return;
        }
        if ($review->state !== ReviewState::Approved || $review->target->kind !== ReviewTargetKind::MaturitySubmission
            || ! hash_equals($review->target->fingerprint, hash('sha256', $json))) {
            throw new DomainException('يلزم قرار مراجعة مستقل ومعتمد للّقطة نفسها.');
        }
        $this->snapshotJson = $json;
    }

    public static function fingerprint(string $approachPublicId, MaturityStage $stage, RatingScale $scale, int $selectedBandIndex, array $answers): string
    {
        return hash('sha256', self::encode($approachPublicId, $stage, $scale, $selectedBandIndex, $answers));
    }

    public function snapshot(): array
    {
        return json_decode($this->snapshotJson, true, 64, JSON_THROW_ON_ERROR);
    }

    public function selectedBand(): RatingBand
    {
        $snapshot = $this->snapshot();
        $band = $snapshot['scale'][$snapshot['selected_band_index']];

        return new RatingBand($band['name'], $band['description'], $band['lower_bound'], $band['upper_bound']);
    }

    private static function encode(string $approachPublicId, MaturityStage $stage, RatingScale $scale, int $selectedBandIndex, array $answers): string
    {
        if (! Str::isUlid($approachPublicId) || ! array_key_exists($selectedBandIndex, $scale->bands)) {
            throw new DomainException('اختر منهجية وحالة تقييم صالحتين.');
        }
        self::assertSnapshotValues($answers);
        try {
            return json_encode([
                'approach_public_id' => strtolower($approachPublicId),
                'stage' => $stage->value,
                'scale' => array_map(fn (RatingBand $band) => [
                    'name' => $band->name, 'description' => $band->description,
                    'lower_bound' => $band->lowerBound, 'upper_bound' => $band->upperBound,
                ], $scale->bands),
                'selected_band_index' => $selectedBandIndex,
                'answers' => $answers,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION, 64);
        } catch (JsonException $exception) {
            throw new DomainException('تعذّر تثبيت لقطة التقييم.', previous: $exception);
        }
    }

    private static function assertSnapshotValues(array $values, int $depth = 0): void
    {
        if ($depth > 48) {
            throw new DomainException('لقطة التقييم متداخلة أكثر من الحد المدعوم.');
        }
        foreach ($values as $value) {
            if (is_array($value)) {
                self::assertSnapshotValues($value, $depth + 1);
            } elseif ((! is_scalar($value) && $value !== null) || (is_float($value) && ! is_finite($value))) {
                throw new DomainException('تقبل اللقطة بيانات ثابتة فقط.');
            }
        }
    }
}
