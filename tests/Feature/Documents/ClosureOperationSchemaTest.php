<?php

namespace Tests\Feature\Documents;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClosureOperationSchemaTest extends TestCase
{
    use DatabaseMigrations;

    public function test_real_file_and_reassessment_grants_have_separate_persistent_scope_and_audit(): void
    {
        foreach (['document_file_operation_assignments', 'document_file_operation_changes',
            'maturity_reassessment_operation_assignments', 'maturity_reassessment_operation_changes'] as $table) {
            $this->assertTrue(Schema::hasTable($table), 'Missing explicit finite operation backing: '.$table);
            $this->assertSame(0, DB::table($table)->count());
        }
        $this->assertSame([], (require config_path('authorization.php'))['capabilities']);
    }
}
