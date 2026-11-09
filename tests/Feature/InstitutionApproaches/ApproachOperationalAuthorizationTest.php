<?php

namespace Tests\Feature\InstitutionApproaches;

use App\Actions\Approaches\CreateApproach;
use App\Actions\Approaches\ManageApproachAssignments;
use App\Authorization\ApproachAssignmentAccess;
use App\Authorization\TenantContext;
use App\Filament\Institution\Approaches\ApproachAccess;
use App\Models\Approach;
use App\Models\ApproachAssignment;
use App\Models\ApproachAssignmentChange;
use App\Models\ApproachContentRevision;
use App\Models\OrganizationMembership;
use App\Policies\ApproachPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\ApproachAssignmentFixtures;
use Tests\TestCase;

class ApproachOperationalAuthorizationTest extends TestCase
{
    use ApproachAssignmentFixtures, RefreshDatabase;

    private function denied(callable $operation): void
    {
        try {
            $operation();
            $this->fail('An unassigned operation must be rejected.');
        } catch (AuthorizationException|ModelNotFoundException) {
            $this->assertTrue(true);
        }
    }

    public function test_actual_policy_creates_explicit_assignments_and_contributor_persists_drafts_and_history(): void
    {
        $f = $this->assignmentFixture();
        $this->inAssignmentFixture($f, function () use ($f): void {
            $this->assertSame(ApproachPolicy::class, get_class(app(ApproachPolicy::class)));
            $id = $this->createAssignedApproach($f);
            $row = Approach::where('public_id', $id)->firstOrFail();
            $this->assertSame($f['unit']->id, $row->owning_unit_id);
            $this->assertSame(7, DB::table('approach_assignments')->where('approach_id', $row->id)->count());
            $this->denied(fn () => app(ApproachAccess::class)->saveDraft($id, 'Quality has no draft grant', 'none'));
            $this->loginAssignmentActor($f['contributor'], $f['organization']);
            $access = app(ApproachAccess::class);
            $this->assertSame($id, $access->show($id)['public_id']);
            $access->saveDraft($id, 'المسودة الأولى', 'none');
            $first = $access->history($id, 'draft')->first();
            $access->saveDraft($id, 'المسودة الثانية', $first['public_id']);
            $this->assertSame('المسودة الثانية', app(ApproachAccess::class)->history($id, 'draft')->first()['body']);
            $this->assertSame(2, app(ApproachAccess::class)->history($id, 'draft')->total());
            $this->denied(fn () => $access->update($id, ['name' => 'forbidden'], $access->show($id)['edit_token']));
            $this->assertFalse(app(ApproachPolicy::class)->approve($f['contributor'], $row));
            $this->assertSame(0, $access->history($id, 'audit')->total());
        });
        $this->assertSame([], config('authorization.capabilities'));
        $this->assertNull(ApproachAssignmentAccess::context());
        $this->assertFalse(app(ApproachAccess::class)->can('list'));
    }

    public function test_resource_filter_applies_before_search_count_and_pagination_without_creator_or_unit_inheritance(): void
    {
        $f = $this->assignmentFixture();
        $this->inAssignmentFixture($f, function () use ($f): void {
            $id = $this->createAssignedApproach($f, 'Visible');
            $hidden = app(TenantContext::class)->run($f['quality'], $f['organization']->id,
                fn () => app(CreateApproach::class)->execute($f['quality'], $this->assignmentData($f, 'Hidden')));
            $access = app(ApproachAccess::class);
            $this->assertSame(1, $access->listing(perPage: 1)->total());
            $this->assertSame($id, $access->listing(perPage: 1)->first()['public_id']);
            $this->assertSame(0, $access->listing(search: 'Hidden')->total());
            $this->assertCount(0, $access->listing(page: 2, perPage: 1)->items());
            $this->denied(fn () => $access->show($hidden->public_id));
            $this->loginAssignmentActor($f['unassigned'], $f['organization']);
            $this->assertFalse($access->canEnter($f['unassigned']));
            $this->denied(fn () => $access->listing());
        });
    }

    public static function initialAuditFailures(): array
    {
        return [[1], [4], [7]];
    }

    #[DataProvider('initialAuditFailures')]
    public function test_initial_grant_audit_failure_rolls_back_resource_all_recipients_and_success_history(int $failAt): void
    {
        $f = $this->assignmentFixture();
        $before = DB::table('approach_assignment_changes')->count();
        $position = 0;
        ApproachAssignmentChange::creating(function (ApproachAssignmentChange $event) use (&$position, $failAt): void {
            if ($event->approach_id !== null && ++$position === $failAt) {
                throw new RuntimeException('Injected initial grant audit failure.');
            }
        });
        $this->inAssignmentFixture($f, function () use ($f): void {
            try {
                $this->createAssignedApproach($f);
                $this->fail('Injected audit failure must roll back creation.');
            } catch (RuntimeException $error) {
                $this->assertSame('Injected initial grant audit failure.', $error->getMessage());
            }
        });
        $this->assertDatabaseCount('approaches', 0);
        $this->assertSame(0, DB::table('approach_assignments')->whereNotNull('approach_id')->count());
        $this->assertSame($before, DB::table('approach_assignment_changes')->count());
    }

