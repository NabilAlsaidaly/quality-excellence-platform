<?php

namespace Tests\Unit\Maturity;

use App\Domain\Maturity\ApprovedStageAssessment;
use App\Domain\Maturity\ExpectedStageEvidence;
use App\Domain\Maturity\MaturityStage;
use App\Domain\Maturity\RatingBand;
use App\Domain\Maturity\RatingScale;
use App\Domain\Maturity\StageConfigurationSnapshot;
use App\Domain\Maturity\StageEvidenceReference;
use App\Domain\Maturity\StageQuestion;
use App\Domain\Maturity\StageSubmission;
use App\Domain\Reviews\ReviewState;
use App\Domain\Reviews\ReviewWorkflow;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

class StageSubmissionTest extends TestCase
{
    private function id(): string
    {
        return (string) Str::ulid();
    }

    private function fixture(): array
    {
        $org = $this->id();
        $approach = $this->id();
        $question = $this->id();
        $first = $this->id();
        $second = $this->id();
        $author = $this->id();
        $reviewer = $this->id();
        $target = $this->id();
        $configuration = new StageConfigurationSnapshot($this->id(), $org, MaturityStage::Orientation,
            [new StageQuestion($question, 'كيف ترتبط المنهجية بالأهداف؟', 'أجب واربط الشواهد المطلوبة.', true,
                [new ExpectedStageEvidence($first, 'الشاهد الأول', true), new ExpectedStageEvidence($second, 'الشاهد الثاني', true)])],
            new RatingScale([new RatingBand('بداية', 'وصف البداية', 0, 30), new RatingBand('تقدم', 'وصف التقدم', 31, 70), new RatingBand('استقرار', 'وصف الاستقرار', 71, 100)]));
        $answers = [$question => 'إجابة موثقة'];
        $references = [new StageEvidenceReference($org, $question, $first, $this->id(), $this->id()),
            new StageEvidenceReference($org, $question, $second, $this->id(), $this->id())];

        return compact('org', 'approach', 'question', 'first', 'second', 'author', 'reviewer', 'target', 'configuration', 'answers', 'references');
    }

    private function submission(array $f, ?array $answers = null, ?array $references = null): StageSubmission
    {
        return new StageSubmission($this->id(), $f['org'], $f['approach'], $f['target'], $f['configuration'],
            $answers ?? $f['answers'], $references ?? $f['references'], [$f['author']]);
    }

    public function test_complete_snapshot_has_two_required_evidence_refs_and_no_preselected_reviewer_rating(): void
    {
        $f = $this->fixture();
        $submission = $this->submission($f);
        $submission->assertComplete();
        $snapshot = $submission->snapshot();
        $this->assertCount(2, $snapshot['evidence']);
        $this->assertCount(3, $snapshot['configuration']['scale']);
        $this->assertArrayNotHasKey('selected_band_index', $snapshot);
        $this->assertArrayNotHasKey('score', $snapshot);
        $this->assertSame(MaturityStage::Orientation, $submission->stage());
        $target = $submission->reviewTarget();
        $this->assertSame($submission->fingerprint(), $target->fingerprint);
        $this->assertSame($submission->publicId, $target->revisionPublicId);
        $this->assertSame($f['target'], $target->targetPublicId);
    }

    public function test_incomplete_draft_can_be_frozen_but_cannot_be_approved_as_complete(): void
    {
        $f = $this->fixture();
        $draft = $this->submission($f, [], []);
        $this->assertSame([], $draft->snapshot()['answers']);
        $this->expectException(DomainException::class);
        $draft->assertComplete();
    }

    public function test_a_required_expected_evidence_cannot_be_satisfied_by_another_expectation(): void
    {
        $f = $this->fixture();
        $submission = $this->submission($f, references: [$f['references'][0]]);
        $this->expectException(DomainException::class);
        $submission->assertComplete();
    }

