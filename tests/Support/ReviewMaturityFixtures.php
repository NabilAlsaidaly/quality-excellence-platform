<?php

namespace Tests\Support;

use App\Actions\Documents\DocumentWorkspace;
use App\Actions\Documents\ManageDocumentWorkspaceAssignments;
use App\Actions\Reviews\ManageReviewMaturityAssignments;
use App\Authorization\ApproachAssignmentAccess;
use App\Authorization\DocumentWorkspaceAccess;
use App\Authorization\ReviewMaturityAccess;
use App\Models\Approach;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\ReviewMaturityReview;
use App\Policies\ReviewMaturityPolicy;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

trait ReviewMaturityFixtures
{
    use ApproachAssignmentFixtures;

    protected array $workflowFixtures = [];

    protected function reviewMaturityFixture(bool $fakeStorage = true): array
    {
        if ($fakeStorage) {
            Storage::set('local', Storage::fake('workflow-'.bin2hex(random_bytes(8))));
        }
        Gate::policy(ReviewMaturityReview::class, ReviewMaturityPolicy::class);
        $f = $this->assignmentFixture();
        $f['author'] = $f['contributor'];
        $f['reviewer'] = $f['unassigned'];
        $f['document_proof'] = array_replace($f['proof'], ['scenario' => DocumentWorkspaceAccess::SCENARIO,
            'editor_id' => $f['author']->id, 'reader_id' => $f['reviewer']->id]);
        $this->loginWorkflowActor($f, 'issuer');
        $this->withExistingWorkflowProofs($f, fn () => app(ManageDocumentWorkspaceAssignments::class)->bootstrap($f['issuer']));
        $publicId = $this->inAssignmentFixture($f, fn () => $this->createAssignedApproach($f, 'منهجية العرض المتكامل'));
        $f['approach'] = Approach::where('public_id', $publicId)->firstOrFail();
        $f['workflow_proof'] = array_replace($f['proof'], ['scenario' => ReviewMaturityAccess::SCENARIO,
            'approach_id' => $f['approach']->id, 'author_id' => $f['author']->id, 'reviewer_id' => $f['reviewer']->id]);
        $this->workflowFixtures[] = $f;
        $this->asWorkflow($f, 'issuer', fn () => app(ManageReviewMaturityAssignments::class)->bootstrap());
        $f['document_public_id'] = $this->asWorkflow($f, 'author', fn () => app(DocumentWorkspace::class)->create([
            'title' => 'شاهد اصطناعي محلي', 'description' => 'بايتات اختبار فقط؛ ليست وثيقة ممسوحة أو معتمدة.']));
        $document = Document::where('organization_id', $f['organization']->id)->where('public_id', $f['document_public_id'])->firstOrFail();
        $version = DocumentVersion::where('organization_id', $document->organization_id)->where('document_id', $document->id)->firstOrFail();
        $f['workflow_proof']['synthetic_versions'] = [strtolower($version->public_id) => ['document_public_id' => $document->public_id,
            'sha256' => $version->sha256, 'byte_size' => $version->byte_size]];
        $this->workflowFixtures[array_key_last($this->workflowFixtures)] = $f;

        return $f;
    }

    protected function loginWorkflowActor(array $f, string $actor): void
    {
        $this->loginAssignmentActor($f[$actor], $f['organization']);
    }

    protected function withExistingWorkflowProofs(array $f, Closure $callback): mixed
    {
        return ApproachAssignmentAccess::runFixture(request(), $f['proof'], fn () => DocumentWorkspaceAccess::runFixture(request(), $f['document_proof'], $callback));
    }

    protected function inWorkflow(array $f, Closure $callback): mixed
    {
        return $this->withExistingWorkflowProofs($f, fn () => ReviewMaturityAccess::runFixture(request(), $f['workflow_proof'], $callback));
    }

    protected function asWorkflow(array $f, string $actor, Closure $callback): mixed
    {
        $this->loginWorkflowActor($f, $actor);

        return $this->inWorkflow($f, $callback);
    }

    protected function workflowPanelProof(array $f): array
    {
        return ['approach' => $f['proof'], 'document' => $f['document_proof'], 'workflow' => $f['workflow_proof']];
    }

    protected function cleanupReviewMaturity(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($this->workflowFixtures as $f) {
            $db = DB::connection();
            if (! app()->environment('testing') || ! (($db->getDriverName() === 'sqlite' && $db->getDatabaseName() === ':memory:')
                || ($db->getDriverName() === 'mysql' && $db->getDatabaseName() === 'quality_platform_ci' && getenv('MYSQL_TESTS_ENABLED') === '1'
                    && $db->selectOne('SELECT @@server_uuid AS uuid')->uuid === $f['proof']['server_uuid']))) {
                throw new RuntimeException('Refuse workflow cleanup outside the owned disposable test database.');
            }
            $organization = $f['organization']->id;
            // Remove ONLY this fixture's rows; newest first preserves the explicit predecessor FKs.
            foreach (['review_maturity_notifications', 'review_maturity_audit', 'maturity_workflow_assessments', 'review_maturity_reviews',
                'maturity_workflow_evidence', 'maturity_workflow_submissions', 'maturity_workflow_configurations', 'review_maturity_assignments', 'maturity_workflow_stages'] as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                foreach (DB::table($table)->where('organization_id', $organization)->where('fixture_id', $f['proof']['fixture_id'])->orderByDesc('id')->pluck('id') as $id) {
                    DB::table($table)->where('organization_id', $organization)->where('id', $id)->delete();
                }
            }
            foreach (DB::table('document_versions')->where('organization_id', $organization)->get() as $version) {
                Storage::disk('local')->delete($version->storage_key);
            }
            foreach (['document_workspace_assignment_changes', 'document_workspace_assignments', 'document_metadata_revisions', 'document_versions', 'documents',
                'approach_assignment_changes', 'approach_assignments', 'approach_changes', 'approach_content_revisions', 'approaches', 'authorization_changes',
                'model_has_roles', 'model_has_permissions'] as $table) {
                DB::table($table)->where('organization_id', $organization)->delete();
            }
            $roles = DB::table('roles')->where('organization_id', $organization)->pluck('id');
            DB::table('role_has_permissions')->whereIn('role_id', $roles)->delete();
            foreach (['roles', 'organization_memberships', 'users'] as $table) {
                DB::table($table)->where('organization_id', $organization)->delete();
            }
        }
    }
}
