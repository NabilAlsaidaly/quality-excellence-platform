<?php

namespace App\Domain\Maturity;

use InvalidArgumentException;

/** A categorical range, never a computed score or an approval decision. */
final readonly class RatingBand
{
    public function __construct(
        public string $name,
        public string $description,
        public int|float $lowerBound,
        public int|float $upperBound,
    ) {
        if (! mb_check_encoding($name, 'UTF-8') || preg_match('/[^\p{Z}\s]/u', $name) !== 1
            || ! mb_check_encoding($description, 'UTF-8')) {
            throw new InvalidArgumentException('أدخل اسم حالة ووصفًا صالحين.');
        }

        if (! is_finite((float) $lowerBound) || ! is_finite((float) $upperBound)
            || $lowerBound < 0 || $upperBound > 100 || $lowerBound > $upperBound) {
            throw new InvalidArgumentException('يجب أن يكون مجال الحالة ضمن 0–100، وألا يتجاوز الحد الأدنى الحد الأعلى.');
        }
    }
}
