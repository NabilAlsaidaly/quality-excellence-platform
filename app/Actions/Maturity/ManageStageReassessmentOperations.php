<?php

namespace App\Actions\Maturity;

use App\Authorization\DocumentWorkspaceAccess;
use App\Authorization\ReviewMaturityAccess;
use App\Authorization\TenantAuthorization;
use App\Models\Approach;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Separate finite stage grants. No normal bootstrap, route, delegation or renewal. */
final class ManageStageReassessmentOperations
{
    public function issue(string $recipientId, string $operation, string $stageId): string
    {
        return $this->issuer(function (User $issuer, Approach $approach, array $proof) use ($recipientId, $operation, $stageId): string {
            if (! Str::isUlid($recipientId) || ! Str::isUlid($stageId) || ! in_array($operation, ReviewMaturityAccess::REASSESSMENT_OPERATIONS, true)) {
                throw new AuthorizationException;
            }
            $recipient = User::where('organization_id', $proof['organization_id'])->where('public_id', $recipientId)->lockForUpdate()->first() ?? throw new AuthorizationException;
            $expected = match ($operation) {
                'open_reassessment', 'save_reassessment' => [$proof['author_id']],
                'review_reassessment' => [$proof['reviewer_id']], default => [$proof['author_id'], $proof['reviewer_id']],
            };
            if (! in_array((int) $recipient->id, $expected, true) || ! app(ReviewMaturityAccess::class)->structural($recipient)) {
                throw new AuthorizationException;
            }
            $stage = DB::table('maturity_workflow_stages')->where('organization_id', $proof['organization_id'])->where('fixture_id', $proof['fixture_id'])
                ->where('approach_id', $approach->id)->where('public_id', $stageId)->lockForUpdate()->first() ?? throw new AuthorizationException;
            $prior = $this->query($proof)->where('stage_id', $stage->id)->where('grantee_id', $recipient->id)->where('operation', $operation)->lockForUpdate()->first();
            if ($prior !== null) {
                return $prior->public_id; // An expired/revoked assignment is never renewed by retry.
            }
            $now = CarbonImmutable::now('UTC')->startOfSecond();
            $public = (string) Str::ulid();
            $id = $this->query($proof)->insertGetId(['public_id' => $public, 'organization_id' => $proof['organization_id'], 'fixture_id' => $proof['fixture_id'],
                'approach_id' => $approach->id, 'stage_id' => $stage->id, 'grantee_id' => $recipient->id, 'issuer_id' => $issuer->id, 'operation' => $operation,
                'starts_at' => $now->format('Y-m-d H:i:s'), 'expires_at' => $now->addHours(24)->format('Y-m-d H:i:s'), 'created_at' => $now->format('Y-m-d H:i:s')]);
            $this->event($proof, $issuer, $recipient->id, $id, 'granted', 'granted:'.$public, $operation);

            return $public;
        });
    }

    public function revoke(string $publicId): void
    {
        $this->issuer(function (User $issuer, Approach $approach, array $proof) use ($publicId): void {
            $row = Str::isUlid($publicId) ? $this->query($proof)->where('public_id', $publicId)->lockForUpdate()->first() : null;
            if ($row === null) {
                throw new AuthorizationException;
            }
            if ($row->revoked_at !== null) {
                return;
            }
            $this->query($proof)->where('id', $row->id)->update(['revoked_at' => ReviewMaturityAccess::timestamp(), 'revoked_by' => $issuer->id]);
            $this->event($proof, $issuer, $row->grantee_id, $row->id, 'revoked', 'revoked:'.$row->public_id, $row->operation);
        });
    }

