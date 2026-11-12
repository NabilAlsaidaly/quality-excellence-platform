<?php

namespace Tests\Feature\Filament;

use App\Filament\Institution\Resources\DocumentResource\Pages\DocumentBank;
use Filament\Facades\Filament;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Documents\DocumentFileOperationAuthorizationTest;
use Tests\Support\ClosureDocumentUploadAuthorization;
use Tests\Support\ClosureDocumentUploadContext;
use Tests\Support\DocumentWorkspacePanelProvider;
use Tests\Support\HarmlessDocumentSamples;

class ClosureDocumentUploadTest extends DocumentFileOperationAuthorizationTest
{
    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->instance('request', Request::create('http://localhost'));
        $app->booting(fn () => $app->register(DocumentWorkspacePanelProvider::class));
        $app->booted(function (): void {
            foreach (Route::getRoutes() as $route) {
                if (in_array('POST', $route->methods(), true) && str_contains($route->getName() ?? '', 'livewire') && str_contains($route->uri(), 'update')) {
                    $route->middleware(ClosureDocumentUploadContext::class);
                }
            }
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public static function formats(): array
    {
        return [['pdf'], ['docx']];
    }

    #[DataProvider('formats')]
    public function test_native_filepond_uuid_field_receives_a_bound_signed_url_and_other_fields_are_denied(string $format): void
    {
        $f = $this->documentFixture();
        $f['proof']['file_operation_scope'] = 'CLOSURE_FILES_LOCAL_001';
        $this->permittedUpload($f);
        config(['app.url' => 'http://localhost']);
        URL::forceRootUrl('http://localhost');
        Filament::setCurrentPanel(Filament::getPanel('document_fixture'));
        Filament::bootCurrentPanel();
        DocumentWorkspacePanelProvider::withFixture($f['proof'], function () use ($f, $format): void {
            $this->post('/institution/login', ['organization' => $f['organization']->public_id, 'email' => $f['editor']->email, 'password' => 'password'])->assertRedirect();
            $page = $this->get('/institution/document-workspace/documents')->assertOk();
            $dom = new \DOMDocument;
            $old = libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8" ?>'.$page->getContent());
            libxml_clear_errors();
            libxml_use_internal_errors($old);
            $snapshot = null;
            foreach ($dom->getElementsByTagName('*') as $node) {
                if ($node->hasAttribute('wire:snapshot')) {
                    $candidate = $node->getAttribute('wire:snapshot');
                    $name = json_decode($candidate, true)['memo']['name'];
                    if ($name === DocumentBank::class || str_contains($name, 'document-bank')) {
                        $snapshot = $candidate;
                    }
                }
            }
            $this->assertIsString($snapshot);
            $send = function (string $method, array $params, int $status = 200, array $updates = []) use (&$snapshot) {
                $response = $this->postJson(Livewire::getUpdateUri(), ['components' => [['snapshot' => $snapshot, 'updates' => $updates,
                    'calls' => [['path' => '', 'method' => $method, 'params' => $params]]]]], ['X-Livewire' => 'true', 'X-CSRF-TOKEN' => session()->token()])->assertStatus($status);
                if ($status === 200) {
                    $snapshot = $response->json('components.0.snapshot');
                }

                return $response;
            };
            $file = tempnam(sys_get_temp_dir(), 'closure-native-http-');
            $format === 'pdf' ? file_put_contents($file, HarmlessDocumentSamples::pdf()) : HarmlessDocumentSamples::docx($file);
            $mime = $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
            try {
                $send('mountAction', ['uploadFile']);
                $response = $send('_startUpload', ['mountedActions.0.data.file.8e32f9d8-8d48-4a01-b3f6-70d187dc4181',
                    [['name' => 'harmless.'.$format, 'size' => filesize($file), 'type' => $mime]], false]);
                $response->assertJsonPath('components.0.effects.dispatches.0.name', 'upload:generatedSignedUrl');
                $url = $response->json('components.0.effects.dispatches.0.params.url');
                parse_str(parse_url($url, PHP_URL_QUERY), $query);
                $this->assertSame($f['editor']->public_id, $query['closure_actor']);
                $this->assertSame($f['proof']['fixture_id'], $query['closure_fixture']);
                $this->assertArrayHasKey('signature', $query);
                config(['livewire.temporary_file_upload.middleware' => [ClosureDocumentUploadAuthorization::class]]);
                Storage::set('tmp-for-tests', Storage::disk('local'));
                $this->withoutExceptionHandling();
                $upload = $this->post($url, ['files' => [new UploadedFile($file, 'harmless.'.$format, $mime, UPLOAD_ERR_OK, true)]])->assertOk()->assertJsonCount(1, 'paths');
                $send('_finishUpload', ['mountedActions.0.data.file.8e32f9d8-8d48-4a01-b3f6-70d187dc4181', $upload->json('paths'), false]);
                $send('callMountedAction', [], updates: ['mountedActions.0.data.title' => 'رفع أصلي عبر HTTP', 'mountedActions.0.data.description' => 'بايتات محفوظة من النموذج الأصلي.']);
                $this->assertDatabaseCount('document_intakes', 1);
                $this->assertDatabaseCount('document_versions', 1);
            } finally {
                unlink($file);
                $this->withExceptionHandling();
            }
            $send('_startUpload', ['unrelated.file', [['name' => 'harmless.pdf', 'size' => 329, 'type' => 'application/pdf']], false], 403);
        });
    }
}
