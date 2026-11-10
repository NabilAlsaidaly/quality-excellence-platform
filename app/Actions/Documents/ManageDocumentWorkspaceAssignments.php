<?php

namespace App\Actions\Documents;

use App\Authorization\DocumentWorkspaceAccess;
use App\Authorization\RoleManagement;
use App\Models\Document;
use App\Models\DocumentWorkspaceAssignment;
use App\Models\DocumentWorkspaceAssignmentChange;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Internal named fixture lifecycle only; never registered as an endpoint/command. */
final class ManageDocumentWorkspaceAssignments
{
    public function __construct(private DocumentWorkspaceAccess $access, private CreateDocumentWithVersion $boundary) {}

    public function bootstrap(?User $issuer): void
    {
        $this->issuer($issuer, function (User $current, array $proof): void {
            if (DocumentWorkspaceAssignment::where('organization_id', $proof['organization_id'])->where('fixture_id', $proof['fixture_id'])->exists()) {
                throw new LogicException('Do not adopt or re-bootstrap an existing fixture.');
            }
            $roles = app(RoleManagement::class);
            $role = $roles->create('Document TEST_ONLY '.$proof['fixture_id']);
            $roles->syncPermissions($role, [DocumentWorkspaceAccess::CAPABILITY]);
            foreach ([$proof['editor_id'], $proof['reader_id']] as $id) {
                $member = $this->member($id, $proof['organization_id']);
                $roles->assign($role, $member);
            }
            $this->grant($current, $proof['editor_id'], 'create');
            $this->grant($current, $proof['editor_id'], 'list');
            $this->grant($current, $proof['reader_id'], 'list');
        });
    }

    /** Called only by the newly created parent's afterPersist callback in its transaction. */
    public function initializeDocument(User $actor, Document $document): void
    {
        $proof = DocumentWorkspaceAccess::context() ?? throw new AuthorizationException;
        if (DB::transactionLevel() === 0 || ! $document->wasRecentlyCreated
            || (int) $actor->id !== $proof['editor_id'] || (int) $document->created_by !== (int) $actor->id
            || (int) $document->organization_id !== $proof['organization_id']) {
            throw new AuthorizationException;
        }
        $this->access->authorize($actor, 'create');
        $recipients = [$proof['editor_id'] => ['view', 'metadata', 'append', 'history'], $proof['reader_id'] => ['view', 'history']];
        ksort($recipients);
        foreach ($recipients as $id => $operations) {
            foreach ($operations as $operation) {
                $this->grant($actor, $id, $operation, $document);
            }
        }
        $this->access->authorize($actor, 'create');
    }

    public function revoke(?User $issuer, string $assignmentPublicId): void
    {
        $this->issuer($issuer, function (User $current, array $proof) use ($assignmentPublicId): void {
            $query = DocumentWorkspaceAssignment::where('organization_id', $proof['organization_id'])
                ->where('fixture_id', $proof['fixture_id'])->where('scenario', DocumentWorkspaceAccess::SCENARIO)->where('classification', 'TEST_ONLY');
            $candidate = Str::isUlid($assignmentPublicId) ? (clone $query)->where('public_id', $assignmentPublicId)->first() : null;
            if ($candidate === null) {
                throw (new ModelNotFoundException)->setModel(DocumentWorkspaceAssignment::class);
            }
            if ($candidate->document_id !== null) {
                Document::where('organization_id', $proof['organization_id'])->whereKey($candidate->document_id)->lockForUpdate()->firstOrFail();
            }
            $assignment = $query->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($assignment->revoked_at !== null) {
                return;
            }
            $now = DocumentWorkspaceAccess::timestamp();
            DB::table('document_workspace_assignments')->where('id', $assignment->id)->where('organization_id', $proof['organization_id'])
                ->update(['revoked_at' => $now, 'revoked_by' => $current->id]);
            $this->event($current, (int) $assignment->grantee_id, 'revoked', $assignment->operation, $assignment->document_id, $assignment, 'revoked:'.$assignment->public_id);
        });
    }

