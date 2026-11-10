<?php

namespace Tests\Support;

use App\Actions\Documents\CreateDocumentWithVersion;
use App\Actions\Documents\DocumentWorkspace;
use App\Actions\Documents\ManageDocumentWorkspaceAssignments;
use App\Authorization\DocumentWorkspaceAccess;
use App\Authorization\TenantContext;
use App\Models\Document;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/** Explicit named fictional actors. No J03 permissions or runtime bootstrap. */
trait DocumentWorkspaceFixtures
{
    protected array $documentWorkspaceFixtures = [];

    protected array $workspaceDocumentIds = [];

    protected function prepareDocumentWorkspace(): void
    {
        Storage::set('local', Storage::fake('document-workspace-'.strtolower((string) Str::ulid())));
        Event::listen('eloquent.created: '.Document::class, function (Document $document): void {
            $this->workspaceDocumentIds[] = $document->id;
        });
    }

    protected function documentFixture(bool $bootstrap = true): array
    {
        $organization = Organization::factory()->create();
        $actors = [];
        foreach (['issuer', 'editor', 'reader', 'unassigned'] as $name) {
            $actors[$name] = User::factory()->create(['principal_type' => 'institution', 'organization_id' => $organization->id]);
            OrganizationMembership::factory()->create(['organization_id' => $organization->id, 'user_id' => $actors[$name]->id]);
        }
        $db = DB::connection();
        $proof = ['scenario' => 'DOCUMENT_LOCAL_001', 'fixture_id' => bin2hex(random_bytes(16)),
            'driver' => $db->getDriverName(), 'database' => $db->getDatabaseName(),
            'server_uuid' => $db->getDriverName() === 'mysql' ? $db->selectOne('SELECT @@server_uuid AS uuid')->uuid : null,
            'organization_id' => $organization->id, 'issuer_id' => $actors['issuer']->id,
            'editor_id' => $actors['editor']->id, 'reader_id' => $actors['reader']->id];
        $fixture = $actors + compact('organization', 'proof');
        $this->documentWorkspaceFixtures[] = $fixture;
        if ($bootstrap) {
            $this->loginDocumentActor($actors['issuer'], $organization);
            $this->inDocumentFixture($fixture, fn () => app(ManageDocumentWorkspaceAssignments::class)->bootstrap($actors['issuer']));
        }

        return $fixture;
    }

    protected function loginDocumentActor(User $actor, Organization $organization): void
    {
        Auth::guard('institution')->setUser($actor);
        app('session.store')->put(['identity.active_guard' => 'institution', 'institution.organization' => $organization->public_id,
            'institution.password_hash' => $actor->getAuthPassword()]);
        request()->setLaravelSession(app('session.store'));
    }

    protected function inDocumentFixture(array $fixture, Closure $operation): mixed
    {
        return DocumentWorkspaceAccess::runFixture(request(), $fixture['proof'], $operation);
    }

    protected function createWorkspaceDocument(array $fixture, string $title = 'وثيقة اختبار مؤسسية'): string
    {
        $this->loginDocumentActor($fixture['editor'], $fixture['organization']);

        return app(DocumentWorkspace::class)->create(['title' => $title, 'description' => 'وصف عربي محفوظ للوثيقة']);
    }

    protected function unassignedWorkspaceDocument(array $fixture): string
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, 'unassigned internal synthetic bytes');
        rewind($stream);
        try {
            return app(TenantContext::class)->run($fixture['editor'], $fixture['organization']->id,
                fn () => app(CreateDocumentWithVersion::class)->execute($fixture['editor'], ['title' => 'وثيقة غير مسندة',
                    'description' => 'يجب ألا تظهر', 'original_filename' => 'fixture.txt'], $stream))['document_public_id'];
        } finally {
            fclose($stream);
        }
    }

    protected function cleanupDocumentWorkspace(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        $db = DB::connection();
        if (! app()->environment('testing') || ! (($db->getDriverName() === 'sqlite' && $db->getDatabaseName() === ':memory:')
            || ($db->getDriverName() === 'mysql' && $db->getDatabaseName() === 'quality_platform_ci' && getenv('MYSQL_TESTS_ENABLED') === '1'))) {
            throw new RuntimeException('Refuse cleanup outside disposable document workspace tests.');
        }
        foreach ($this->documentWorkspaceFixtures as $f) {
            if ($db->getDriverName() === 'mysql' && $db->selectOne('SELECT @@server_uuid AS uuid')->uuid !== $f['proof']['server_uuid']) {
                throw new RuntimeException('Owned MySQL identity changed.');
            }
            foreach (['document_workspace_assignment_changes', 'document_workspace_assignments'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->where('organization_id', $f['organization']->id)->where('fixture_id', $f['proof']['fixture_id'])->delete();
                }
            }
        }
        if (Schema::hasTable('document_metadata_revisions')) {
            DB::table('document_metadata_revisions')->whereIn('document_id', $this->workspaceDocumentIds)->delete();
        }
        foreach (DB::table('document_versions')->whereIn('document_id', $this->workspaceDocumentIds)->get() as $version) {
            Storage::disk('local')->delete($version->storage_key);
        }
        DB::table('document_versions')->whereIn('document_id', $this->workspaceDocumentIds)->delete();
        DB::table('documents')->whereIn('id', $this->workspaceDocumentIds)->delete();
        foreach ($this->documentWorkspaceFixtures as $f) {
            $organization = $f['organization']->id;
            DB::table('authorization_changes')->where('organization_id', $organization)->delete();
            $roles = DB::table('roles')->where('organization_id', $organization)->pluck('id');
            foreach (['model_has_roles', 'model_has_permissions'] as $table) {
                DB::table($table)->where('organization_id', $organization)->delete();
            }
            DB::table('role_has_permissions')->whereIn('role_id', $roles)->delete();
            DB::table('roles')->where('organization_id', $organization)->delete();
            DB::table('organization_memberships')->where('organization_id', $organization)->delete();
            DB::table('users')->where('organization_id', $organization)->delete();
        }
    }
}
