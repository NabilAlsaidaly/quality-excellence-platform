<?php

namespace App\Domain\Reviews;

final readonly class ReviewChange
{
    /** @param list<ReviewSignal> $signals */
    public function __construct(public ReviewSnapshot $current, public ?ReviewSnapshot $previous, public bool $opened, public array $signals) {}
}
