<?php

namespace App\Domain\Maturity;

use InvalidArgumentException;

/**
 * Immutable definition only, without persistence or a scale placement decision.
 * Coverage-gap warnings await a separately specified precision/adjacency contract.
 */
final readonly class RatingScale
{
    /** @var list<RatingBand> */
    public array $bands;

    /** @param list<RatingBand> $bands */
    public function __construct(array $bands)
    {
        if ($bands === [] || ! array_is_list($bands)) {
            throw new InvalidArgumentException('أضف حالات السلم بالترتيب المطلوب.');
        }

        foreach ($bands as $band) {
            if (! $band instanceof RatingBand) {
                throw new InvalidArgumentException('تعريف حالة السلم غير صالح.');
            }
        }

        $byRange = $bands;
        usort($byRange, fn (RatingBand $a, RatingBand $b) => $a->lowerBound <=> $b->lowerBound);
        for ($index = 1; $index < count($byRange); $index++) {
            if ($byRange[$index]->lowerBound <= $byRange[$index - 1]->upperBound) {
                throw new InvalidArgumentException('تتداخل مجالات حالات السلم.');
            }
        }

        $this->bands = $bands;
    }
}
