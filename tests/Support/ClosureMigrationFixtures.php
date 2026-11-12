<?php

namespace Tests\Support;

use App\Support\ClosureMigrationGuards;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Isolate legacy migration failure scenarios from the newer EMPTY closure dependencies. */
trait ClosureMigrationFixtures
{
    protected function rollbackEmptyClosureFixtures(): void
    {
        ClosureMigrationGuards::assertTarget();
        $files = [
            '2026_11_14_000001_add_document_public_scope_identity.php',
            '2026_11_14_000002_create_document_file_operation_assignments_table.php',
            '2026_11_14_000003_create_document_file_operation_changes_table.php',
            '2026_11_14_000004_create_maturity_reassessment_operation_assignments_table.php',
            '2026_11_14_000005_create_maturity_reassessment_operation_changes_table.php',
            '2026_11_14_000006_create_document_demo_availability_decisions_table.php',
        ];
        $tables = ['document_file_operation_assignments', 'document_file_operation_changes',
            'maturity_reassessment_operation_assignments', 'maturity_reassessment_operation_changes', 'document_demo_availability_decisions'];
        $names = array_map(fn ($file) => pathinfo($file, PATHINFO_FILENAME), $files);
        $journal = DB::table('migrations')->orderBy('id')->get();
        $actual = $journal->filter(fn ($row) => str_starts_with($row->migration, '2026_11_14_'))->pluck('migration')->sort()->values()->all();
        // Complete-or-absent only. Absence also requires all owned objects to be absent.
        if ($actual === []) {
            if (collect($tables)->contains(fn ($table) => Schema::hasTable($table))
                || Schema::hasIndex('documents', 'documents_closure_public_scope')
                || Schema::hasIndex('document_versions', 'versions_closure_public_scope')
                || Schema::hasIndex('document_intakes', 'intake_demo_exact_scope')) {
                throw new RuntimeException('Unjournaled closure objects; reviewed recovery required.');
            }

            return;
        }
        $expected = $names;
        sort($expected);
        if ($actual !== $expected || collect($files)->contains(fn ($file) => ! is_file(database_path('migrations/'.$file)))) {
            throw new RuntimeException('Unknown or partial closure fixture journal; refuse rollback.');
        }
        foreach ($tables as $table) {
            if (! Schema::hasTable($table) || DB::table($table)->exists()) {
                throw new RuntimeException('Only the exact EMPTY closure fixture is reversible.');
            }
        }
        ClosureMigrationGuards::assertRollbackSafe();
        $beforeTables = Schema::getTableListing(schemaQualified: false);
        $this->artisan('migrate:rollback', ['--path' => array_map(fn ($file) => 'database/migrations/'.$file, $files),
            '--step' => $journal->count(), '--force' => true])->assertExitCode(0);
        $this->assertEquals($journal->reject(fn ($row) => in_array($row->migration, $names, true))->values()->all(),
            DB::table('migrations')->orderBy('id')->get()->all(), 'The remaining journal must be byte-for-byte-equivalent row values.');
        $afterTables = Schema::getTableListing(schemaQualified: false);
        $expectedTables = array_values(array_diff($beforeTables, $tables));
        sort($afterTables);
        sort($expectedTables);
        $this->assertSame($expectedTables, $afterTables);
        $this->assertFalse(Schema::hasIndex('documents', 'documents_closure_public_scope'));
        $this->assertFalse(Schema::hasIndex('document_versions', 'versions_closure_public_scope'));
        $this->assertFalse(Schema::hasIndex('document_intakes', 'intake_demo_exact_scope'));
    }
}
