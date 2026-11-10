<?php

namespace Tests\Feature\Notifications;

use App\Actions\Notifications\ReviewMaturityNotifications;
use App\Actions\Reviews\SaveReviewableApproach;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ReviewMaturityFixtures;
use Tests\TestCase;

class ReviewMaturityNotificationsTest extends TestCase
{
    use DatabaseMigrations, ReviewMaturityFixtures;

    protected function tearDown(): void
    {
        $this->cleanupReviewMaturity();
        parent::tearDown();
    }

    public function test_actual_delivery_recipient_unread_read_unread_and_wrong_actor_denial(): void
    {
        $f = $this->reviewMaturityFixture();
        $this->asWorkflow($f, 'author', fn () => app(SaveReviewableApproach::class)->execute($f['approach']->public_id, 'محتوى خاص لا يوضع في الإشعار', 'none', (string) Str::ulid()));
        $notice = DB::table('review_maturity_notifications')->first();
        $this->assertSame($f['reviewer']->id, $notice->recipient_id);
        $this->assertNull($notice->read_at);
        $this->assertStringNotContainsString('محتوى خاص', $notice->reason);
        $this->asWorkflow($f, 'reviewer', fn () => app(ReviewMaturityNotifications::class)->mark($f['approach']->public_id, $notice->public_id, true));
        $this->assertNotNull(DB::table('review_maturity_notifications')->value('read_at'));
        $this->asWorkflow($f, 'reviewer', fn () => app(ReviewMaturityNotifications::class)->mark($f['approach']->public_id, $notice->public_id, false));
        $this->assertNull(DB::table('review_maturity_notifications')->value('read_at'));
        $this->assertSame(0, $this->asWorkflow($f, 'author', fn () => app(ReviewMaturityNotifications::class)->inbox($f['approach']->public_id)->total()));
        $this->expectException(AuthorizationException::class);
        $this->asWorkflow($f, 'author', fn () => app(ReviewMaturityNotifications::class)->mark($f['approach']->public_id, $notice->public_id, true));
    }
}
