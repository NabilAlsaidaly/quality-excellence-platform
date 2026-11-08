<?php

namespace Tests\Feature\Filament;

use App\Actions\Documents\CreateDocumentWithVersion;
use App\Actions\Documents\ListDocuments;
use App\Actions\Documents\ReadDocumentHistory;
use App\Authorization\TenantContext;
use App\Filament\Documents\Blueprints\DocumentDetailsBlueprint;
use App\Filament\Documents\Blueprints\DocumentFormBlueprint;
use App\Filament\Documents\Blueprints\DocumentTableBlueprint;
use App\Filament\Documents\Blueprints\DocumentVersionsBlueprint;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\OrganizationMembership;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Panel;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class DocumentFilamentPresentationTest extends TestCase
{
    use DatabaseMigrations;

    private array $owned = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::set('local', Storage::fake('documents-'.strtolower((string) Str::ulid())));
        app()->setLocale('ar');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel(); // Reuse the existing theme/colors, never register a document page.
        DocumentPresentationHarness::$member = null;
        DocumentPresentationHarness::$syntheticGrant = false;
        DocumentPresentationHarness::$failRead = false;
        Event::listen('eloquent.created: '.Document::class, function (Document $document): void {
            $this->owned[] = $document->id;
        });
        Livewire::component('document-presentation-fixture', DocumentPresentationHarness::class);
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach (DB::table('document_versions')->whereIn('document_id', $this->owned)->get() as $version) {
            Storage::disk('local')->delete($version->storage_key);
        }
        DB::table('document_versions')->whereIn('document_id', $this->owned)->delete();
        DB::table('documents')->whereIn('id', $this->owned)->delete();
        parent::tearDown();
    }

    private function seedPresentation(): array
    {
        $member = OrganizationMembership::factory()->create();
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, 'PRIVATE SYNTHETIC BYTES');
        rewind($stream);
        try {
            $document = app(TenantContext::class)->run($member->user, $member->organization_id,
                fn () => app(CreateDocumentWithVersion::class)->execute($member->user,
                    ['title' => 'وثيقة <script>alert(1)</script>', 'description' => 'بيانات اختبار عربية', 'original_filename' => 'PRIVATE-NAME.bin'], $stream));
        } finally {
            fclose($stream);
        }
        DocumentPresentationHarness::$member = $member;
        DocumentPresentationHarness::$syntheticGrant = true;

        return [$member, $document];
    }

    public function test_actual_filament_components_render_rtl_and_public_safe_metadata_only(): void
    {
        [$member, $document] = $this->seedPresentation();
        $version = DocumentVersion::sole();
        foreach (['list', 'details', 'versions', 'form'] as $mode) {
            $component = Livewire::test(DocumentPresentationHarness::class, ['mode' => $mode, 'document' => $document['document_public_id']])
                ->assertOk()->assertSee('الوثائق والإصدارات')->assertSee('بيانات اختبار')->assertSee('الرفع غير مفعّل');
            $html = $component->html();
            $this->assertStringContainsString('lang="ar" dir="rtl"', $html);
            $this->assertStringContainsString('qep-preview', $html);
            $theme = file_get_contents(resource_path('css/filament/admin/theme.css'));
            $this->assertStringContainsString('--qep-primary: #17324D', $theme);
            $this->assertStringContainsString('--qep-secondary: #087F78', $theme);
            foreach ([$version->storage_key, $version->sha256, 'PRIVATE-NAME.bin', 'PRIVATE SYNTHETIC BYTES', $member->user->email] as $private) {
                $this->assertStringNotContainsString($private, $html);
            }
            $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
            $this->assertStringNotContainsString('type="file"', $html);
            if ($mode === 'list') {
                $this->assertStringContainsString('fi-ta', $html);
                $this->assertTrue($component->instance()->getTable()->hasQuery() === false);
            }
            if ($mode === 'form') {
                $fields = $component->instance()->form->getFlatFields();
                $this->assertInstanceOf(TextInput::class, $fields['data.title'] ?? $fields['title']);
                foreach ($fields as $field) {
                    $this->assertTrue($field->isDisabled());
                    $this->assertFalse($field instanceof FileUpload);
                }
            }
            if (getenv('ISSUE42_VISUAL_FIXTURES') === '1') {
                $directory = base_path('.local-tools/output/issue42');
                if (! is_dir($directory)) {
                    mkdir($directory, 0777, true);
                }
                // Static screenshot input only: do not host the test Livewire component.
                $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true, flags: JSON_THROW_ON_ERROR);
                $css = file_get_contents(public_path('build/'.$manifest['resources/css/filament/admin/theme.css']['file']));
                $nativeStyles = Blade::render('@filamentStyles @livewireStyles');
                // qep-preview uses the existing Tahoma token; omit an unreachable localhost Inter link in static-only artifacts.
                $nativeStyles = preg_replace('#<link\b[^>]*href="[^"]*/fonts/filament/[^"]*"[^>]*>#', '', $nativeStyles);
                $snapshot = preg_replace('/wire:(?:snapshot|effects)="[^"]*"/', '', $html);
                file_put_contents($directory.'/'.$mode.'.html', '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>اختبار واجهة الوثائق</title>'.$nativeStyles.'<style>'.$css.'</style></head><body class="fi-body"><main class="fi-page">'.$snapshot.'</main></body></html>');
            }
        }
        $this->assertSame([], config('authorization.capabilities'));
    }

    public function test_missing_policy_or_synthetic_grant_never_becomes_an_implicit_allow(): void
    {
        [$member, $document] = $this->seedPresentation();
        DocumentPresentationHarness::$syntheticGrant = false;
        Livewire::actingAs($member->user)->test(DocumentPresentationHarness::class, ['document' => $document['document_public_id']])
            ->assertOk()->assertSee('الوصول غير مسموح')->assertDontSee('بيانات اختبار عربية');
        $this->assertDatabaseCount('documents', 1);
    }

    public function test_native_search_sort_pagination_and_version_empty_states_use_read_services(): void
    {
        [$member, $document] = $this->seedPresentation();
        Livewire::test(DocumentPresentationHarness::class)->assertSee('بيانات اختبار')
            ->set('tableSearch', 'not present')->assertSee('لا توجد وثائق');
        $table = Livewire::test(DocumentPresentationHarness::class)->instance()->getTable();
        $this->assertSame([], $table->getRecordActions());
        $this->assertSame([], $table->getToolbarActions());
        $this->assertSame([], $table->getHeaderActions());
        DocumentPresentationHarness::$member = OrganizationMembership::factory()->create();
        Livewire::test(DocumentPresentationHarness::class)->assertSee('لا توجد وثائق')->assertDontSee('بيانات اختبار عربية');
        Livewire::test(DocumentPresentationHarness::class, ['mode' => 'versions'])->assertSee('لا توجد إصدارات');
        DocumentPresentationHarness::$failRead = true;
        Livewire::test(DocumentPresentationHarness::class)->assertSee('تعذّر تحميل بيانات الاختبار')->assertDontSee('PRIVATE');
    }

    public function test_raw_read_arrays_are_minimized_before_filament_receives_records(): void
    {
        $records = new LengthAwarePaginator([['document_public_id' => strtolower((string) Str::ulid()),
            'title' => 'safe', 'storage_key' => 'PRIVATE-PATH', 'sha256' => 'PRIVATE-HASH', 'original_filename' => 'PRIVATE-NAME']], 1, 20);
        $safe = DocumentTableBlueprint::project($records, 'document_public_id', ['document_public_id', 'title']);
        $this->assertSame(['document_public_id', 'title'], array_keys($safe->items()[array_key_first($safe->items())]));
        $this->assertStringNotContainsString('PRIVATE', json_encode($safe->items()));
        $this->assertArrayHasKey('storage_key', $records->items()[0], 'Projection must not mutate its original read result.');
    }

    public function test_no_document_resource_routes_navigation_or_upload_mutation_are_registered(): void
    {
        [$member, $document] = $this->seedPresentation();
        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsString('documents', $route->uri());
        }
        $panel = (new AdminPanelProvider($this->app))->panel(Panel::make());
        foreach ($panel->getResources() as $resource) {
            $this->assertStringNotContainsString('Document', $resource);
        }
        $before = DocumentVersion::sole()->fresh()->getAttributes();
        Livewire::test(DocumentPresentationHarness::class, ['mode' => 'form'])
            ->set('data.title', 'forged')->call('mountAction', 'uploadDisabled')->assertSet('mountedActions', []);
        $this->assertSame($before, DocumentVersion::sole()->getAttributes());
        Livewire::test(DocumentPresentationHarness::class)->call('_startUpload', 'data.file', [], false)->assertForbidden();
        $this->assertDatabaseCount('document_versions', 1);
        $this->actingAs($member->user)->get('/admin/documents')->assertNotFound();
        $this->get('/documents/upload')->assertNotFound();
    }
}