    private function issuer(Closure $callback): mixed
    {
        $proof = ReviewMaturityAccess::context() ?? throw new AuthorizationException;
        if (! ReviewMaturityAccess::hasReassessmentScope()) {
            throw new AuthorizationException;
        }

        return app(DocumentWorkspaceAccess::class)->withSession(Auth::guard('institution')->user(), fn (User $actor) => app(TenantAuthorization::class)->withinMutation($actor,
            function (Organization $organization, User $issuer) use ($proof, $callback): mixed {
                if ((int) $issuer->id !== $proof['issuer_id'] || (int) $organization->id !== $proof['organization_id']) {
                    throw new AuthorizationException;
                }
                $approach = Approach::where('organization_id', $organization->id)->whereKey($proof['approach_id'])->lockForUpdate()->first() ?? throw new AuthorizationException;
                DB::table('maturity_workflow_stages')->where('organization_id', $organization->id)->where('fixture_id', $proof['fixture_id'])
                    ->where('approach_id', $approach->id)->orderBy('stage_ordinal')->lockForUpdate()->get();
                $this->query($proof)->orderBy('id')->lockForUpdate()->get();

                return $callback($issuer, $approach, $proof);
            }, User::where('organization_id', $proof['organization_id'])->whereIn('id', [$proof['author_id'], $proof['reviewer_id']])->orderBy('id')->get()->all()));
    }

    public function recordDenied(string $operation, string $approachId, ?int $stageId): void
    {
        if (! ReviewMaturityAccess::hasReassessmentScope() || ! in_array($operation, ReviewMaturityAccess::REASSESSMENT_OPERATIONS, true)) {
            return;
        }
        try {
            app(DocumentWorkspaceAccess::class)->withSession(Auth::guard('institution')->user(), fn (User $actor) => app(TenantAuthorization::class)->withinMutation($actor,
                function (Organization $organization, User $current) use ($operation, $approachId, $stageId): void {
                    $proof = ReviewMaturityAccess::context() ?? throw new AuthorizationException;
                    $approach = Approach::where('organization_id', $organization->id)->whereKey($proof['approach_id'])->where('public_id', $approachId)->lockForUpdate()->first();
                    if ($approach === null || $stageId === null || ! DB::table('maturity_workflow_stages')->where('organization_id', $organization->id)
                        ->where('fixture_id', $proof['fixture_id'])->where('approach_id', $approach->id)->where('id', $stageId)->lockForUpdate()->exists()) {
                        return;
                    }
                    $grant = $this->query($proof)->where('stage_id', $stageId)->where('grantee_id', $current->id)->where('operation', $operation)->lockForUpdate()->first();
                    if ($grant !== null && $grant->revoked_at === null && $grant->expires_at <= ReviewMaturityAccess::timestamp()
                        && ! DB::table('maturity_reassessment_operation_changes')->where('organization_id', $organization->id)->where('fixture_id', $proof['fixture_id'])
                            ->where('event_key', 'expired:'.$grant->public_id)->exists()) {
                        $this->event($proof, $current, $current->id, $grant->id, 'expiration_observed', 'expired:'.$grant->public_id, $operation);
                    }
                    $this->event($proof, $current, $current->id, $grant?->id, 'access_denied', (string) Str::ulid(), $operation);
                }));
        } catch (AuthorizationException) {
            // An unverified session is not an attributable tenant audit actor.
        }
    }

    private function query(array $proof): Builder
    {
        return DB::table('maturity_reassessment_operation_assignments')->where('organization_id', $proof['organization_id'])
            ->where('fixture_id', $proof['fixture_id'])->where('approach_id', $proof['approach_id'])->where('scenario', ReviewMaturityAccess::SCENARIO)->where('classification', 'TEST_ONLY');
    }

    private function event(array $proof, User $actor, int $subject, ?int $assignment, string $event, string $key, string $operation): void
    {
        DB::table('maturity_reassessment_operation_changes')->insert(['public_id' => (string) Str::ulid(), 'organization_id' => $proof['organization_id'],
            'fixture_id' => $proof['fixture_id'], 'assignment_id' => $assignment, 'actor_id' => $actor->id, 'subject_id' => $subject, 'event' => $event,
            'event_key' => $key, 'created_at' => ReviewMaturityAccess::timestamp(), 'details_json' => json_encode(['operation' => $operation], JSON_THROW_ON_ERROR)]);
    }
}