    public function test_blank_required_answer_cannot_be_replaced_by_an_evidence_count(): void
    {
        $f = $this->fixture();
        $submission = $this->submission($f, [$f['question'] => " \u{00a0}\n"]);
        $this->expectException(DomainException::class);
        $submission->assertComplete();
    }

    public function test_payload_order_new_revision_id_and_id_casing_do_not_manufacture_a_content_change(): void
    {
        $f = $this->fixture();
        $first = $this->submission($f);
        $next = $this->submission($f, [strtolower($f['question']) => $f['answers'][$f['question']]], array_reverse($f['references']));
        $this->assertNotSame($first->publicId, $next->publicId);
        $this->assertSame($first->fingerprint(), $next->fingerprint());
        $opened = ReviewWorkflow::saved(null, $first->reviewTarget(), $this->id(), new DateTimeImmutable('2026-11-10T13:00:00Z'));
        $same = ReviewWorkflow::saved($opened->current, $next->reviewTarget(), $this->id(), new DateTimeImmutable('2026-11-10T13:00:01Z'));
        $this->assertFalse($same->opened);
        $this->assertSame([], $same->signals);
    }

    public function test_changed_answer_opens_a_new_pending_review_without_rewriting_prior_correction(): void
    {
        $f = $this->fixture();
        $first = $this->submission($f);
        $opened = ReviewWorkflow::saved(null, $first->reviewTarget(), $this->id(), new DateTimeImmutable('2026-11-10T13:00:00Z'));
        $correction = ReviewWorkflow::decide($opened->current, $f['reviewer'], $first->publicId, ReviewState::ChangesRequested,
            'وضّح الإجابة.', new DateTimeImmutable('2026-11-10T13:01:00Z'));
        $next = $this->submission($f, [$f['question'] => 'إجابة مصححة']);
        $changed = ReviewWorkflow::saved($correction->current, $next->reviewTarget(), $this->id(), new DateTimeImmutable('2026-11-10T13:02:00Z'));
        $this->assertSame(ReviewState::ChangesRequested, $correction->current->state);
        $this->assertSame('وضّح الإجابة.', $correction->current->reason);
        $this->assertSame(ReviewState::Pending, $changed->current->state);
        $this->assertSame($next->publicId, $changed->current->target->revisionPublicId);
    }

    public function test_foreign_tenant_evidence_reference_is_rejected_before_snapshot_creation(): void
    {
        $f = $this->fixture();
        $foreign = new StageEvidenceReference($this->id(), $f['question'], $f['first'], $this->id(), $this->id());
        $this->expectException(DomainException::class);
        $this->submission($f, references: [$foreign]);
    }

    public function test_foreign_configuration_cannot_be_bound_to_a_submission(): void
    {
        $f = $this->fixture();
        $f['org'] = $this->id();
        $this->expectException(DomainException::class);
        $this->submission($f);
    }

