<?php

namespace Tests\Unit\Maturity;

use App\Domain\Maturity\ExpectedStageEvidence;
use App\Domain\Maturity\MaturityStage;
use App\Domain\Maturity\StageConfigurationSnapshot;
use App\Domain\Maturity\StageQuestion;
use App\Domain\Maturity\TestOnlyReassessmentDependencies;
use App\Domain\Reviews\ReviewConflict;
use DomainException;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use Tests\Support\MaturityAssessmentSamples;

class TestOnlyReassessmentDependenciesTest extends TestCase
{
    private function projection(array $f): TestOnlyReassessmentDependencies
    {
        return TestOnlyReassessmentDependencies::fromInitial($f['cycle'], array_map(fn ($record) => $record['submission'], $f['records']));
    }

    public function test_lower_category_requires_sequential_revalidation_without_touching_original_approvals(): void
    {
        $f = MaturityAssessmentSamples::cycle();
        $original = $this->projection($f);
        $next = MaturityAssessmentSamples::next($f['records']['orientation'], 0);
        $current = $original->withApproval($next['assessment'], $next['submission'], $f['records']['orientation']['assessment']->review->publicId);
        $this->assertFalse($current->requiresRevalidation(MaturityStage::Orientation));
        foreach (array_slice(MaturityStage::cases(), 1) as $stage) {
            $this->assertTrue($current->requiresRevalidation($stage));
            $this->assertFalse($original->requiresRevalidation($stage));
        }
        $this->assertTrue($current->mayReassess(MaturityStage::System));
        $this->assertFalse($current->mayReassess(MaturityStage::Implementation));
        foreach (array_slice(MaturityStage::cases(), 1) as $stage) {
            $record = $f['records'][$stage->value];
            $approved = MaturityAssessmentSamples::next($record, 1);
            $current = $current->withApproval($approved['assessment'], $approved['submission'], $record['assessment']->review->publicId);
            $this->assertFalse($current->requiresRevalidation($stage));
        }
        foreach (MaturityStage::cases() as $stage) {
            $this->assertFalse($current->requiresRevalidation($stage));
        }
        $this->assertSame($f['records']['orientation']['assessment'], $f['cycle']->assessment(MaturityStage::Orientation));
    }

    public function test_a_downstream_approval_cannot_skip_an_unrevalidated_dependency(): void
    {
        $f = MaturityAssessmentSamples::cycle();
        $lower = MaturityAssessmentSamples::next($f['records']['orientation'], 0);
        $projection = $this->projection($f)->withApproval($lower['assessment'], $lower['submission'], $f['records']['orientation']['assessment']->review->publicId);
        $skip = MaturityAssessmentSamples::next($f['records']['implementation'], 1);
        $this->expectException(DomainException::class);
        $projection->withApproval($skip['assessment'], $skip['submission'], $f['records']['implementation']['assessment']->review->publicId);
    }

    public function test_higher_and_equal_categories_preserve_downstream_validity(): void
    {
        foreach ([0, 1] as $initialBand) {
            $f = MaturityAssessmentSamples::cycle($initialBand);
            $next = MaturityAssessmentSamples::next($f['records']['orientation'], 1);
            $projection = $this->projection($f)->withApproval($next['assessment'], $next['submission'], $f['records']['orientation']['assessment']->review->publicId);
            foreach (MaturityStage::cases() as $stage) {
                $this->assertFalse($projection->requiresRevalidation($stage));
            }
        }
    }

    public function test_changed_question_configuration_is_not_silently_substituted(): void
    {
        $f = MaturityAssessmentSamples::cycle();
        $old = $f['records']['orientation'];
        $configuration = new StageConfigurationSnapshot((string) Str::ulid(), $f['organization'], MaturityStage::Orientation,
            [new StageQuestion((string) Str::ulid(), 'Changed', 'Changed', true,
                [new ExpectedStageEvidence((string) Str::ulid(), 'Optional fixture evidence', false)])], $old['configuration']->scale);
        $next = MaturityAssessmentSamples::next($old, 1, configuration: $configuration);
        $this->expectException(DomainException::class);
        $this->projection($f)->withApproval($next['assessment'], $next['submission'], $old['assessment']->review->publicId);
    }

    public function test_stale_previous_decision_is_rejected(): void
    {
        $f = MaturityAssessmentSamples::cycle();
        $next = MaturityAssessmentSamples::next($f['records']['orientation'], 1);
        $this->expectException(ReviewConflict::class);
        $this->projection($f)->withApproval($next['assessment'], $next['submission'], (string) Str::ulid());
    }

    public function test_a_later_increase_does_not_silently_erase_outstanding_revalidation(): void
    {
        $f = MaturityAssessmentSamples::cycle();
        $old = $f['records']['orientation'];
        $lower = MaturityAssessmentSamples::next($old, 0);
        $projection = $this->projection($f)->withApproval($lower['assessment'], $lower['submission'], $old['assessment']->review->publicId);
        $higher = MaturityAssessmentSamples::next($lower, 1, 'later increase');
        $projection = $projection->withApproval($higher['assessment'], $higher['submission'], $lower['assessment']->review->publicId);
        foreach (array_slice(MaturityStage::cases(), 1) as $stage) {
            $this->assertTrue($projection->requiresRevalidation($stage));
        }
    }

    public function test_unreliable_original_category_order_fails_closed_without_reordering_the_scale(): void
    {
        $f = MaturityAssessmentSamples::cycle(reverseScale: true);
        $next = MaturityAssessmentSamples::next($f['records']['orientation'], 0);
        $this->expectException(DomainException::class);
        $this->projection($f)->withApproval($next['assessment'], $next['submission'], $f['records']['orientation']['assessment']->review->publicId);
    }
}
