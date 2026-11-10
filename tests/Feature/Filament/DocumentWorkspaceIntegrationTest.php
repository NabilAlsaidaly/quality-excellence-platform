<?php

namespace Tests\Feature\Filament;

use App\Actions\Documents\DocumentWorkspace;
use App\Filament\Institution\Resources\DocumentResource\Pages\DocumentBank;
use App\Filament\Institution\Resources\DocumentResource\Pages\DocumentWorkspace as WorkspacePage;
use App\Models\User;
use DOMDocument;
use Filament\Facades\Filament;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\DocumentWorkspaceFixtures;
use Tests\Support\DocumentWorkspacePanelProvider;
use Tests\TestCase;

class DocumentWorkspaceIntegrationTest extends TestCase
{
    use DatabaseMigrations, DocumentWorkspaceFixtures;

    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        // Match the real browser router: resolve HttpKernel before booting the
        // test provider, rather than relying on a later first HTTP resolution.
        $app->instance('request', Request::create('http://localhost'));
        $app->booting(fn () => $app->register(DocumentWorkspacePanelProvider::class));
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareDocumentWorkspace();
        config(['app.url' => 'http://localhost']);
        config(['app.debug' => false]);
        URL::forceRootUrl('http://localhost');
    }

    protected function tearDown(): void
    {
        $this->cleanupDocumentWorkspace();
        parent::tearDown();
    }

    public function test_real_institutional_login_and_document_policy_admit_a_separate_panel_without_j03_rights(): void
    {
        $f = $this->documentFixture();
        DocumentWorkspacePanelProvider::withFixture($f['proof'], function () use ($f): void {
            $this->post('/institution/login', ['organization' => $f['organization']->public_id, 'email' => $f['editor']->email, 'password' => 'password'])
                ->assertRedirect('/institution/'.$f['organization']->public_id.'/account');
            $this->assertInstanceOf(User::class, Auth::guard('institution')->user());
            $this->assertSame($f['editor']->id, Auth::guard('institution')->id());
            $this->assertFalse($f['editor']->canAccessPanel(Filament::getPanel('document_fixture')));
            $this->assertFalse($f['editor']->canAccessPanel(Filament::getPanel('institution')));
            $this->assertFalse(DB::table('permissions')->where('name', 'approaches.access')->exists());
            $this->assertTrue($this->inDocumentFixture($f, fn () => app(DocumentWorkspace::class)->can('list')));
            $this->get('/institution/document-workspace/documents')->assertOk()->assertSee('بنك الوثائق');
        });
    }

    private function signIn(array $f, string $name): void
    {
        $this->get('/institution/login')->assertOk();
        $this->post('/institution/login', ['organization' => $f['organization']->public_id, 'email' => $f[$name]->email, 'password' => 'password'])
            ->assertRedirect('/institution/'.$f['organization']->public_id.'/account');
        $this->assertAuthenticatedAs($f[$name], 'institution');
        $this->assertGuest('web');
    }

    private function snapshot(TestResponse $response, string $class): string
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $name = str($class)->replace('\\', '.')->explode('.')->map(fn ($part) => (string) str($part)->kebab())->implode('.');
        foreach ($document->getElementsByTagName('*') as $node) {
            if ($node->hasAttribute('wire:snapshot')) {
                $snapshot = $node->getAttribute('wire:snapshot');
                if (in_array(json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR)['memo']['name'], [$class, $name], true)) {
                    return $snapshot;
                }
            }
        }
        $this->fail('Actual native Document page snapshot missing.');
    }

    private function transport(string &$snapshot, string $method, array $params = [], array $updates = [], int $status = 200): TestResponse
    {
        $response = $this->postJson(Livewire::getUpdateUri(), ['components' => [['snapshot' => $snapshot, 'updates' => $updates,
            'calls' => [['path' => '', 'method' => $method, 'params' => $params]]]]], ['X-Livewire' => 'true', 'X-CSRF-TOKEN' => session()->token()]);
        $response->assertStatus($status);
        if ($status === 200) {
            $snapshot = $response->json('components.0.snapshot');
            $this->assertIsString($snapshot);
        }

        return $response;
    }

    public function test_actual_native_livewire_http_creates_edits_appends_and_reloads_history_with_real_document_policy(): void
    {
        $f = $this->documentFixture();
        DocumentWorkspacePanelProvider::withFixture($f['proof'], function () use ($f): void {
            $this->signIn($f, 'editor');
            $bank = $this->get('/institution/document-workspace/documents')->assertOk()->assertSee('إنشاء وثيقة اختبارية');
            $snapshot = $this->snapshot($bank, DocumentBank::class);
            $this->transport($snapshot, 'mountAction', ['create']);
            $this->transport($snapshot, 'callMountedAction', [], ['mountedActions.0.data.title' => 'وثيقة عبر Livewire الحقيقي',
                'mountedActions.0.data.description' => 'وصف محفوظ عبر HTTP']);
            $this->assertDatabaseCount('documents', 1);
            $this->assertDatabaseCount('document_versions', 1);
            $this->assertSame(6, DB::table('document_workspace_assignments')->whereNotNull('document_id')->count());
            $id = DB::table('documents')->value('public_id');
            $page = $this->get('/institution/document-workspace/documents/'.$id)->assertOk()->assertSee('وثيقة عبر Livewire الحقيقي');
            $snapshot = $this->snapshot($page, WorkspacePage::class);
            $this->transport($snapshot, 'mountAction', ['edit']);
            $this->transport($snapshot, 'callMountedAction', [], ['mountedActions.0.data.title' => 'عنوان محدث من المتصفح',
                'mountedActions.0.data.description' => 'وصف محدث محفوظ']);
            $this->assertDatabaseCount('document_metadata_revisions', 1);
            $this->assertSame('وثيقة عبر Livewire الحقيقي', DB::table('documents')->value('title'));
            $this->transport($snapshot, 'mountAction', ['append']);
            $this->transport($snapshot, 'callMountedAction');
            $this->assertDatabaseCount('document_versions', 2);
            $this->get('/institution/document-workspace/documents/'.$id)->assertOk()->assertSee('عنوان محدث من المتصفح');
            $history = $this->transport($snapshot, '$refresh', [], ['historyType' => 'metadata']);
            $html = $history->json('components.0.effects.html');
            $this->assertIsString($html);
            $this->assertStringContainsString('البيانات الأصلية المحفوظة', $html);
            $this->assertStringContainsString('وثيقة عبر Livewire الحقيقي', $html);
            $this->assertFalse(DB::table('permissions')->where('name', 'approaches.access')->exists());
            $this->post('/institution/'.$f['organization']->public_id.'/logout')->assertRedirect('/institution/login');
            $this->get('/institution/document-workspace/documents')->assertRedirect('/institution/login');
        });
    }

    public function test_reader_unassigned_tenant_revoked_expired_session_and_unsigned_context_cannot_gain_writes(): void
    {
        $f = $this->documentFixture();
        $foreign = $this->documentFixture();
        $id = $this->inDocumentFixture($f, fn () => $this->createWorkspaceDocument($f));
        $foreignId = $this->inDocumentFixture($foreign, fn () => $this->createWorkspaceDocument($foreign));
        DocumentWorkspacePanelProvider::withFixture($f['proof'], function () use ($f, $id, $foreignId): void {
            $this->signIn($f, 'reader');
            $page = $this->get('/institution/document-workspace/documents/'.$id)->assertOk()->assertDontSee('تعديل البيانات')->assertDontSee('إنشاء إصدار اختباري');
            $snapshot = $this->snapshot($page, WorkspacePage::class);
            $this->transport($snapshot, 'callMountedAction', status: 403);
            $this->transport($snapshot, '_startUpload', ['file', [], false], status: 403);
            // Livewire4 intentionally renders locked-property tampering as a
            // generic419 with debug=false before application render callbacks.
            $tampered = $this->transport($snapshot, '$refresh', updates: ['document' => $foreignId], status: 419);
            $this->assertStringNotContainsString($foreignId, $tampered->getContent());
            $this->get('/institution/document-workspace/documents/'.$foreignId)->assertNotFound();
            DB::table('document_workspace_assignments')->where('fixture_id', $f['proof']['fixture_id'])->where('grantee_id', $f['reader']->id)
                ->where('operation', 'view')->update(['revoked_at' => now('UTC')->format('Y-m-d H:i:s'), 'revoked_by' => $f['issuer']->id]);
            $this->get('/institution/document-workspace/documents/'.$id)->assertForbidden();
            $this->signIn($f, 'unassigned');
            $this->get('/institution/document-workspace/documents')->assertForbidden();
            $this->signIn($f, 'editor');
            User::findOrFail($f['editor']->id)->forceFill(['password' => 'changed-test-password'])->save();
            $this->get('/institution/document-workspace/documents')->assertForbidden();
        });
        $this->get('/institution/document-workspace/documents', ['X-Document-Fixture' => $f['proof']['fixture_id']])->assertNotFound();
        $this->assertDatabaseCount('document_metadata_revisions', 0);
        $this->assertDatabaseCount('document_versions', 2);
    }
}
