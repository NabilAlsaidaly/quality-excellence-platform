<?php

namespace Tests\Unit\Maturity;

use App\Domain\Maturity\RatingBand;
use App\Domain\Maturity\RatingScale;
use App\Domain\Maturity\RatingScaleCoverage;
use PHPUnit\Framework\TestCase;

class RatingScaleCoverageTest extends TestCase
{
    private function band(int|float $lower, int|float $upper): RatingBand
    {
        return new RatingBand('حالة', 'وصف الحالة', $lower, $upper);
    }

    public function test_full_range_has_no_gaps_and_does_not_create_an_assessment(): void
    {
        $scale = new RatingScale([$this->band(0, 100)]);
        $this->assertSame([], RatingScaleCoverage::gaps($scale));
        $this->assertCount(1, $scale->bands);
        $this->assertFalse(method_exists(RatingScaleCoverage::class, 'score'));
    }

    public function test_existing_valid_three_band_example_reports_open_intervals_without_rejecting_it(): void
    {
        $scale = new RatingScale([$this->band(0, 30), $this->band(31, 70), $this->band(71, 100)]);
        $this->assertSame([
            ['lower_bound' => 30, 'upper_bound' => 31, 'lower_included' => false, 'upper_included' => false],
            ['lower_bound' => 70, 'upper_bound' => 71, 'lower_included' => false, 'upper_included' => false],
        ], RatingScaleCoverage::gaps($scale));
        $this->assertCount(3, $scale->bands);
    }

    public function test_uncovered_endpoints_are_included_but_configured_boundaries_are_not(): void
    {
        $this->assertSame([
            ['lower_bound' => 0, 'upper_bound' => 5, 'lower_included' => true, 'upper_included' => false],
            ['lower_bound' => 80, 'upper_bound' => 100, 'lower_included' => false, 'upper_included' => true],
        ], RatingScaleCoverage::gaps(new RatingScale([$this->band(5, 80)])));
    }

    public function test_decimal_bounds_and_configured_category_order_are_never_rounded_or_changed(): void
    {
        $high = $this->band(29.875, 100);
        $low = $this->band(0, 29.75);
        $scale = new RatingScale([$high, $low]);
        $this->assertSame([
            ['lower_bound' => 29.75, 'upper_bound' => 29.875, 'lower_included' => false, 'upper_included' => false],
        ], RatingScaleCoverage::gaps($scale));
        $this->assertSame([$high, $low], $scale->bands);
    }

    public function test_valid_zero_width_category_is_retained_with_its_exact_remaining_gap(): void
    {
        $band = $this->band(0, 0);
        $scale = new RatingScale([$band]);
        $this->assertSame([
            ['lower_bound' => 0, 'upper_bound' => 100, 'lower_included' => false, 'upper_included' => true],
        ], RatingScaleCoverage::gaps($scale));
        $this->assertSame([$band], $scale->bands);
    }
}
