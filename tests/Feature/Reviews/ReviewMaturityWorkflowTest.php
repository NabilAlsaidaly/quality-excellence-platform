<?php

namespace Tests\Feature\Reviews;

use App\Actions\Reviews\DecideReview;
use App\Actions\Reviews\ManageReviewMaturityAssignments;
use App\Actions\Reviews\SaveReviewableApproach;
use App\Models\ReviewMaturityReview;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\ReviewMaturityFixtures;
use Tests\TestCase;

class ReviewMaturityWorkflowTest extends TestCase
{
    use DatabaseMigrations, ReviewMaturityFixtures;

    protected function tearDown(): void
    {
        $this->cleanupReviewMaturity();
        parent::tearDown();
    }

    public function test_real_save_correction_independent_approval_retry_and_history(): void
    {
        $f = $this->reviewMaturityFixture();
        $key = (string) Str::ulid();
        $first = $this->asWorkflow($f, 'author', fn () => app(SaveReviewableApproach::class)->execute($f['approach']->public_id, 'محتوى أول', 'none', $key));
        $this->assertSame('pending', $first->state);
        $replay = $this->asWorkflow($f, 'author', fn () => app(SaveReviewableApproach::class)->execute($f['approach']->public_id, 'محتوى أول', 'none', $key));
        $this->assertSame($first->id, $replay->id);
        $this->assertSame(1, DB::table('approach_content_revisions')->count());
        $this->assertSame(1, DB::table('review_maturity_notifications')->count());
        $this->asWorkflow($f, 'reviewer', fn () => app(DecideReview::class)->execute($first->public_id, $first->fingerprint, 'changes_requested', 'أضف تفاصيل التطبيق.', (string) Str::ulid()));
        $revision = DB::table('approach_content_revisions')->value('public_id');
        $second = $this->asWorkflow($f, 'author', fn () => app(SaveReviewableApproach::class)->execute($f['approach']->public_id, 'محتوى مصحح مع تفاصيل التطبيق', $revision, (string) Str::ulid()));
        $this->asWorkflow($f, 'reviewer', fn () => app(DecideReview::class)->execute($second->public_id, $second->fingerprint, 'approved', 'تمت المراجعة المستقلة.', (string) Str::ulid()));
        $this->assertSame('changes_requested', ReviewMaturityReview::findOrFail($first->id)->state);
        $this->assertSame('approved', ReviewMaturityReview::findOrFail($second->id)->state);
        $this->assertSame(2, DB::table('approach_content_revisions')->count());
        $this->assertSame(4, DB::table('review_maturity_notifications')->count());
    }

    public function test_policy_fails_closed_outside_the_exact_fixture(): void
    {
        $f = $this->reviewMaturityFixture();
        $this->loginWorkflowActor($f, 'author');
        $this->expectException(AuthorizationException::class);
        app(SaveReviewableApproach::class)->execute($f['approach']->public_id, 'محاولة غير مخولة', 'none', (string) Str::ulid());
    }

    public function test_noop_retry_payload_conflict_supersession_and_stale_decision_preserve_history(): void
    {
        $f = $this->reviewMaturityFixture();
        $key = (string) Str::ulid();
        $first = $this->asWorkflow($f, 'author', fn () => app(SaveReviewableApproach::class)->execute($f['approach']->public_id, 'النص الأول', 'none', $key));
        $token = DB::table('approach_content_revisions')->value('public_id');
        $this->assertNull($this->asWorkflow($f, 'author', fn () => app(SaveReviewableApproach::class)->execute($f['approach']->public_id, 'النص الأول', $token, (string) Str::ulid())));
        $this->assertSame(1, DB::table('review_maturity_reviews')->count());
        try {
            $this->asWorkflow($f, 'author', fn () => app(SaveReviewableApproach::class)->execute($f['approach']->public_id, 'بيانات مختلفة', $token, $key));
            $this->fail('Request key accepted a different payload.');
        } catch (ConflictHttpException) {
            $this->assertSame(1, DB::table('approach_content_revisions')->count());
        }
        $second = $this->asWorkflow($f, 'author', fn () => app(SaveReviewableApproach::class)->execute($f['approach']->public_id, 'النص الأحدث', $token, (string) Str::ulid()));
        $this->assertSame('superseded', $first->fresh()->state);
        $this->assertSame($first->id, $second->supersedes_id);
        try {
            $this->asWorkflow($f, 'reviewer', fn () => app(DecideReview::class)->execute($first->public_id, $first->fingerprint, 'approved', 'قرار قديم', (string) Str::ulid()));
            $this->fail('Stale review approved.');
        } catch (ConflictHttpException) {
            $this->assertSame('pending', $second->fresh()->state);
        }
    }