    public function test_unknown_question_or_expected_evidence_cannot_enter_a_review_payload(): void
    {
        $f = $this->fixture();
        foreach ([fn () => $this->submission($f, [$this->id() => 'غير معروف']),
            fn () => $this->submission($f, references: [new StageEvidenceReference($f['org'], $f['question'], $this->id(), $this->id(), $this->id())])] as $operation) {
            try {
                $operation();
                $this->fail('Unconfigured content was accepted.');
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_duplicate_evidence_binding_is_not_counted_as_two_expected_items(): void
    {
        $f = $this->fixture();
        $this->expectException(DomainException::class);
        $this->submission($f, references: [$f['references'][0], $f['references'][0]]);
    }

    public function test_historical_answers_question_text_scale_and_refs_are_returned_as_copies(): void
    {
        $f = $this->fixture();
        $submission = $this->submission($f);
        $before = $submission->snapshot();
        $copy = $before;
        $copy['answers'][0]['answer'] = 'تغيير';
        $copy['configuration']['questions'][0]['text'] = 'سؤال بديل';
        $copy['configuration']['scale'][0]['name'] = 'سلم بديل';
        $copy['evidence'][0]['version_public_id'] = $this->id();
        $this->assertSame($before, $submission->snapshot());
        $this->assertNotSame($copy, $submission->snapshot());
    }

    public function test_supplied_reviewer_identity_cannot_approve_their_own_answers(): void
    {
        $f = $this->fixture();
        $submission = $this->submission($f);
        $opened = ReviewWorkflow::saved(null, $submission->reviewTarget(), $this->id(), new DateTimeImmutable('2026-11-10T13:00:00Z'));
        $this->expectException(DomainException::class);
        ReviewWorkflow::decide($opened->current, $f['author'], $submission->publicId, ReviewState::Approved, null,
            new DateTimeImmutable('2026-11-10T13:01:00Z'));
    }

    public function test_legacy_assessment_fingerprint_cannot_silently_consume_an_unrated_submission(): void
    {
        $f = $this->fixture();
        $submission = $this->submission($f);
        $opened = ReviewWorkflow::saved(null, $submission->reviewTarget(), $this->id(), new DateTimeImmutable('2026-11-10T13:00:00Z'));
        $approved = ReviewWorkflow::decide($opened->current, $f['reviewer'], $submission->publicId, ReviewState::Approved,
            'اختيار بشري لاحق', new DateTimeImmutable('2026-11-10T13:01:00Z'));
        // The existing value requires a fingerprint containing a preselected
        // rating. Fixing the persistent bridge needs the separate exact amendment.
        $this->expectException(DomainException::class);
        new ApprovedStageAssessment($f['approach'], MaturityStage::Orientation, $f['configuration']->scale, 0,
            $submission->snapshot(), $approved->current);
    }

    public function test_optional_inputs_are_not_promoted_to_mandatory_business_rules(): void
    {
        $f = $this->fixture();
        $f['configuration'] = new StageConfigurationSnapshot($this->id(), $f['org'], MaturityStage::Orientation,
            [new StageQuestion($f['question'], 'سؤال اختياري', '', false, [new ExpectedStageEvidence($f['first'], 'شاهد اختياري', false)])],
            $f['configuration']->scale);
        $submission = $this->submission($f, [], []);
        $submission->assertComplete();
        $this->assertSame([], $submission->snapshot()['answers']);
        $this->assertSame([], $submission->snapshot()['evidence']);
    }

    public function test_inactive_questions_cannot_accept_new_answers_or_satisfy_an_empty_stage(): void
    {
        $f = $this->fixture();
        $f['configuration'] = new StageConfigurationSnapshot($this->id(), $f['org'], MaturityStage::Orientation,
            [new StageQuestion($f['question'], 'سؤال معطل', '', false, [new ExpectedStageEvidence($f['first'], 'شاهد', false)], false)],
            $f['configuration']->scale);
        try {
            $this->submission($f, [$f['question'] => 'إجابة مخفية'], []);
            $this->fail('Inactive question accepted input.');
        } catch (DomainException) {
            $this->assertFalse($f['configuration']->questions[0]->active);
        }
        $this->expectException(DomainException::class);
        $this->submission($f, [], [])->assertComplete();
    }

    public function test_one_real_version_can_support_distinct_configured_expectations_without_copying_it(): void
    {
        $f = $this->fixture();
        $reference = $f['references'][0];
        $second = new StageEvidenceReference($f['org'], $f['question'], $f['second'], $reference->documentPublicId, $reference->versionPublicId);
        $submission = $this->submission($f, references: [$reference, $second]);
        $submission->assertComplete();
        $this->assertCount(2, $submission->snapshot()['evidence']);
        $this->assertCount(1, array_unique(array_column($submission->snapshot()['evidence'], 'version_public_id')));
    }

    public function test_invalid_utf8_and_case_duplicated_question_keys_are_rejected(): void
    {
        $f = $this->fixture();
        foreach ([[$f['question'] => "\xff"], [$f['question'] => 'أ', strtolower($f['question']) => 'ب']] as $answers) {
            try {
                $this->submission($f, $answers);
                $this->fail('Invalid or duplicated input accepted.');
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }
    }
}
