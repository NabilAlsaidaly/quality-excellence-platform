<?php

use App\Support\ClosureMigrationGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function assertTarget(): void
    {
        ClosureMigrationGuards::assertTarget();
    }

    public function up(): void
    {
        $this->assertTarget();
        foreach (['documents' => 'documents_closure_public_scope', 'document_versions' => 'versions_closure_public_scope'] as $table => $index) {
            if (Schema::hasIndex($table, $index)) {
                throw new RuntimeException('Existing closure identity key; reviewed recovery required.');
            }
        }
        $create = function (): void {
            Schema::table('documents', fn ($table) => $table->unique(['organization_id', 'id', 'public_id'], 'documents_closure_public_scope'));
            try {
                Schema::table('document_versions', fn ($table) => $table->unique(['organization_id', 'document_id', 'id', 'public_id'], 'versions_closure_public_scope'));
            } catch (Throwable $error) {
                Schema::table('documents', fn ($table) => $table->dropUnique('documents_closure_public_scope'));
                throw $error;
            }
        };
        DB::getDriverName() === 'sqlite' ? DB::transaction($create) : $create();
    }

    public function assertRollbackSafe(): void
    {
        $this->assertTarget();
        $inventory = [
            '2026_11_14_000001_add_document_public_scope_identity' => null,
            '2026_11_14_000002_create_document_file_operation_assignments_table' => 'document_file_operation_assignments',
            '2026_11_14_000003_create_document_file_operation_changes_table' => 'document_file_operation_changes',
            '2026_11_14_000004_create_maturity_reassessment_operation_assignments_table' => 'maturity_reassessment_operation_assignments',
            '2026_11_14_000005_create_maturity_reassessment_operation_changes_table' => 'maturity_reassessment_operation_changes',
            '2026_11_14_000006_create_document_demo_availability_decisions_table' => 'document_demo_availability_decisions',
        ];
        $journal = DB::table('migrations')->orderBy('id')->get()->filter(fn ($row) => str_starts_with($row->migration, '2026_11_14_'))->values();
        $names = $journal->pluck('migration')->all();
        if ($names !== array_slice(array_keys($inventory), 0, count($names))
            || $journal->contains(fn ($row) => $row->id < 1 || $row->batch < 1)
            || $journal->pluck('batch')->values()->all() !== $journal->pluck('batch')->sort()->values()->all()) {
            throw new RuntimeException('Unknown, partial, duplicate or inconsistent closure journal; no reverse DDL.');
        }
        $keys = [
            ['documents', 'documents_closure_public_scope', array_key_first($inventory)],
            ['document_versions', 'versions_closure_public_scope', array_key_first($inventory)],
            ['document_intakes', 'intake_demo_exact_scope', array_key_last($inventory)],
            ['document_file_operation_assignments', 'df_grant_demo_authority', array_key_last($inventory)],
        ];
        foreach ($keys as [$table, $key, $owner]) {
            if (Schema::hasIndex($table, $key) !== in_array($owner, $names, true)) {
                throw new RuntimeException('Closure key/journal mismatch; reviewed recovery required.');
            }
        }
        foreach ($inventory as $migration => $table) {
            if ($table === null) {
                continue;
            }
            $journaled = in_array($migration, $names, true);
            if (Schema::hasTable($table) !== $journaled) {
                throw new RuntimeException('Partial or unknown closure migration state; no reverse DDL.');
            }
            if ($journaled && DB::table($table)->exists()) {
                throw new RuntimeException('Closure authorization/audit history preserved before any DROP.');
            }
        }
        $ownedTables = array_values(array_filter($inventory));
        $protected = array_merge($ownedTables, ['documents', 'document_versions']);
        if (DB::getDriverName() === 'mysql') {
            $references = DB::select('SELECT TABLE_NAME AS source, REFERENCED_TABLE_NAME AS target,
                GROUP_CONCAT(REFERENCED_COLUMN_NAME ORDER BY ORDINAL_POSITION) AS referenced_columns
                FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_SCHEMA=DATABASE()
                AND REFERENCED_TABLE_NAME IN ('.implode(',', array_fill(0, count($protected), '?')).')
                GROUP BY TABLE_NAME,CONSTRAINT_NAME,REFERENCED_TABLE_NAME', $protected);
        } else {
            $references = [];
            foreach (Schema::getTableListing(schemaQualified: false) as $source) {
                foreach (Schema::getForeignKeys($source) as $foreign) {
                    $references[] = (object) ['source' => $source, 'target' => $foreign['foreign_table'], 'referenced_columns' => implode(',', $foreign['foreign_columns'])];
                }
            }
        }
        foreach ($references as $reference) {
            $depends = in_array($reference->target, $ownedTables, true)
                || ($reference->target === 'documents' && $reference->referenced_columns === 'organization_id,id,public_id')
                || ($reference->target === 'document_versions' && $reference->referenced_columns === 'organization_id,document_id,id,public_id');
            if ($depends && ! in_array($reference->source, $ownedTables, true)) {
                throw new RuntimeException('External closure dependency preserved before any reverse DDL.');
            }
        }
    }

    public function down(): void
    {
        $this->assertRollbackSafe();
        Schema::table('document_versions', fn ($table) => $table->dropUnique('versions_closure_public_scope'));
        Schema::table('documents', fn ($table) => $table->dropUnique('documents_closure_public_scope'));
    }
};
