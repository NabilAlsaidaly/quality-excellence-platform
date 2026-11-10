<?php

namespace App\Actions\Reviews;

use App\Actions\Approaches\CreateApproach;
use App\Authorization\DocumentWorkspaceAccess;
use App\Authorization\ReviewMaturityAccess;
use App\Authorization\RoleManagement;
use App\Domain\Maturity\MaturityStage;
use App\Models\Approach;
use App\Models\OrganizationMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Named, finite technical fixture only. No endpoint or operational bootstrap exists. */
final class ManageReviewMaturityAssignments
{
    public function bootstrap(): void
    {
        $this->issuer(function (User $issuer, Approach $approach, array $proof): void {
            if (DB::table('maturity_workflow_stages')->where('organization_id', $proof['organization_id'])->where('fixture_id', $proof['fixture_id'])->exists()) {
                throw new LogicException('Existing workflow fixture must not be adopted or bootstrapped again.');
            }
            $roles = app(RoleManagement::class);
            $role = $roles->create('Workflow TEST_ONLY '.$proof['fixture_id']);
            $roles->syncPermissions($role, [ReviewMaturityAccess::CAPABILITY]);
            foreach ([$proof['author_id'], $proof['reviewer_id']] as $id) {
                $member = $this->member($id, $proof['organization_id']);
                $roles->assign($role, $member);
            }
            foreach (MaturityStage::cases() as $index => $stage) {
                DB::table('maturity_workflow_stages')->insert(ReviewMaturityWorkspace::attributes($approach)
                    + ['stage' => $stage->value, 'stage_ordinal' => $index + 1]);
            }
            foreach ([$proof['author_id'], $proof['reviewer_id']] as $id) {
                $this->grant($issuer, $approach, $id, 'read_workflow');
            }
            $this->grant($issuer, $approach, $proof['author_id'], 'edit_content');
            $this->grant($issuer, $approach, $proof['reviewer_id'], 'review_content');
            foreach (DB::table('maturity_workflow_stages')->where('organization_id', $proof['organization_id'])->where('fixture_id', $proof['fixture_id'])->orderBy('id')->get() as $stage) {
                $this->grant($issuer, $approach, $proof['author_id'], 'answer_stage', $stage->id);
                $this->grant($issuer, $approach, $proof['reviewer_id'], 'configure_stage', $stage->id);
                $this->grant($issuer, $approach, $proof['reviewer_id'], 'review_stage', $stage->id);
            }
        });
    }

    public function revoke(string $publicId): void
    {
        $this->issuer(function (User $issuer, Approach $approach) use ($publicId): void {
            $row = Str::isUlid($publicId) ? app(ReviewMaturityAccess::class)->assignments()->where('public_id', $publicId)->lockForUpdate()->first() : null;
            if ($row === null) {
                throw new AuthorizationException;
            }
            if ($row->revoked_at !== null) {
                return;
            }
            DB::table('review_maturity_assignments')->where('id', $row->id)->update(['revoked_at' => ReviewMaturityAccess::timestamp(), 'revoked_by' => $issuer->id]);
            app(RecordReviewMaturityAudit::class)->execute($issuer, $approach, 'revoked', 'revoked:'.$row->public_id,
                $row->stage_id, assignment: $row, operation: $row->operation, subjectId: $row->grantee_id);
        });
    }

    private function issuer(Closure $callback): mixed
    {
        $proof = ReviewMaturityAccess::context() ?? throw new AuthorizationException;

        return app(DocumentWorkspaceAccess::class)->withSession(Auth::guard('institution')->user(), fn (User $actor) => app(CreateApproach::class)->withinContext($actor, function (int $organization, User $issuer) use ($callback, $proof): mixed {
            if ((int) $issuer->id !== $proof['issuer_id'] || ! hash_equals($issuer->getAuthPassword(), (string) request()->session()->get('institution.password_hash'))) {
                throw new AuthorizationException;
            }
            $approach = Approach::where('organization_id', $organization)->whereKey($proof['approach_id'])->where('created_by', $proof['quality_id'])->lockForUpdate()->firstOrFail();
            DB::table('maturity_workflow_stages')->where('organization_id', $organization)->where('fixture_id', $proof['fixture_id'])->orderBy('id')->lockForUpdate()->get();
            app(ReviewMaturityAccess::class)->assignments()->orderBy('id')->lockForUpdate()->get();

            return $callback($issuer, $approach, $proof);
        }));
    }