    public function test_notification_insert_failure_rolls_back_content_review_audit_and_delivery(): void
    {
        $f = $this->reviewMaturityFixture();
        $audits = DB::table('review_maturity_audit')->count();
        $fault = true;
        DB::listen(function ($query) use (&$fault): void {
            if ($fault && str_contains($query->sql, 'insert into "review_maturity_notifications"')) {
                $fault = false;
                throw new \RuntimeException('Injected delivery failure.');
            }
        });
        try {
            $this->asWorkflow($f, 'author', fn () => app(SaveReviewableApproach::class)->execute($f['approach']->public_id, 'لا يجب أن يُحفظ', 'none', (string) Str::ulid()));
            $this->fail('Injected notification failure was not reached.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Injected delivery failure.', $error->getMessage());
        }
        $this->assertSame(0, DB::table('approach_content_revisions')->count());
        $this->assertSame(0, DB::table('review_maturity_reviews')->count());
        $this->assertSame(0, DB::table('review_maturity_notifications')->count());
        $this->assertSame($audits, DB::table('review_maturity_audit')->count());
    }

    public function test_revocation_and_expiry_fail_closed_in_the_existing_session_and_are_audited(): void
    {
        $f = $this->reviewMaturityFixture();
        $grant = DB::table('review_maturity_assignments')->where('grantee_id', $f['author']->id)->where('operation', 'edit_content')->first();
        $this->asWorkflow($f, 'issuer', fn () => app(ManageReviewMaturityAssignments::class)->revoke($grant->public_id));
        $this->loginWorkflowActor($f, 'author');
        try {
            $this->inWorkflow($f, fn () => app(SaveReviewableApproach::class)->execute($f['approach']->public_id, 'مرفوض', 'none', (string) Str::ulid()));
            $this->fail('Revoked assignment remained usable.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('approach_content_revisions')->count());
        }
        $this->assertSame(1, DB::table('review_maturity_audit')->where('event', 'revoked')->count());
        $this->assertSame(1, DB::table('review_maturity_audit')->where('event', 'access_denied')->count());
        $f2 = $this->reviewMaturityFixture();
        $end = now('UTC')->subSecond();
        DB::table('review_maturity_assignments')->where('grantee_id', $f2['author']->id)->where('operation', 'edit_content')
            ->update(['starts_at' => $end->copy()->subDay()->format('Y-m-d H:i:s'), 'expires_at' => $end->format('Y-m-d H:i:s')]);
        try {
            $this->asWorkflow($f2, 'author', fn () => app(SaveReviewableApproach::class)->execute($f2['approach']->public_id, 'منتهي', 'none', (string) Str::ulid()));
            $this->fail('Expired assignment remained usable.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('approach_content_revisions')->count());
        }
        $this->assertSame(1, DB::table('review_maturity_audit')->where('event', 'expiration_observed')->count());
    }

    public function test_wall_clock_expiry_during_origin_transaction_rolls_back_every_write(): void
    {
        $f = $this->reviewMaturityFixture();
        $original = CarbonImmutable::now('UTC');
        $armed = true;
        DB::listen(function ($query) use (&$armed, $original): void {
            if ($armed && str_contains($query->sql, 'insert into "review_maturity_notifications"')) {
                $armed = false;
                CarbonImmutable::setTestNow($original->addHours(24)->addSecond());
            }
        });
        try {
            try {
                $this->asWorkflow($f, 'author', fn () => app(SaveReviewableApproach::class)->execute($f['approach']->public_id, 'must roll back after expiry', 'none', (string) Str::ulid()));
                $this->fail('Assignment expiry before commit was ignored.');
            } catch (AuthorizationException) {
                $this->assertFalse($armed);
            }
            $this->assertSame(0, DB::table('approach_content_revisions')->count());
            $this->assertSame(0, DB::table('review_maturity_reviews')->count());
            $this->assertSame(0, DB::table('review_maturity_notifications')->count());
            $this->assertSame(1, DB::table('review_maturity_audit')->where('event', 'expiration_observed')->count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