    public function test_revoked_contributor_is_denied_in_existing_session_without_destroying_history(): void
    {
        $f = $this->assignmentFixture();
        $this->inAssignmentFixture($f, function () use ($f): void {
            $id = $this->createAssignedApproach($f);
            $row = Approach::where('public_id', $id)->firstOrFail();
            $this->loginAssignmentActor($f['contributor'], $f['organization']);
            app(ApproachAccess::class)->saveDraft($id, 'preserve', 'none');
            app(TenantContext::class)->run($f['quality'], $f['organization']->id,
                fn () => app(ManageApproachAssignments::class)->revokeContributor($f['quality'], $row, $f['contributor']));
            $this->denied(fn () => app(ApproachAccess::class)->saveDraft($id, 'forbidden', 'none'));
            $this->assertFalse(app(ApproachAccess::class)->canEnter($f['contributor']));
            $this->assertDatabaseCount('approach_content_revisions', 1);
            $this->assertSame(4, DB::table('approach_assignment_changes')->where('event', 'revoked')->count());
            $this->assertGreaterThan(0, DB::table('approach_assignment_changes')->where('event', 'access_denied')->count());
        });
    }

    public function test_quality_revocation_expiry_and_missing_membership_fail_closed_without_approval_or_delegation(): void
    {
        $f = $this->assignmentFixture();
        $this->inAssignmentFixture($f, function () use ($f): void {
            $id = $this->createAssignedApproach($f);
            app(TenantContext::class)->run($f['issuer'], $f['organization']->id,
                fn () => app(ManageApproachAssignments::class)->revokeQuality($f['issuer']));
            $this->denied(fn () => app(ApproachAccess::class)->show($id));
            $this->loginAssignmentActor($f['contributor'], $f['organization']);
            $this->travel(24)->hours();
            $this->denied(fn () => app(ApproachAccess::class)->saveDraft($id, 'expired', 'none'));
            $this->assertGreaterThan(0, DB::table('approach_assignment_changes')->where('event', 'expiration_observed')->count());
            $this->travelBack();
            DB::table('model_has_roles')->where('model_id', $f['contributor']->id)->delete();
            OrganizationMembership::where('user_id', $f['contributor']->id)->delete();
            $this->assertFalse(app(ApproachAccess::class)->can('view', $id));
        });
    }

    public function test_fixture_context_is_request_bound_exception_safe_and_cannot_be_enabled_by_headers_sessions_or_config(): void
    {
        $f = $this->assignmentFixture();
        $original = request();
        $this->inAssignmentFixture($f, function () use ($f, $original): void {
            $this->createAssignedApproach($f);
            $other = Request::create('/institution/workspace', server: ['HTTP_X_FIXTURE' => 'J03_LOCAL_APPROACH_001']);
            app()->instance('request', $other);
            try {
                $this->assertNull(ApproachAssignmentAccess::context());
                $this->assertFalse(app(ApproachAccess::class)->can('list'));
            } finally {
                app()->instance('request', $original);
            }
        });
        app('session.store')->put('authorization.test_fixture', $f['proof']);
        config(['authorization.test_fixture' => $f['proof']]);
        $this->assertNull(ApproachAssignmentAccess::context());
        $this->assertFalse(app(ApproachAccess::class)->can('list'));
        try {
            $this->inAssignmentFixture($f, fn () => throw new RuntimeException('scope exit'));
        } catch (RuntimeException $error) {
            $this->assertSame('scope exit', $error->getMessage());
        }
        $this->assertNull(ApproachAssignmentAccess::context());
    }