    private function member(int $id, int $organization): User
    {
        $user = User::where('organization_id', $organization)->whereKey($id)->where('principal_type', 'institution')->lockForUpdate()->first();
        if ($user === null || OrganizationMembership::where('organization_id', $organization)->where('user_id', $id)->lockForUpdate()->first() === null) {
            throw new AuthorizationException;
        }

        return $user;
    }

    private function grant(User $issuer, Approach $approach, int $recipient, string $operation, ?int $stageId = null): void
    {
        $proof = ReviewMaturityAccess::context() ?? throw new AuthorizationException;
        $this->member($recipient, $proof['organization_id']);
        $start = CarbonImmutable::now('UTC')->startOfSecond();
        $id = DB::table('review_maturity_assignments')->insertGetId(ReviewMaturityWorkspace::attributes($approach) + [
            'grantee_id' => $recipient, 'issuer_id' => $issuer->id, 'stage_id' => $stageId, 'operation' => $operation,
            'starts_at' => $start->format('Y-m-d H:i:s'), 'expires_at' => $start->addHours(24)->format('Y-m-d H:i:s')]);
        $row = DB::table('review_maturity_assignments')->where('id', $id)->first();
        app(RecordReviewMaturityAudit::class)->execute($issuer, $approach, 'granted', 'granted:'.$row->public_id, $stageId,
            assignment: $row, operation: $operation, subjectId: $recipient);
    }

    /** Verified post-rollback fact; never called for visibility checks or foreign targets. */
    public function recordDenied(string $operation, string $publicId, ?int $stageId): void
    {
        if (! in_array($operation, ReviewMaturityAccess::OPERATIONS, true)) {
            return;
        }
        try {
            app(DocumentWorkspaceAccess::class)->withSession(Auth::guard('institution')->user(), fn (User $actor) => app(CreateApproach::class)->withinContext($actor, function (int $organization, User $current) use ($operation, $publicId, $stageId): void {
                $proof = ReviewMaturityAccess::context() ?? throw new AuthorizationException;
                $approach = Approach::where('organization_id', $organization)->whereKey($proof['approach_id'])->where('public_id', $publicId)->lockForUpdate()->first();
                if ($approach === null || ($stageId !== null && DB::table('maturity_workflow_stages')->where('organization_id', $organization)
                    ->where('fixture_id', $proof['fixture_id'])->where('approach_id', $approach->id)->where('id', $stageId)->lockForUpdate()->first() === null)) {
                    return;
                }
                $row = app(ReviewMaturityAccess::class)->assignments()->where('grantee_id', $current->id)->where('operation', $operation)
                    ->where('stage_id', $stageId)->orderBy('id')->lockForUpdate()->first();
                if ($row !== null && $row->revoked_at === null && $row->expires_at <= ReviewMaturityAccess::timestamp()
                    && ! DB::table('review_maturity_audit')->where('organization_id', $organization)->where('fixture_id', $proof['fixture_id'])
                        ->where('event_key', 'expired:'.$row->public_id)->lockForUpdate()->first()) {
                    app(RecordReviewMaturityAudit::class)->execute($current, $approach, 'expiration_observed', 'expired:'.$row->public_id,
                        $stageId, assignment: $row, operation: $operation, subjectId: $current->id);
                }
                app(RecordReviewMaturityAudit::class)->execute($current, $approach, 'access_denied', (string) Str::ulid(),
                    $stageId, assignment: $row, operation: $operation, subjectId: $current->id);
            }));
        } catch (AuthorizationException) {
            // No attribution to a session/current identity that cannot be verified.
        }
    }
}
