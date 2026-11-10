<?php

namespace App\Domain\Maturity;

use DomainException;
use Illuminate\Support\Str;

/** Copied settings for a revision; no decision about operational scale placement/inheritance. */
final readonly class StageConfigurationSnapshot
{
    /** @param list<StageQuestion> $questions */
    public function __construct(public string $publicId, public string $organizationPublicId, public MaturityStage $stage,
        public array $questions, public RatingScale $scale)
    {
        if (! Str::isUlid($publicId) || ! Str::isUlid($organizationPublicId) || ! array_is_list($questions)) {
            throw new DomainException('إصدار إعداد المرحلة غير صالح.');
        }
        $ids = [];
        foreach ($questions as $question) {
            if (! $question instanceof StageQuestion || in_array(strtolower($question->publicId), $ids, true)) {
                throw new DomainException('تعريف الأسئلة أو ترتيبها غير صالح.');
            }
            $ids[] = strtolower($question->publicId);
        }
    }

    public function snapshot(): array
    {
        return ['public_id' => strtolower($this->publicId), 'organization_public_id' => strtolower($this->organizationPublicId),
            'stage' => $this->stage->value, 'questions' => array_map(fn (StageQuestion $question) => $question->snapshot(), $this->questions),
            'scale' => array_map(fn (RatingBand $band) => ['name' => $band->name, 'description' => $band->description,
                'lower_bound' => $band->lowerBound, 'upper_bound' => $band->upperBound], $this->scale->bands)];
    }
}
