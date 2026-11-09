<?php

namespace App\Actions\Approaches;

use App\Authorization\ApproachAssignmentAccess;
use App\Authorization\RoleManagement;
use App\Models\Approach;
use App\Models\ApproachAssignment;
use App\Models\ApproachAssignmentChange;
use App\Models\OrganizationMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** No public grant endpoint. Only the explicitly named local fixture has authority. */
final class ManageApproachAssignments
{
    public function __construct(private CreateApproach $boundary, private ApproachAssignmentAccess $access) {}

    public function bootstrap(User $issuer): void
    {
        $proof = ApproachAssignmentAccess::context() ?? throw new AuthorizationException;
        if ((int) $issuer->id !== $proof['issuer_id']) {
            throw new AuthorizationException;
        }
        $this->boundary->withinContext($issuer, function () use ($issuer, $proof): void {
            $quality = $this->member($proof['quality_id'], $proof['organization_id']);
            $contributor = $this->member($proof['contributor_id'], $proof['organization_id']);
            $roles = app(RoleManagement::class);
            $role = $roles->create('j03-test-'.$proof['fixture_id']);
            $roles->syncPermissions($role, ['approaches.access']);
            $roles->assign($role, $quality);
            $roles->assign($role, $contributor);
            foreach (['quality', 'create', 'list'] as $operation) {
                $this->grant($issuer, $quality, $operation, recipient: $operation === 'create' ? $contributor : null);
            }
            $this->grant($issuer, $contributor, 'list');
        });
    }

    public function initialRecipients(User $quality, Approach $resource): void
    {
        $proof = ApproachAssignmentAccess::context() ?? throw new AuthorizationException;
        if ((int) $quality->id !== $proof['quality_id'] || ! $resource->wasRecentlyCreated
            || (int) $resource->created_by !== (int) $quality->id || (int) $resource->organization_id !== $proof['organization_id']
            || ! $this->access->allows($quality, 'create') || DB::transactionLevel() < 1) {
            throw new AuthorizationException;
        }
        $plan = $this->access->current($quality, 'create');
        if ((int) $plan?->initial_contributor_id !== $proof['contributor_id']) {
            throw new AuthorizationException;
        }
        $contributor = $this->member($proof['contributor_id'], $proof['organization_id']);
        foreach (['view', 'updateMetadata', 'revokeContributor'] as $operation) {
            $this->grant($quality, $quality, $operation, $resource);
        }
        foreach (['view', 'editDraft', 'viewDraftHistory', 'viewAuditHistory'] as $operation) {
            $this->grant($quality, $contributor, $operation, $resource);
        }
    }

    public function revokeQuality(User $issuer): void
    {
        $proof = ApproachAssignmentAccess::context() ?? throw new AuthorizationException;
        if ((int) $issuer->id !== $proof['issuer_id']) {
            throw new AuthorizationException;
        }
        $this->boundary->withinContext($issuer, function () use ($issuer, $proof): void {
            $quality = $this->member($proof['quality_id'], $proof['organization_id']);
            $row = $this->access->current($quality, 'quality') ?? throw new AuthorizationException;
            $this->revoke($issuer, $row);
        });
    }

    public function revokeContributor(User $quality, Approach $resource, User $contributor): void
    {
        $proof = ApproachAssignmentAccess::context() ?? throw new AuthorizationException;
        $this->boundary->withinContext($quality, function () use ($quality, $resource, $contributor, $proof): void {
            $current = Approach::where('organization_id', $proof['organization_id'])->whereKey($resource->id)->lockForUpdate()->first();
            if ($current === null || (int) $quality->id !== $proof['quality_id'] || (int) $contributor->id !== $proof['contributor_id']
                || ! $this->access->allows($quality, 'revokeContributor', $current)) {
                throw new AuthorizationException;
            }
            $this->member($contributor->id, $proof['organization_id']);
            $rows = ApproachAssignment::where('organization_id', $proof['organization_id'])->where('fixture_id', $proof['fixture_id'])
                ->where('grantee_id', $contributor->id)->where('approach_id', $current->id)->whereNull('revoked_at')->orderBy('id')->lockForUpdate()->get();
            foreach ($rows as $row) {
                $this->revoke($quality, $row);
            }
        });
    }

    private function member(int $id, int $organizationId): User
    {
        $user = User::whereKey($id)->where('principal_type', 'institution')->where('organization_id', $organizationId)->lockForUpdate()->first();
        if ($user === null || ! OrganizationMembership::where('organization_id', $organizationId)->where('user_id', $id)->lockForUpdate()->first()) {
            throw new AuthorizationException;
        }

        return $user;
    }

    private function grant(User $issuer, User $subject, string $operation, ?Approach $resource = null, ?User $recipient = null): void
    {
        $proof = ApproachAssignmentAccess::context() ?? throw new AuthorizationException;
        $start = CarbonImmutable::now('UTC')->startOfSecond();
        $row = new ApproachAssignment;
        $row->forceFill(['organization_id' => $proof['organization_id'], 'fixture_id' => $proof['fixture_id'],
            'scenario' => ApproachAssignmentAccess::SCENARIO, 'classification' => 'TEST_ONLY', 'grantee_id' => $subject->id,
            'issuer_id' => $issuer->id, 'operation' => $operation, 'scope_kind' => $resource === null ? 'institution' : 'approach',
            'approach_id' => $resource?->id, 'initial_contributor_id' => $recipient?->id,
            'starts_at' => $start->format('Y-m-d H:i:s'), 'expires_at' => $start->addHours(24)->format('Y-m-d H:i:s'),
            'created_at' => $start->format('Y-m-d H:i:s')]);
        $row->save();
        $this->event($issuer, $row, 'granted');
    }

    private function revoke(User $actor, ApproachAssignment $row): void
    {
        DB::table('approach_assignments')->where('id', $row->id)->update(['revoked_at' => ApproachAssignmentAccess::timestamp(), 'revoked_by' => $actor->id]);
        $this->event($actor, $row, 'revoked');
    }

    private function event(User $actor, ApproachAssignment $row, string $event): void
    {
        $change = new ApproachAssignmentChange;
        $change->forceFill(['organization_id' => $row->organization_id, 'fixture_id' => $row->fixture_id,
            'assignment_id' => $row->id, 'approach_id' => $row->approach_id, 'actor_id' => $actor->id,
            'subject_id' => $row->grantee_id, 'operation' => $row->operation, 'event' => $event,
            'event_key' => $row->public_id.':'.$event, 'created_at' => ApproachAssignmentAccess::timestamp()]);
        $change->save();
    }
}