/** Test-only native Filament harness. No route, panel, provider or production component registration. */
final class DocumentPresentationHarness extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions, InteractsWithSchemas, InteractsWithTable;

    public static ?OrganizationMembership $member = null;

    public static bool $syntheticGrant = false;

    public static bool $failRead = false;

    #[Locked]
    public string $mode = 'list';

    #[Locked]
    public ?string $document = null;

    public array $data = [];

    public function mount(string $mode = 'list', ?string $document = null): void
    {
        abort_unless(app()->environment('testing') && in_array($mode, ['list', 'details', 'versions', 'form'], true), 404);
        $this->mode = $mode;
        $this->document = $document;
    }

    public function table(Table $table): Table
    {
        if ($this->mode === 'versions') {
            return DocumentVersionsBlueprint::configure($table, fn (int $page, int $perPage) => $this->read(function ($actor) use ($page, $perPage) {
                return $this->document === null ? new LengthAwarePaginator([], 0, $perPage, $page)
                    : app(ReadDocumentHistory::class)->execute($actor, $this->document, page: $page, perPage: $perPage);
            }));
        }

        return DocumentTableBlueprint::configure($table, fn ($search, $sort, $direction, $page, $perPage) => $this->read(fn ($actor) => app(ListDocuments::class)->execute($actor, $search, $sort, $direction, $page, $perPage)));
    }

    public function details(Schema $schema): Schema
    {
        $metadata = $this->document !== null && self::$syntheticGrant
            ? $this->read(fn ($actor) => app(ListDocuments::class)->show($actor, $this->document)) : [];

        return DocumentDetailsBlueprint::configure($schema, $metadata);
    }

    public function form(Schema $schema): Schema
    {
        return DocumentFormBlueprint::configure($schema)->statePath('data');
    }

    /** Native Schemas include an upload trait; explicitly deny it in this test harness too. */
    public function _startUpload($name, $fileInfo, $isMultiple): void
    {
        abort(403);
    }

    private function read(callable $operation): mixed
    {
        abort_unless(self::$syntheticGrant && self::$member !== null, 403);
        if (self::$failRead) {
            throw new RuntimeException('Synthetic read fault.');
        }

        return app(TenantContext::class)->run(self::$member->user, self::$member->organization_id,
            fn () => $operation(self::$member->user));
    }

    public function render()
    {
        return view('filament.documents.document-shell', ['mode' => $this->mode, 'synthetic' => true,
            'forbidden' => ! self::$syntheticGrant, 'error' => self::$failRead]);
    }
}