    public function test_stale_metadata_and_draft_tokens_preserve_exact_existing_history(): void
    {
        $f = $this->assignmentFixture();
        $this->inAssignmentFixture($f, function () use ($f): void {
            $id = $this->createAssignedApproach($f);
            $access = app(ApproachAccess::class);
            $token = $access->show($id)['edit_token'];
            $access->update($id, ['name' => 'updated'], $token);
            try {
                $access->update($id, ['name' => 'stale'], $token);
                $this->fail('Stale metadata must fail.');
            } catch (ConflictHttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
            $this->loginAssignmentActor($f['contributor'], $f['organization']);
            $access->saveDraft($id, 'one', 'none');
            try {
                $access->saveDraft($id, 'stale', 'none');
                $this->fail('Stale draft must fail.');
            } catch (ConflictHttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
            $this->assertSame(1, $access->history($id, 'audit')->total());
            $this->assertSame(1, $access->history($id, 'draft')->total());
            $this->assertSame('updated', $access->show($id)['name']);
        });
    }

    public static function normalEnvironments(): array
    {
        return [['local'], ['staging'], ['production']];
    }

    #[DataProvider('normalEnvironments')]
    public function test_existing_roles_and_recognized_capability_cannot_activate_a_fixture_in_normal_environments(string $environment): void
    {
        $f = $this->assignmentFixture();
        $this->loginAssignmentActor($f['quality'], $f['organization']);
        app()->instance('env', $environment);
        config(['authorization.capabilities' => config('authorization.test_only_fixture_capabilities')]);
        try {
            $this->assertFalse(app(ApproachAccess::class)->can('list'));
            $this->denied(fn () => $this->inAssignmentFixture($f, fn () => $this->fail('Non-testing fixture scope opened.')));
        } finally {
            app()->instance('env', 'testing');
        }
    }

    public static function badProofs(): array
    {
        return [[['scenario' => 'other']], [['fixture_id' => 'invalid']], [['database' => 'quality_platform']],
            [['driver' => 'mysql']], [['issuer_id' => 0]]];
    }

    #[DataProvider('badProofs')]
    public function test_unverified_fixture_proof_is_rejected(array $override): void
    {
        $f = $this->assignmentFixture(false);
        $f['proof'] = array_replace($f['proof'], $override);
        $this->denied(fn () => $this->inAssignmentFixture($f, fn () => $this->fail('Unverified target accepted.')));
        $this->assertNull(ApproachAssignmentAccess::context());
    }

    public function test_foreign_resources_issuer_and_form_recipient_ids_cannot_grant_access(): void
    {
        $f = $this->assignmentFixture();
        $foreign = $this->assignmentFixture();
        $foreignId = $this->inAssignmentFixture($foreign, fn () => $this->createAssignedApproach($foreign));
        $this->inAssignmentFixture($f, function () use ($f, $foreignId): void {
            $this->loginAssignmentActor($f['quality'], $f['organization']);
            $this->denied(fn () => app(ApproachAccess::class)->show($foreignId));
            $this->denied(fn () => app(TenantContext::class)->run($f['contributor'], $f['organization']->id,
                fn () => app(ManageApproachAssignments::class)->revokeQuality($f['contributor'])));
            try {
                app(ApproachAccess::class)->create($this->assignmentData($f) + ['initial_contributor_id' => $f['unassigned']->id]);
                $this->fail('A form recipient must not override the server creation plan.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('approach', $error->errors());
            }
            $this->loginAssignmentActor($f['issuer'], $f['organization']);
            $this->assertFalse(app(ApproachAccess::class)->canEnter($f['issuer']));
        });
    }

    public function test_assignment_models_preserve_lifecycle_and_audit_history(): void
    {
        $f = $this->assignmentFixture();
        $row = ApproachAssignment::where('fixture_id', $f['proof']['fixture_id'])->where('operation', 'quality')->firstOrFail();
        try {
            $row->forceFill(['operation' => 'view'])->save();
            $this->fail('Generic model mutation accepted.');
        } catch (LogicException) {
            $this->assertSame('quality', $row->fresh()->operation);
        }
        $history = ApproachAssignmentChange::where('fixture_id', $f['proof']['fixture_id'])->firstOrFail();
        try {
            $history->delete();
            $this->fail('Historical event was deleted.');
        } catch (LogicException) {
            $this->assertTrue($history->fresh()->exists);
        }
    }

    public function test_recipient_membership_lost_after_plan_issuance_rolls_back_entire_creation(): void
    {
        $f = $this->assignmentFixture();
        DB::table('model_has_roles')->where('model_id', $f['contributor']->id)->delete();
        OrganizationMembership::where('user_id', $f['contributor']->id)->delete();
        $this->inAssignmentFixture($f, function () use ($f): void {
            $this->denied(fn () => $this->createAssignedApproach($f));
            $this->assertDatabaseCount('approaches', 0);
            $this->assertSame(0, DB::table('approach_assignments')->whereNotNull('approach_id')->count());
            $this->assertSame(0, DB::table('approach_assignment_changes')->whereNotNull('approach_id')->count());
        });
    }

    public function test_expiry_at_final_check_rolls_back_inflight_draft_and_records_actual_observation(): void
    {
        $f = $this->assignmentFixture();
        $this->inAssignmentFixture($f, function () use ($f): void {
            $id = $this->createAssignedApproach($f);
            $this->loginAssignmentActor($f['contributor'], $f['organization']);
            ApproachContentRevision::created(fn () => $this->travel(24)->hours());
            try {
                $this->denied(fn () => app(ApproachAccess::class)->saveDraft($id, 'must roll back', 'none'));
                $this->assertDatabaseCount('approach_content_revisions', 0);
                $this->assertSame(1, DB::table('approach_assignment_changes')->where('event', 'expiration_observed')->count());
            } finally {
                $this->travelBack();
            }
        });
    }
}
