<?php

namespace App\Domain\Maturity;

use DomainException;
use Illuminate\Support\Str;

/** Versioned question input. This first text-answer implementation adds no implicit grants. */
final readonly class StageQuestion
{
    /** @param list<ExpectedStageEvidence> $expectedEvidence */
    public function __construct(public string $publicId, public string $text, public string $instructions,
        public bool $answerRequired, public array $expectedEvidence, public bool $active = true, public string $answerType = 'text')
    {
        if (! Str::isUlid($publicId) || ! mb_check_encoding($text, 'UTF-8') || mb_strlen($text) > 65535
            || preg_match('/[^\p{Z}\s]/u', $text) !== 1 || ! mb_check_encoding($instructions, 'UTF-8') || mb_strlen($instructions) > 65535
            || $answerType !== 'text' || ! array_is_list($expectedEvidence) || $expectedEvidence === []) {
            throw new DomainException('تعريف السؤال أو نوع الإجابة غير مدعوم.');
        }
        $ids = [];
        foreach ($expectedEvidence as $expected) {
            if (! $expected instanceof ExpectedStageEvidence || in_array(strtolower($expected->publicId), $ids, true)) {
                throw new DomainException('أضف شواهد متوقعة صالحة دون تكرار.');
            }
            $ids[] = strtolower($expected->publicId);
        }
    }

    public function snapshot(): array
    {
        return ['public_id' => strtolower($this->publicId), 'text' => $this->text, 'instructions' => $this->instructions,
            'answer_type' => $this->answerType, 'answer_required' => $this->answerRequired, 'active' => $this->active,
            'expected_evidence' => array_map(fn (ExpectedStageEvidence $expected) => $expected->snapshot(), $this->expectedEvidence)];
    }
}
