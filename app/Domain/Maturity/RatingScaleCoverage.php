<?php

namespace App\Domain\Maturity;

/** Arithmetic gaps only; never rounds bounds, rejects a valid scale or chooses a rating. */
final class RatingScaleCoverage
{
    /** @return list<array{lower_bound:int|float,upper_bound:int|float,lower_included:bool,upper_included:bool}> */
    public static function gaps(RatingScale $scale): array
    {
        $ordered = $scale->bands;
        usort($ordered, fn (RatingBand $a, RatingBand $b) => $a->lowerBound <=> $b->lowerBound);
        $gaps = [];
        $lower = 0;
        $included = true;
        foreach ($ordered as $band) {
            if ($band->lowerBound > $lower) {
                $gaps[] = ['lower_bound' => $lower, 'upper_bound' => $band->lowerBound,
                    'lower_included' => $included, 'upper_included' => false];
            }
            $lower = $band->upperBound;
            $included = false;
        }
        if ($lower < 100) {
            $gaps[] = ['lower_bound' => $lower, 'upper_bound' => 100, 'lower_included' => $included, 'upper_included' => true];
        }

        return $gaps;
    }
}
