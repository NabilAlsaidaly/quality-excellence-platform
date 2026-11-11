<?php

namespace Tests\Support;

use App\Domain\Maturity\ApprovedStageAssessment;
use App\Domain\Maturity\ExpectedStageEvidence;
use App\Domain\Maturity\InitialMaturityCycle;
use App\Domain\Maturity\MaturityStage;
use App\Domain\Maturity\RatingBand;
use App\Domain\Maturity\RatingScale;
use App\Domain\Maturity\StageConfigurationSnapshot;
use App\Domain\Maturity\StageQuestion;
use App\Domain\Maturity\StageSubmission;
use App\Domain\Reviews\ReviewSnapshot;
use App\Domain\Reviews\ReviewState;
use DateTimeImmutable;
use Illuminate\Support\Str;

/** Domain samples only: no database grant, file/evidence authority or actual reviewer authentication. */
final class MaturityAssessmentSamples
{
    public static function cycle(int $band = 1, bool $reverseScale = false): array
    {
        $organization = strtolower((string) Str::ulid());
        $approach = strtolower((string) Str::ulid());
        $cycle = InitialMaturityCycle::begin($organization, $approach);
        $records = [];
        foreach (MaturityStage::cases() as $stage) {
            $bands = [new RatingBand('Low', 'test', 0, 30), new RatingBand('High', 'test', 31, 100)];
            $configuration = new StageConfigurationSnapshot((string) Str::ulid(), $organization, $stage,
                [new StageQuestion((string) Str::ulid(), 'Question', 'Instructions', true,
                    [new ExpectedStageEvidence((string) Str::ulid(), 'Optional fixture evidence', false)])],
                new RatingScale($reverseScale ? array_reverse($bands) : $bands));
            $records[$stage->value] = self::record($organization, $approach, (string) Str::ulid(), $configuration, $band, 'initial');
            $cycle = $cycle->withAssessment($records[$stage->value]['assessment']);
        }

        return compact('organization', 'approach', 'cycle', 'records');
    }

    public static function next(array $record, int $band, string $answer = 'reassessed', ?StageConfigurationSnapshot $configuration = null): array
    {
        $old = $record['submission'];

        return self::record($old->organizationPublicId, $old->approachPublicId, $old->stageTargetPublicId,
            $configuration ?? $record['configuration'], $band, $answer);
    }

    private static function record(string $organization, string $approach, string $stageId,
        StageConfigurationSnapshot $configuration, int $band, string $answer): array
    {
        $question = $configuration->questions[0];
        $submission = new StageSubmission((string) Str::ulid(), $organization, $approach, $stageId, $configuration,
            [$question->publicId => $answer], [], [(string) Str::ulid()]);
        $review = ReviewSnapshot::open((string) Str::ulid(), $submission->reviewTarget(), new DateTimeImmutable('2026-11-11T00:00:00Z'));
        $review = $review->decide((string) Str::ulid(), $submission->publicId, ReviewState::Approved, 'Independent test reason', new DateTimeImmutable('2026-11-11T00:01:00Z'));
        $assessment = new ApprovedStageAssessment($approach, $configuration->stage, $configuration->scale, $band,
            $submission->snapshot()['answers'], $review, $submission);

        return compact('configuration', 'submission', 'assessment');
    }
}