    /** Only after a denied application operation, never a Policy visibility query. */
    public function recordDenied(?User $actor, string $operation, ?string $publicId): void
    {
        if (! in_array($operation, DocumentWorkspaceAccess::OPERATIONS, true)) {
            return;
        }
        $this->access->withSession($actor, fn (User $fresh) => $this->boundary->withinContext($fresh,
            function (Organization $organization, User $current) use ($operation, $publicId): void {
                $document = null;
                if (! in_array($operation, ['create', 'list'], true)) {
                    $document = $publicId !== null && Str::isUlid($publicId) ? Document::where('organization_id', $organization->id)
                        ->where('public_id', $publicId)->lockForUpdate()->first() : null;
                    if ($document === null) {
                        return; // Do not attribute or reveal a missing/foreign resource.
                    }
                }
                $query = $this->access->query($current, $operation);
                $assignment = $query->where('document_id', $document?->id)->orderBy('id')->lockForUpdate()->first();
                if ($assignment !== null && $assignment->revoked_at === null && $assignment->expires_at <= DocumentWorkspaceAccess::timestamp()) {
                    $key = 'expired:'.$assignment->public_id;
                    if (DocumentWorkspaceAssignmentChange::where('organization_id', $organization->id)
                        ->where('fixture_id', DocumentWorkspaceAccess::context()['fixture_id'])->where('event_key', $key)->lockForUpdate()->first(['id']) === null) {
                        $this->event($current, (int) $current->id, 'expiration_observed', $operation, $document?->id, $assignment, $key);
                    }
                }
                $this->event($current, (int) $current->id, 'access_denied', $operation, $document?->id, $assignment, (string) Str::ulid());
            }));
    }

    private function issuer(?User $actor, Closure $operation): mixed
    {
        return $this->access->withSession($actor, fn (User $fresh) => $this->boundary->withinContext($fresh,
            function (Organization $organization, User $current) use ($operation): mixed {
                $proof = DocumentWorkspaceAccess::context() ?? throw new AuthorizationException;
                if ((int) $current->id !== $proof['issuer_id'] || (int) $organization->id !== $proof['organization_id']
                    || ! hash_equals($current->getAuthPassword(), (string) request()->session()->get('institution.password_hash'))) {
                    throw new AuthorizationException;
                }
                $result = $operation($current, $proof);
                if (! hash_equals($current->getAuthPassword(), (string) request()->session()->get('institution.password_hash'))) {
                    throw new AuthorizationException;
                }

                return $result;
            }));
    }

    private function member(int $id, int $organization): User
    {
        $user = User::whereKey($id)->where('principal_type', 'institution')->where('organization_id', $organization)->lockForUpdate()->first();
        if ($user === null || ! OrganizationMembership::where('organization_id', $organization)->where('user_id', $id)->lockForUpdate()->first()) {
            throw new AuthorizationException;
        }

        return $user;
    }

    private function grant(User $issuer, int $recipient, string $operation, ?Document $document = null): void
    {
        $proof = DocumentWorkspaceAccess::context() ?? throw new AuthorizationException;
        $editor = $recipient === $proof['editor_id'];
        $reader = $recipient === $proof['reader_id'];
        $allowed = $document === null ? ($editor ? ['create', 'list'] : ($reader ? ['list'] : []))
            : ($editor ? ['view', 'metadata', 'append', 'history'] : ($reader ? ['view', 'history'] : []));
        if (DB::transactionLevel() === 0 || ! in_array($operation, $allowed, true)) {
            throw new AuthorizationException;
        }
        $this->member($recipient, $proof['organization_id']);
        $start = CarbonImmutable::now('UTC')->startOfSecond();
        $assignment = new DocumentWorkspaceAssignment;
        $assignment->forceFill(['organization_id' => $proof['organization_id'], 'fixture_id' => $proof['fixture_id'],
            'scenario' => DocumentWorkspaceAccess::SCENARIO, 'classification' => 'TEST_ONLY',
            'grantee_id' => $recipient, 'issuer_id' => $issuer->id, 'document_id' => $document?->id,
            'scope_kind' => $document === null ? 'institution' : 'document', 'operation' => $operation,
            'starts_at' => $start->format('Y-m-d H:i:s'), 'expires_at' => $start->addHours(24)->format('Y-m-d H:i:s'),
            'created_at' => $start->format('Y-m-d H:i:s')])->save();
        $this->event($issuer, $recipient, 'granted', $operation, $document?->id, $assignment, 'granted:'.$assignment->public_id);
    }

    private function event(User $actor, int $subject, string $event, string $operation, ?int $document, ?DocumentWorkspaceAssignment $assignment, string $key): void
    {
        $proof = DocumentWorkspaceAccess::context() ?? throw new AuthorizationException;
        $change = new DocumentWorkspaceAssignmentChange;
        $change->forceFill(['organization_id' => $proof['organization_id'], 'fixture_id' => $proof['fixture_id'],
            'scenario' => DocumentWorkspaceAccess::SCENARIO, 'classification' => 'TEST_ONLY', 'assignment_id' => $assignment?->id,
            'document_id' => $document, 'actor_id' => $actor->id, 'subject_id' => $subject,
            'event' => $event, 'operation' => $operation, 'event_key' => $key, 'created_at' => DocumentWorkspaceAccess::timestamp()])->save();
    }
}
