<?php

namespace Tests\Unit\Maturity;

use App\Domain\Maturity\MaturityStage;
use App\Domain\Maturity\RatingBand;
use App\Domain\Maturity\RatingScale;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MaturityInvariantsTest extends TestCase
{
    public function test_the_five_stages_have_the_required_fixed_order_and_arabic_labels(): void
    {
        $this->assertSame(['orientation', 'system', 'implementation', 'measurement', 'improvement'], array_column(MaturityStage::cases(), 'value'));
        $this->assertSame(['التوجه', 'النظام', 'التنفيذ', 'القياس', 'التحسين والتطوير'],
            array_map(fn (MaturityStage $stage) => $stage->label(), MaturityStage::cases()));
    }

    public function test_scale_cardinality_is_configurable_and_valid_gapped_examples_remain_valid(): void
    {
        foreach ([[[0, 30], [31, 70], [71, 100]], [[5, 20], [21, 40], [41, 60], [61, 80], [81, 100]]] as $ranges) {
            $bands = array_map(fn (array $range) => $this->band($range[0], $range[1]), $ranges);
            $scale = new RatingScale($bands);
            $this->assertSame($bands, $scale->bands);
        }
    }

    public function test_configured_order_is_preserved_while_overlap_validation_uses_range_order(): void
    {
        $high = $this->band(71, 100);
        $low = $this->band(0, 30);
        $middle = $this->band(31, 70);
        $scale = new RatingScale([$high, $low, $middle]);
        $this->assertSame([$high, $low, $middle], $scale->bands);
    }

    #[DataProvider('invalidBounds')]
    public function test_invalid_or_nonfinite_bounds_are_rejected(int|float $lower, int|float $upper): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->band($lower, $upper);
    }

    public static function invalidBounds(): array
    {
        return [[-1, 20], [0, 101], [70, 30], [NAN, 40], [0, NAN], [INF, INF], [-INF, 20], [0, INF]];
    }

    public function test_bounds_are_not_rounded_or_replaced_with_a_numeric_score(): void
    {
        $band = $this->band(12.25, 29.75);
        $this->assertSame(12.25, $band->lowerBound);
        $this->assertSame(29.75, $band->upperBound);
        $this->assertSame('حالة يختارها المراجع', $band->name);
        $this->assertSame('وصف الحالة وقت الاختيار', $band->description);
    }

    #[DataProvider('overlappingRanges')]
    public function test_overlapping_ranges_are_rejected_regardless_of_configured_order(array $ranges): void
    {
        $bands = array_map(fn (array $range) => $this->band($range[0], $range[1]), $ranges);
        $this->expectException(InvalidArgumentException::class);
        new RatingScale($bands);
    }

    public static function overlappingRanges(): array
    {
        return [
            [[[0, 40], [30, 70]]],
            [[[71, 100], [0, 30], [20, 70]]],
            [[[0, 30], [30, 70]]],
            [[[0, 100], [40, 50]]],
            [[[0, 30], [0, 30]]],
        ];
    }

    public function test_empty_scale_is_rejected_without_inventing_a_fixed_band_count(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RatingScale([]);
    }

    public function test_invalid_band_payload_is_rejected_at_the_domain_boundary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RatingScale([['lowerBound' => 0, 'upperBound' => 100]]);
    }

    #[DataProvider('invalidNames')]
    public function test_band_names_must_be_meaningful_valid_utf8(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RatingBand($name, 'وصف', 0, 100);
    }

    public static function invalidNames(): array
    {
        return [[''], ['  '], ["\u{00A0}"], ["\xFF"]];
    }

    public function test_a_single_category_and_zero_width_range_are_not_arbitrarily_forbidden(): void
    {
        $band = $this->band(0, 0);
        $this->assertSame([$band], (new RatingScale([$band]))->bands);
    }

    public function test_original_configuration_cannot_be_mutated_through_a_snapshot(): void
    {
        $bands = [$this->band(0, 30), $this->band(31, 100)];
        $scale = new RatingScale($bands);
        $bands[0] = $this->band(0, 10);
        $this->assertSame(30, $scale->bands[0]->upperBound);

        try {
            $scale->bands[0] = $bands[0];
            $this->fail('Historical value arrays must be immutable.');
        } catch (\Error $error) {
            $this->assertStringContainsString('readonly', $error->getMessage());
        }

        try {
            $scale->bands[0]->name = 'اسم آخر';
            $this->fail('Historical category metadata must be immutable.');
        } catch (\Error $error) {
            $this->assertStringContainsString('readonly', $error->getMessage());
        }
    }

    private function band(int|float $lower, int|float $upper): RatingBand
    {
        return new RatingBand('حالة يختارها المراجع', 'وصف الحالة وقت الاختيار', $lower, $upper);
    }
}
