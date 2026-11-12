<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\ManageDocumentFileOperations;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\DocumentWorkspaceFixtures;
use Tests\TestCase;

class ClosureMigrationSafetyTest extends TestCase
{
    use DatabaseMigrations, DocumentWorkspaceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareDocumentWorkspace();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->app) {
                foreach ($this->documentWorkspaceFixtures as $f) {
                    foreach (['document_file_operation_changes', 'document_file_operation_assignments'] as $table) {
                        DB::table($table)->where('organization_id', $f['organization']->id)->where('fixture_id', $f['proof']['fixture_id'])->delete();
                    }
                }
                $this->cleanupDocumentWorkspace();
            }
        } finally {
            parent::tearDown();
        }
    }

    public static function invalidJournals(): array
    {
        return [['duplicate'], ['unknown'], ['missing']];
    }

    #[DataProvider('invalidJournals')]
    public function test_invalid_closure_journal_is_refused_before_any_reverse_ddl(string $kind): void
    {
        $journal = DB::table('migrations')->orderBy('id')->get();
        $entry = $journal->firstWhere('migration', '2026_11_14_000002_create_document_file_operation_assignments_table');
        $extra = null;
        if ($kind === 'missing') {
            DB::table('migrations')->where('id', $entry->id)->delete();
        } else {
            $extra = DB::table('migrations')->insertGetId(['migration' => $kind === 'duplicate' ? $entry->migration : '2026_11_14_000007_unknown_closure_object', 'batch' => $entry->batch]);
        }
        $before = Schema::getTableListing();
        $attempted = false;
        $armed = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$attempted, &$armed): void {
            if ($armed && preg_match('/\Adrop\s+table\b/i', $sql)) {
                $attempted = true;
                throw new RuntimeException('Test intercepted an unsafe DROP before executing it.');
            }
        });
        try {
            try {
                (require database_path('migrations/2026_11_14_000005_create_maturity_reassessment_operation_changes_table.php'))->down();
                $this->fail('Unknown/duplicate closure state cannot be reversed.');
            } catch (RuntimeException) {
                $this->assertFalse($attempted, 'The migration must reject the invalid journal before requesting any DROP.');
                $this->assertSame($before, Schema::getTableListing());
            }
        } finally {
            $armed = false;
            $kind === 'missing' ? DB::table('migrations')->insert((array) $entry) : DB::table('migrations')->where('id', $extra)->delete();
            $this->assertEquals($journal->all(), DB::table('migrations')->orderBy('id')->get()->all());
        }
    }

    public function test_populated_operation_history_refuses_every_reverse_step_before_any_drop(): void
    {
        $f = $this->documentFixture();
        $f['proof']['file_operation_scope'] = 'CLOSURE_FILES_LOCAL_001';
        $this->inDocumentFixture($f, fn () => app(ManageDocumentFileOperations::class)->issue($f['issuer'], $f['editor'], 'upload_real', (string) Str::ulid(), (string) Str::ulid()));
        $journal = DB::table('migrations')->orderBy('id')->get()->toJson();
        $tables = Schema::getTableListing();
        $files = glob(database_path('migrations/2026_11_14_*.php'));
        $this->assertCount(6, $files);
        foreach ($files as $file) {
            try {
                (require $file)->down();
                $this->fail('No closure table or parent identity key may be dropped while authorization history exists.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('preserved', $error->getMessage());
                $this->assertSame($tables, Schema::getTableListing());
                $this->assertSame($journal, DB::table('migrations')->orderBy('id')->get()->toJson());
            }
        }
    }

    public function test_unknown_external_foreign_key_dependency_is_preserved_before_reverse_ddl(): void
    {
        $this->assertFalse(Schema::hasTable('closure_external_probe'));
        Schema::create('closure_external_probe', function ($table): void {
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('assignment_id');
            $table->foreign(['organization_id', 'assignment_id'])->references(['organization_id', 'id'])->on('document_file_operation_assignments')->restrictOnDelete()->restrictOnUpdate();
        });
        $tables = Schema::getTableListing();
        try {
            try {
                (require database_path('migrations/2026_11_14_000005_create_maturity_reassessment_operation_changes_table.php'))->down();
                $this->fail('A new external dependency must require explicit reviewed recovery.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('External closure dependency', $error->getMessage());
                $this->assertSame($tables, Schema::getTableListing());
            }
        } finally {
            $this->assertFalse(DB::table('closure_external_probe')->exists());
            Schema::drop('closure_external_probe');
        }
    }
}
